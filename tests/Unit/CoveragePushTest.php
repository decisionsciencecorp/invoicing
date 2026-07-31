<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CoveragePushTest extends TestCase
{
    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
        set_config('square_location_id', 'LOC_TEST');
        putenv('SQUARE_LOCATION_ID=LOC_TEST');
        square_config_reset();
    }

    public function testDuplicatePublishAndProductionSquareBase(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 5000, 9000);
        $p1 = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-12', null, 'tier1');
        $this->assertTrue($p1['ok'], $p1['error'] ?? '');
        $p2 = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-12', null, 'tier1');
        $this->assertFalse($p2['ok']);

        putenv('SQUARE_ENVIRONMENT=production');
        square_config_reset();
        $cfg = dsc_invoicing_square_config();
        $this->assertStringContainsString('squareup.com', $cfg['base_url']);
        putenv('SQUARE_ENVIRONMENT=sandbox');
        square_config_reset();

        $this->assertFalse(dsc_invoicing_admin_create_user('x', 'short')['success']);
        $this->assertSame('', dsc_markdown_to_html(''));
        $this->assertSame('', dsc_markdown_normalize_storage(''));

        $cfgW = dsc_invoicing_square_webhook_notification_config();
        $raw = '{not-json';
        $sig = dsc_invoicing_square_webhook_compute_expected_signature($cfgW['url'], $raw, $cfgW['key']);
        $badJson = dsc_invoicing_square_webhook_run($db, $raw, $sig);
        $this->assertSame(400, $badJson['code']);

        $payNoId = json_encode(['type' => 'invoice.payment_made', 'data' => []], JSON_THROW_ON_ERROR);
        $sig2 = dsc_invoicing_square_webhook_compute_expected_signature($cfgW['url'], $payNoId, $cfgW['key']);
        $ign = dsc_invoicing_square_webhook_run($db, $payNoId, $sig2);
        $this->assertSame('no_invoice_id', $ign['payload']['ignored'] ?? null);

        $unknown = json_encode([
            'type' => 'invoice.payment_made',
            'data' => ['object' => ['invoice' => ['id' => 'inv:DOES_NOT_EXIST']]],
        ], JSON_THROW_ON_ERROR);
        $sig3 = dsc_invoicing_square_webhook_compute_expected_signature($cfgW['url'], $unknown, $cfgW['key']);
        $unk = dsc_invoicing_square_webhook_run($db, $unknown, $sig3);
        $this->assertSame('unknown_invoice', $unk['payload']['ignored'] ?? null);

        $foundId = dsc_invoicing_square_webhook_find_invoice_id(['id' => 'inv:from-id-key']);
        $this->assertSame('inv:from-id-key', $foundId);

        // Force rate-limit 429 on list companies
        $key = createApiKey('rl429');
        $rk = 'inv_api:list_companies:' . $key . ':9.9.9.9';
        $db->exec("INSERT INTO api_rate_limits (rate_key, window_start, count) VALUES ('$rk', " . time() . ', 999)');
        $limited = runInvoicingApiListCompanies($key, '9.9.9.9');
        $this->assertSame(429, $limited['code']);

        // Publish mock failure mid-flight
        $seed3 = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 1111, 2222);
        $GLOBALS['_dsc_square_mock_handler'] = static function (string $method, string $path, ?array $body): array {
            if (str_contains($path, '/publish') || $path === '/invoices') {
                return ['ok' => false, 'error' => 'forced'];
            }
            return invoicing_test_default_square_mock($method, $path, $body);
        };
        $fail = dsc_billing_publish_combined_invoice($db, $seed3['engagement_id'], '2026-05', null, 'tier1');
        $this->assertFalse($fail['ok']);
        invoicing_test_install_mocks();

        $this->assertNull(dsc_billing_prev_month('bad'));
        $this->assertSame('2024-02-29', dsc_billing_month_last_day_iso('2024-02'));
        $this->assertSame('failed', dsc_billing_square_map_invoice_status('FAILED'));
        $this->assertSame('published', dsc_billing_square_map_invoice_status('VIEWED'));

        $emptyNameKey = createApiKey('  ');
        $this->assertNotSame('', $emptyNameKey);

        // Publish path: GET after publish missing public_url
        $GLOBALS['_dsc_square_mock_handler'] = static function (string $method, string $path, ?array $body): array {
            if ($method === 'POST' && str_contains($path, '/publish')) {
                $id = 'inv:NOPUB';
                if (preg_match('#^/invoices/([^/]+)/publish$#', $path, $m)) {
                    $id = rawurldecode($m[1]);
                }
                return [
                    'ok' => true,
                    'data' => ['invoice' => ['id' => $id, 'version' => 1, 'status' => 'UNPAID']],
                ];
            }
            return invoicing_test_default_square_mock($method, $path, $body);
        };
        $seed4 = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 3333, 4444);
        $pub4 = dsc_billing_publish_combined_invoice($db, $seed4['engagement_id'], '2026-04', null, 'tier1');
        $this->assertTrue($pub4['ok'], $pub4['error'] ?? '');
        invoicing_test_install_mocks();

        // Hourly overage publish failure
        $seed5 = invoicing_test_seed_company_engagement($db, 5, 10000);
        $eid5 = $seed5['engagement_id'];
        $db->exec(
            "INSERT INTO time_entries (engagement_id, worked_date, hours, billing_period_month) "
            . "VALUES ($eid5, '2026-03-01', 10, '2026-03')"
        );
        $n = 0;
        $GLOBALS['_dsc_square_mock_handler'] = static function (string $method, string $path, ?array $body) use (&$n): array {
            $n++;
            if ($method === 'POST' && $path === '/invoices' && $n > 3) {
                return ['ok' => false, 'error' => 'overage fail'];
            }
            return invoicing_test_default_square_mock($method, $path, $body);
        };
        $ovFail = dsc_billing_publish_combined_invoice($db, $eid5, '2026-04', 88);
        $this->assertFalse($ovFail['ok']);
        invoicing_test_install_mocks();
    }

    public function testApiUnauthorizedSweepAndSafeReturn(): void
    {
        $bad = null;
        $ip = '203.0.113.9';
        $calls = [
            runInvoicingApiGetCompany($bad, $ip, 1),
            runInvoicingApiCreateCompany(['name' => 'x'], $bad, $ip),
            runInvoicingApiUpdateCompany(['id' => 1, 'name' => 'x'], $bad, $ip),
            runInvoicingApiDeleteCompany(['id' => 1], $bad, $ip),
            runInvoicingApiGetEngagement($bad, $ip, 1),
            runInvoicingApiCreateEngagement(['company_id' => 1, 'name' => 'e'], $bad, $ip),
            runInvoicingApiUpdateEngagement(['id' => 1, 'name' => 'e'], $bad, $ip),
            runInvoicingApiDeleteEngagement(['id' => 1], $bad, $ip),
            runInvoicingApiGetTimeEntry($bad, $ip, 1),
            runInvoicingApiCreateTimeEntry(['engagement_id' => 1, 'worked_date' => '2026-01-01', 'hours' => 1], $bad, $ip),
            runInvoicingApiUpdateTimeEntry(['id' => 1, 'hours' => 2], $bad, $ip),
            runInvoicingApiDeleteTimeEntry(['id' => 1], $bad, $ip),
            runInvoicingApiGetOutboundInvoice($bad, $ip, 1),
            runInvoicingApiPublishCombinedInvoice(['engagement_id' => 1, 'anchor_month' => '2026-01'], $bad, $ip),
            runInvoicingApiRefreshOutboundInvoice($bad, $ip, ['id' => 1]),
            runInvoicingApiAttachTasksDocument($bad, $ip, ['id' => 1, 'tasks_document_id' => 1]),
            runInvoicingApiCancelOutboundInvoice($bad, $ip, ['id' => 1]),
            runInvoicingApiListUnpaidAging($bad, $ip),
            runInvoicingApiListAuditLog($bad, $ip, 10, 0),
            runInvoicingApiListConfig($bad, $ip),
            runInvoicingApiListApiKeysMeta($bad, $ip),
            runInvoicingApiListAdminUsers($bad, $ip),
        ];
        foreach ($calls as $r) {
            $this->assertSame(401, $r['code'] ?? 0, json_encode($r));
        }

        putenv('INVOICING_WEB_BASE=/invoicing');
        $safe = dsc_invoicing_safe_admin_return('/invoicing/admin/invoices.php?tab=list');
        $this->assertStringContainsString('invoices.php', $safe);
        putenv('INVOICING_WEB_BASE');

        $_SERVER['REQUEST_URI'] = '/admin/companies.php';
        $this->assertSame('/admin/companies.php', dsc_invoicing_current_request_return());
        $_SERVER['REQUEST_URI'] = '/admin/login.php';
        $this->assertSame('', dsc_invoicing_current_request_return());
        unset($_SERVER['REQUEST_URI']);

        initializeDatabase();
        $this->assertSame('5', (string) get_config('schema_version'));
    }
}
