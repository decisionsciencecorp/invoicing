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
}
