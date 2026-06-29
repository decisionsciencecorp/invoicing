<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BillingDueDatesTest extends TestCase
{
    public function testDueDatesRetainerTodayOverageNet30(): void
    {
        $dates = dsc_billing_due_dates_for_publish();
        $today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
        $net30 = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d');
        $this->assertSame($today, $dates['retainer_due_date']);
        $this->assertSame($net30, $dates['overage_due_date']);
    }

    public function testAggregatePaymentStatusBothRequiredForPaid(): void
    {
        $agg = dsc_billing_aggregate_payment_status('paid', 'published', true);
        $this->assertSame('partial', $agg['payment_status']);

        $agg2 = dsc_billing_aggregate_payment_status('paid', 'paid', true);
        $this->assertSame('paid', $agg2['payment_status']);

        $agg3 = dsc_billing_aggregate_payment_status('published', null, false);
        $this->assertSame('published', $agg3['payment_status']);
    }

    public function testCombinedTotalsRetainerAndOverage(): void
    {
        initializeDatabase();
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 5, 10000);
        $eid = $seed['engagement_id'];
        $db->exec(
            "INSERT INTO time_entries (engagement_id, worked_date, hours, billing_period_month) "
            . "VALUES ($eid, '2026-04-15', 7.5, '2026-04')"
        );
        $totals = dsc_billing_combined_totals($db, $eid, '2026-05');
        $this->assertSame(50000, $totals['retainer_amount_cents']);
        $this->assertSame(25000, $totals['overage_amount_cents']);
        $this->assertSame(75000, $totals['total_cents']);
    }
}
