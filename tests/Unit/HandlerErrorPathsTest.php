<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HandlerErrorPathsTest extends TestCase
{
    private string $key = '';

    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
        $this->key = createApiKey('errors');
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();
    }

    public function testValidationAndNotFoundBranches(): void
    {
        $ip = '127.0.0.55';
        $k = $this->key;

        $this->assertSame(400, runInvoicingApiCreateCompany(['name' => ''], $k, $ip)['code']);
        $this->assertSame(404, runInvoicingApiGetCompany($k, $ip, 99999)['code']);
        $this->assertSame(400, runInvoicingApiGetCompany($k, $ip, 0)['code']);
        $this->assertSame(400, runInvoicingApiUpdateCompany(['company_id' => 0], $k, $ip)['code']);
        $this->assertSame(404, runInvoicingApiUpdateCompany(['company_id' => 99999, 'name' => 'x'], $k, $ip)['code']);
        $this->assertSame(404, runInvoicingApiDeleteCompany(['company_id' => 99999], $k, $ip)['code']);

        $this->assertSame(400, runInvoicingApiCreateEngagement(['company_id' => 0, 'name' => ''], $k, $ip)['code']);
        $this->assertSame(404, runInvoicingApiCreateEngagement(['company_id' => 99999, 'name' => 'x'], $k, $ip)['code']);
        $this->assertSame(404, runInvoicingApiGetEngagement($k, $ip, 99999)['code']);
        $this->assertSame(400, runInvoicingApiGetEngagement($k, $ip, 0)['code']);
        $this->assertSame(400, runInvoicingApiUpdateEngagement(['engagement_id' => 1], $k, $ip)['code']);
        $this->assertSame(404, runInvoicingApiUpdateEngagement(['engagement_id' => 99999, 'name' => 'n'], $k, $ip)['code']);
        $this->assertSame(404, runInvoicingApiDeleteEngagement(['engagement_id' => 99999], $k, $ip)['code']);

        $this->assertSame(400, runInvoicingApiCreateTimeEntry(['engagement_id' => 0], $k, $ip)['code']);
        $this->assertSame(404, runInvoicingApiGetTimeEntry($k, $ip, 99999)['code']);
        $this->assertSame(404, runInvoicingApiUpdateTimeEntry(['time_entry_id' => 99999, 'hours' => 1], $k, $ip)['code']);
        $this->assertSame(400, runInvoicingApiUpdateTimeEntry(['time_entry_id' => 1], $k, $ip)['code']);
        $this->assertSame(404, runInvoicingApiDeleteTimeEntry(['time_entry_id' => 99999], $k, $ip)['code']);

        $this->assertSame(404, runInvoicingApiGetOutboundInvoice($k, $ip, 99999)['code']);
        $this->assertSame(400, runInvoicingApiPublishCombinedInvoice(['engagement_id' => 0], $k, $ip)['code']);

        $listAll = runInvoicingApiListEngagements($k, $ip, null);
        $this->assertTrue($listAll['success']);
        $listT = runInvoicingApiListTimeEntries($k, $ip, null, null, 10, 0);
        $this->assertTrue($listT['success']);
        $listO = runInvoicingApiListOutboundInvoices($k, $ip, null, 10, 0);
        $this->assertTrue($listO['success']);
    }

    public function testCsrfPostOk(): void
    {
        $_SESSION['csrf_token'] = 'goodtoken';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        requireCsrfToken();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'goodtoken';
        requireCsrfToken();
        $this->assertTrue(verifyCsrfToken('goodtoken'));
        unset($_SERVER['REQUEST_METHOD'], $_POST['csrf_token']);
    }

    public function testNewOpsApiHandlers(): void
    {
        $k = createApiKey('opsapi');
        $ip = '127.0.0.1';
        $this->assertSame(401, runInvoicingApiListUnpaidAging(null, $ip)['code']);
        $this->assertTrue(runInvoicingApiListUnpaidAging($k, $ip)['success']);
        $this->assertTrue(runInvoicingApiListAuditLog($k, $ip, 10, 0)['success']);
        $this->assertTrue(runInvoicingApiListConfig($k, $ip)['success']);
        $this->assertTrue(runInvoicingApiListApiKeysMeta($k, $ip)['success']);
        $this->assertTrue(runInvoicingApiListAdminUsers($k, $ip)['success']);

        $this->assertSame(400, runInvoicingApiRefreshOutboundInvoice($k, $ip, [])['code']);
        $this->assertSame(400, runInvoicingApiAttachTasksDocument($k, $ip, [])['code']);
        $this->assertSame(400, runInvoicingApiCancelOutboundInvoice($k, $ip, [])['code']);

        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 5000, 9000);
        $pub = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-03', null, 'tier1');
        $this->assertTrue($pub['ok'], $pub['error'] ?? '');
        $oid = (int) $pub['outbound_id'];

        $ref = runInvoicingApiRefreshOutboundInvoice($k, $ip, ['outbound_id' => $oid]);
        $this->assertTrue($ref['success'], $ref['error'] ?? '');

        $att = runInvoicingApiAttachTasksDocument($k, $ip, ['outbound_id' => $oid, 'tasks_document_id' => 77]);
        $this->assertTrue($att['success'], $att['error'] ?? '');

        unset($GLOBALS['_dsc_square_mock_canceled'], $GLOBALS['_dsc_square_mock_paid']);
        $can = runInvoicingApiCancelOutboundInvoice($k, $ip, ['outbound_id' => $oid]);
        $this->assertTrue($can['success'], $can['error'] ?? '');

        $upEng = runInvoicingApiUpdateEngagement([
            'engagement_id' => $seed['engagement_id'],
            'tasks_project_id' => 42,
            'tasks_directory_path' => 'client-facing',
            'name' => 'Renamed eng',
        ], $k, $ip);
        $this->assertTrue($upEng['success'], $upEng['error'] ?? '');

        $clearProj = runInvoicingApiUpdateEngagement([
            'engagement_id' => $seed['engagement_id'],
            'tasks_project_id' => 0,
        ], $k, $ip);
        $this->assertTrue($clearProj['success'], $clearProj['error'] ?? '');

        $this->assertSame(401, runInvoicingApiListConfig(null, $ip)['code']);
        $this->assertSame(401, runInvoicingApiListAdminUsers(null, $ip)['code']);
        $this->assertSame(400, runInvoicingApiRefreshOutboundInvoice($k, $ip, ['outbound_id' => 999999])['code']);
    }
}
