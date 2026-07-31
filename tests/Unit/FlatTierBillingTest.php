<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FlatTierBillingTest extends TestCase
{
    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
    }

    public function testFlatFeeDueDateIsNet30(): void
    {
        $net30 = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d');
        $this->assertSame($net30, dsc_billing_flat_fee_due_date());
    }

    public function testNormalizeTierAndLabels(): void
    {
        $this->assertSame('tier1', dsc_billing_normalize_tier_key(null));
        $this->assertSame('tier1', dsc_billing_normalize_tier_key('tier1'));
        $this->assertSame('tier2', dsc_billing_normalize_tier_key('tier2'));
        $this->assertSame('tier1', dsc_billing_normalize_tier_key('nope'));
        $this->assertSame('Tier 1', dsc_billing_tier_label('tier1'));
        $this->assertSame('Tier 2', dsc_billing_tier_label('tier2'));
    }

    public function testFlatTierTotalsTier1AndTier2(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 212500, 510000);
        $t1 = dsc_billing_combined_totals($db, $seed['engagement_id'], '2026-07', 'tier1');
        $this->assertSame('flat_tier', $t1['billing_mode']);
        $this->assertSame(212500, $t1['retainer_amount_cents']);
        $this->assertSame(0, $t1['overage_amount_cents']);
        $this->assertSame(212500, $t1['total_cents']);
        $this->assertSame('tier1', $t1['tier_key']);

        $t2 = dsc_billing_combined_totals($db, $seed['engagement_id'], '2026-07', 'tier2');
        $this->assertSame(510000, $t2['total_cents']);
        $this->assertSame('tier2', $t2['tier_key']);
    }

    public function testFlatTierRejectsZeroAmount(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 0, 510000);
        $err = dsc_billing_combined_totals($db, $seed['engagement_id'], '2026-07', 'tier1');
        $this->assertArrayHasKey('error', $err);
    }

    public function testHourlyPathIgnoresTimeForFlat(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 5, 10000, 'flat_tier', 212500, 510000);
        $eid = $seed['engagement_id'];
        $db->exec(
            "INSERT INTO time_entries (engagement_id, worked_date, hours, billing_period_month) "
            . "VALUES ($eid, '2026-06-15', 40, '2026-06')"
        );
        $totals = dsc_billing_combined_totals($db, $eid, '2026-07', 'tier1');
        $this->assertSame(212500, $totals['total_cents']);
        $this->assertSame(0, $totals['overage_amount_cents']);
    }

    public function testPublishFlatWithoutTasksDoc(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 212500, 510000);
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();

        $pub = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-07', null, 'tier1');
        $this->assertTrue($pub['ok'], $pub['error'] ?? 'publish failed');
        $this->assertNotEmpty($pub['outbound_id']);
        $this->assertStringContainsString('invoice.php?t=', (string) ($pub['canonical_url'] ?? ''));

        $row = $db->querySingle(
            'SELECT billing_mode, tier_key, fee_due_date, retainer_amount_cents, overage_amount_cents, tasks_document_id '
            . 'FROM outbound_invoices WHERE id = ' . (int) $pub['outbound_id'],
            true
        );
        $this->assertSame('flat_tier', $row['billing_mode']);
        $this->assertSame('tier1', $row['tier_key']);
        $this->assertSame(212500, (int) $row['retainer_amount_cents']);
        $this->assertSame(0, (int) $row['overage_amount_cents']);
        $this->assertNull($row['tasks_document_id']);
        $net30 = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d');
        $this->assertSame($net30, $row['fee_due_date']);
    }

    public function testPublishHourlyStillRequiresTasksDoc(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 5, 10000);
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();
        $pub = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-07', null);
        $this->assertFalse($pub['ok']);
        $this->assertStringContainsString('Tasks accounting document', (string) ($pub['error'] ?? ''));
    }

    public function testPublishHourlyWithDocAndOverage(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 5, 10000);
        $eid = $seed['engagement_id'];
        $db->exec(
            "INSERT INTO time_entries (engagement_id, worked_date, hours, billing_period_month) "
            . "VALUES ($eid, '2026-06-10', 8, '2026-06')"
        );
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();
        $pub = dsc_billing_publish_combined_invoice($db, $eid, '2026-07', 42);
        $this->assertTrue($pub['ok'], $pub['error'] ?? 'hourly publish failed');
        $row = $db->querySingle(
            'SELECT retainer_amount_cents, overage_amount_cents, billing_mode, tier_key '
            . 'FROM outbound_invoices WHERE id = ' . (int) $pub['outbound_id'],
            true
        );
        $this->assertSame('hourly', $row['billing_mode']);
        $this->assertNull($row['tier_key']);
        $this->assertSame(50000, (int) $row['retainer_amount_cents']);
        $this->assertSame(30000, (int) $row['overage_amount_cents']);
    }

    public function testInvalidMonthAndInactiveEngagement(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 5, 10000);
        $bad = dsc_billing_combined_totals($db, $seed['engagement_id'], 'not-a-month');
        $this->assertArrayHasKey('error', $bad);
        $db->exec('UPDATE engagements SET status = \'paused\' WHERE id = ' . $seed['engagement_id']);
        $paused = dsc_billing_combined_totals($db, $seed['engagement_id'], '2026-07');
        $this->assertArrayHasKey('error', $paused);
        $missing = dsc_billing_combined_totals($db, 999999, '2026-07');
        $this->assertArrayHasKey('error', $missing);
    }

    public function testMonthHelpers(): void
    {
        $this->assertTrue(dsc_billing_valid_month('2026-07'));
        $this->assertFalse(dsc_billing_valid_month('2026-7'));
        $this->assertSame('2026-06', dsc_billing_prev_month('2026-07'));
        $this->assertSame('2025-12', dsc_billing_prev_month('2026-01'));
        $this->assertSame('2026-07-31', dsc_billing_month_last_day_iso('2026-07'));
    }

    public function testSquareStatusMap(): void
    {
        $this->assertSame('paid', dsc_billing_square_map_invoice_status('PAID'));
        $this->assertSame('canceled', dsc_billing_square_map_invoice_status('CANCELED'));
        $this->assertSame('published', dsc_billing_square_map_invoice_status('UNPAID'));
        $this->assertSame('published', dsc_billing_square_map_invoice_status(''));
    }
}
