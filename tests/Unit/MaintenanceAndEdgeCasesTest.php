<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MaintenanceAndEdgeCasesTest extends TestCase
{
    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();
    }

    public function testBackfillRepairAndLegacyHydrateBranches(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 5, 10000);
        $eid = $seed['engagement_id'];
        $token = '';
        $db->exec(
            "INSERT INTO outbound_invoices (
                engagement_id, anchor_month, overage_month, retainer_amount_cents, overage_amount_cents,
                total_amount_cents, square_invoice_id, public_url, payment_status
             ) VALUES (
                $eid, '2026-01-R', '2025-12', 50000, 0, 50000, 'inv:LEGACY_R',
                'https://squareup.com/pay/legacy', 'published'
             )"
        );
        $oid = (int) $db->lastInsertRowID();
        dsc_billing_hydrate_legacy_outbound_row($db, $oid);
        $row = $db->querySingle('SELECT public_token, retainer_public_url, square_retainer_invoice_id FROM outbound_invoices WHERE id=' . $oid, true);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $row['public_token']);
        $this->assertStringContainsString('squareup', (string) $row['retainer_public_url']);
        $this->assertSame('inv:LEGACY_R', $row['square_retainer_invoice_id']);
        $token = (string) $row['public_token'];

        $db->exec(
            "UPDATE outbound_invoices SET public_url = 'http://127.0.0.1:8080/invoice.php?t=$token' WHERE id = $oid"
        );
        $fixed = dsc_billing_repair_localhost_client_urls($db);
        $this->assertGreaterThanOrEqual(1, $fixed);

        $bf = dsc_billing_backfill_psf_invoice_documents($db);
        $this->assertTrue($bf['ok']);
        $this->assertGreaterThanOrEqual(1, $bf['updated']);
    }

    public function testDeleteCompanyAndApiKeyHelpers(): void
    {
        $key = createApiKey('edge');
        $keys = getAllApiKeys();
        $this->assertNotEmpty($keys);
        $id = (int) getDbConnection()->querySingle('SELECT id FROM api_keys ORDER BY id DESC LIMIT 1');
        deleteApiKey($id);

        $ip = '127.0.0.9';
        $c = runInvoicingApiCreateCompany(['name' => 'DelMe ' . uniqid()], $key, $ip);
        // key was deleted — create a new one
        $key2 = createApiKey('edge2');
        $c = runInvoicingApiCreateCompany(['name' => 'DelMe ' . uniqid(), 'billing_email' => 'd@e.co'], $key2, $ip);
        $this->assertTrue($c['success']);
        $cid = (int) $c['data']['company_id'];
        $del = runInvoicingApiDeleteCompany(['company_id' => $cid], $key2, $ip);
        $this->assertTrue($del['success']);

        $this->assertSame('Jan 2, 2026', dsc_invoicing_format_date('2026-01-02'));
        $this->assertSame('', dsc_invoicing_format_date(null));
        $this->assertSame(4, dsc_tasks_psf_project_id());
        $url = dsc_tasks_admin_document_url(12);
        $this->assertStringContainsString('doc.php?id=12', $url);

        $tmp = sys_get_temp_dir() . '/sq_env_' . uniqid() . '.env';
        file_put_contents($tmp, "# comment\nSQUARE_ACCESS_TOKEN=abc\nEMPTY\nFOO=bar\n");
        $vars = square_load_env_file($tmp);
        $this->assertSame('abc', $vars['SQUARE_ACCESS_TOKEN']);
        @unlink($tmp);
        $this->assertSame([], square_load_env_file('/no/such/file.env'));
    }

    public function testEnsureCustomerCreatesViaMock(): void
    {
        $db = getDbConnection();
        $db->exec("INSERT INTO companies (name, billing_email) VALUES ('NewCust " . uniqid() . "', 'n@c.com')");
        $cid = (int) $db->lastInsertRowID();
        $r = dsc_invoicing_square_ensure_company_customer($db, $cid);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertStringStartsWith('CUST_MOCK_', (string) ($r['customer_id'] ?? ''));

        $badEmail = getDbConnection();
        $badEmail->exec("INSERT INTO companies (name, billing_email) VALUES ('Bad " . uniqid() . "', 'not-an-email')");
        $bid = (int) $badEmail->lastInsertRowID();
        $fail = dsc_invoicing_square_ensure_company_customer($badEmail, $bid);
        $this->assertFalse($fail['ok']);
    }

    public function testPublishTier2AndGetOutboundToken(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 212500, 510000);
        $pub = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-11', null, 'tier2');
        $this->assertTrue($pub['ok'], $pub['error'] ?? '');
        $token = (string) $db->querySingle(
            'SELECT public_token FROM outbound_invoices WHERE id=' . (int) $pub['outbound_id']
        );
        $got = dsc_billing_get_outbound_by_public_token($db, $token);
        $this->assertNotNull($got);
        $this->assertSame('tier2', $got['tier_key']);
        $this->assertSame(510000, (int) $got['retainer_amount_cents']);
    }

    public function testAggregatePartialAndStoppage(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 5, 10000);
        $agg = dsc_billing_aggregate_payment_status('paid', 'published', true);
        $this->assertSame('partial', $agg['payment_status']);
        dsc_billing_sync_engagement_stoppage($db, $seed['engagement_id'], 'partial');
        $ws = (int) $db->querySingle('SELECT work_stoppage FROM engagements WHERE id=' . $seed['engagement_id']);
        $this->assertSame(1, $ws);
        dsc_billing_sync_engagement_stoppage($db, $seed['engagement_id'], 'paid');
        $ws2 = (int) $db->querySingle('SELECT work_stoppage FROM engagements WHERE id=' . $seed['engagement_id']);
        $this->assertSame(0, $ws2);
    }
}
