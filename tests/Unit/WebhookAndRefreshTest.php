<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WebhookAndRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();
    }

    public function testWebhookSignatureAndPaymentMade(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 212500, 510000);
        $pub = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-08', null, 'tier1');
        $this->assertTrue($pub['ok'], $pub['error'] ?? '');
        $outboundId = (int) $pub['outbound_id'];
        $invId = (string) $db->querySingle(
            'SELECT square_retainer_invoice_id FROM outbound_invoices WHERE id = ' . $outboundId
        );
        $this->assertStringStartsWith('inv:', $invId);

        $cfg = dsc_invoicing_square_webhook_notification_config();
        $this->assertTrue(dsc_invoicing_square_webhook_is_configured());
        $body = json_encode([
            'type' => 'invoice.payment_made',
            'data' => ['object' => ['invoice' => ['id' => $invId]]],
        ], JSON_THROW_ON_ERROR);
        $sig = dsc_invoicing_square_webhook_compute_expected_signature($cfg['url'], $body, $cfg['key']);
        $this->assertTrue(dsc_invoicing_square_webhook_verify_signature($body, $sig));
        $this->assertFalse(dsc_invoicing_square_webhook_verify_signature($body, 'bad'));

        $GLOBALS['_dsc_square_mock_paid'] = true;
        $run = dsc_invoicing_square_webhook_run($db, $body, $sig);
        $this->assertSame(200, $run['code']);
        $this->assertTrue($run['payload']['success']);
        $this->assertSame($outboundId, $run['payload']['outbound_id']);

        $badSig = dsc_invoicing_square_webhook_run($db, $body, 'nope');
        $this->assertSame(401, $badSig['code']);

        $ignore = dsc_invoicing_square_webhook_run(
            $db,
            json_encode(['type' => 'other.event'], JSON_THROW_ON_ERROR),
            dsc_invoicing_square_webhook_compute_expected_signature(
                $cfg['url'],
                json_encode(['type' => 'other.event'], JSON_THROW_ON_ERROR),
                $cfg['key']
            )
        );
        $this->assertSame('event_type', $ignore['payload']['ignored'] ?? null);

        $found = dsc_invoicing_square_webhook_find_invoice_id(['nested' => ['invoice_id' => 'inv:abc']]);
        $this->assertSame('inv:abc', $found);
        $this->assertNull(dsc_invoicing_square_webhook_find_invoice_id(['x' => 1]));
    }

    public function testAttachTasksDocumentAndHydrateLegacy(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 10000, 20000);
        $pub = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-09', null, 'tier1');
        $this->assertTrue($pub['ok'], $pub['error'] ?? '');
        $oid = (int) $pub['outbound_id'];

        $att = dsc_billing_attach_tasks_document_to_outbound($db, $oid, 55);
        $this->assertTrue($att['ok'], $att['error'] ?? '');
        $title = $db->querySingle('SELECT tasks_document_title FROM outbound_invoices WHERE id = ' . $oid);
        $this->assertSame('Accounting 55', $title);

        dsc_billing_hydrate_legacy_outbound_row($db, $oid);
        $token = (string) $db->querySingle('SELECT public_token FROM outbound_invoices WHERE id = ' . $oid);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);

        $client = dsc_billing_client_page_url([
            'public_token' => $token,
            'public_url' => '',
        ]);
        $this->assertStringContainsString('invoice.php?t=', $client);
    }

    public function testEnsureCustomerUsesExistingId(): void
    {
        $db = getDbConnection();
        $db->exec("INSERT INTO companies (name, billing_email, square_customer_id) VALUES ('X','x@y.com','CUST_EXIST')");
        $cid = (int) $db->lastInsertRowID();
        $r = dsc_invoicing_square_ensure_company_customer($db, $cid);
        $this->assertTrue($r['ok']);
        $this->assertSame('CUST_EXIST', $r['customer_id']);
    }

    public function testTasksMockFailurePath(): void
    {
        $bad = dsc_tasks_fetch_document(999001);
        $this->assertFalse($bad['ok']);
        $this->assertFalse(dsc_tasks_fetch_document(0)['ok']);
        $this->assertTrue(dsc_tasks_api_is_configured());
    }
}
