<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CrudHandlersTest extends TestCase
{
    private string $apiKey = '';

    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
        $this->apiKey = createApiKey('crud');
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();
    }

    public function testCompanyEngagementTimeEntryCrudAndPublish(): void
    {
        $ip = '127.0.0.1';
        $key = $this->apiKey;

        $this->assertFalse(runInvoicingApiListCompanies(null, $ip)['success']);

        $c = runInvoicingApiCreateCompany([
            'name' => 'AcquireROI Test LLC',
            'billing_email' => 'billing@acquire.test',
        ], $key, $ip);
        $this->assertTrue($c['success'], $c['error'] ?? '');
        $companyId = (int) $c['data']['company_id'];

        $get = runInvoicingApiGetCompany($key, $ip, $companyId);
        $this->assertTrue($get['success']);
        $list = runInvoicingApiListCompanies($key, $ip);
        $this->assertNotEmpty($list['data']['companies']);

        $up = runInvoicingApiUpdateCompany([
            'company_id' => $companyId,
            'notes' => 'flat client',
        ], $key, $ip);
        $this->assertTrue($up['success']);

        $eng = runInvoicingApiCreateEngagement([
            'company_id' => $companyId,
            'name' => 'Siemens SQ',
            'billing_mode' => 'flat_tier',
            'tier1_amount_cents' => 212500,
            'tier2_amount_cents' => 510000,
        ], $key, $ip);
        $this->assertTrue($eng['success'], $eng['error'] ?? '');
        $eid = (int) $eng['data']['engagement_id'];

        $getE = runInvoicingApiGetEngagement($key, $ip, $eid);
        $this->assertTrue($getE['success']);
        $this->assertSame('flat_tier', $getE['data']['engagement']['billing_mode'] ?? null);

        $listE = runInvoicingApiListEngagements($key, $ip, $companyId);
        $this->assertTrue($listE['success']);

        $upE = runInvoicingApiUpdateEngagement([
            'engagement_id' => $eid,
            'tier1_amount_cents' => 212500,
            'billing_mode' => 'flat_tier',
        ], $key, $ip);
        $this->assertTrue($upE['success']);

        $hourly = runInvoicingApiCreateEngagement([
            'company_id' => $companyId,
            'name' => 'Hourly eng',
            'hourly_rate_cents' => 10000,
            'included_hours_per_month' => 5,
        ], $key, $ip);
        $this->assertTrue($hourly['success']);
        $hid = (int) $hourly['data']['engagement_id'];

        $te = runInvoicingApiCreateTimeEntry([
            'engagement_id' => $hid,
            'worked_date' => '2026-06-01',
            'hours' => 6,
            'billing_period_month' => '2026-06',
            'memo' => 'work',
        ], $key, $ip);
        $this->assertTrue($te['success'], $te['error'] ?? '');
        $tid = (int) $te['data']['time_entry_id'];

        $getT = runInvoicingApiGetTimeEntry($key, $ip, $tid);
        $this->assertTrue($getT['success']);
        $listT = runInvoicingApiListTimeEntries($key, $ip, $hid, '2026-06', 50, 0);
        $this->assertTrue($listT['success']);
        $upT = runInvoicingApiUpdateTimeEntry([
            'time_entry_id' => $tid,
            'hours' => 7,
        ], $key, $ip);
        $this->assertTrue($upT['success']);

        $pubFlat = runInvoicingApiPublishCombinedInvoice([
            'engagement_id' => $eid,
            'anchor_month' => '2026-07',
            'tier_key' => 'tier1',
        ], $key, $ip);
        $this->assertTrue($pubFlat['success'], $pubFlat['error'] ?? json_encode($pubFlat));
        $oid = (int) ($pubFlat['data']['outbound_id'] ?? 0);

        $pubHourly = runInvoicingApiPublishCombinedInvoice([
            'engagement_id' => $hid,
            'anchor_month' => '2026-07',
            'tasks_document_id' => 77,
        ], $key, $ip);
        $this->assertTrue($pubHourly['success'], $pubHourly['error'] ?? json_encode($pubHourly));

        $listO = runInvoicingApiListOutboundInvoices($key, $ip, $eid, 50, 0);
        $this->assertTrue($listO['success']);
        $getO = runInvoicingApiGetOutboundInvoice($key, $ip, $oid);
        $this->assertTrue($getO['success']);

        $delT = runInvoicingApiDeleteTimeEntry(['time_entry_id' => $tid], $key, $ip);
        $this->assertTrue($delT['success']);
        $delH = runInvoicingApiDeleteEngagement(['engagement_id' => $hid], $key, $ip);
        $this->assertTrue($delH['success']);
    }

    public function testPublishApiRequiresTierForFlatDefaultAndHourlyDoc(): void
    {
        $ip = '10.0.0.2';
        $key = $this->apiKey;
        $c = runInvoicingApiCreateCompany(['name' => 'Co2', 'billing_email' => 'a@b.co'], $key, $ip);
        $cid = (int) $c['data']['company_id'];
        $eng = runInvoicingApiCreateEngagement([
            'company_id' => $cid,
            'name' => 'Flat2',
            'billing_mode' => 'flat_tier',
            'tier1_amount_cents' => 1000,
            'tier2_amount_cents' => 2000,
        ], $key, $ip);
        $eid = (int) $eng['data']['engagement_id'];

        $pub = runInvoicingApiPublishCombinedInvoice([
            'engagement_id' => $eid,
            'anchor_month' => '2026-10',
        ], $key, $ip);
        $this->assertTrue($pub['success'], $pub['error'] ?? '');

        $h = runInvoicingApiCreateEngagement([
            'company_id' => $cid,
            'name' => 'H2',
            'hourly_rate_cents' => 10000,
            'included_hours_per_month' => 1,
        ], $key, $ip);
        $hid = (int) $h['data']['engagement_id'];
        $needDoc = runInvoicingApiPublishCombinedInvoice([
            'engagement_id' => $hid,
            'anchor_month' => '2026-10',
        ], $key, $ip);
        $this->assertFalse($needDoc['success']);
    }
}
