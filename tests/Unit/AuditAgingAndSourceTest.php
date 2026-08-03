<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuditAgingAndSourceTest extends TestCase
{
    public function testAuditLogRoundTrip(): void
    {
        require_once dirname(__DIR__, 2) . '/public/includes/audit.php';
        initializeDatabase();
        $before = dsc_invoicing_audit_log_count();
        dsc_invoicing_audit_log('unit.test', 'phpunit', 'outbound_invoice', '1', ['ok' => true]);
        $this->assertSame($before + 1, dsc_invoicing_audit_log_count());
        $rows = dsc_invoicing_audit_log_list(5, 0);
        $this->assertNotEmpty($rows);
        $this->assertSame('unit.test', $rows[0]['action']);
    }

    public function testUnpaidAgingBuckets(): void
    {
        $db = getDbConnection();
        initializeDatabase();
        $db->exec("INSERT INTO companies (name, billing_email) VALUES ('Aging Co', 'a@example.com')");
        $cid = (int) $db->lastInsertRowID();
        $db->exec(
            "INSERT INTO engagements (company_id, name, hourly_rate_cents, included_hours_per_month, status) "
            . "VALUES ($cid, 'Eng', 10000, 10, 'active')"
        );
        $eid = (int) $db->lastInsertRowID();
        $past = (new DateTimeImmutable('-45 days'))->format('Y-m-d');
        $st = $db->prepare(
            'INSERT INTO outbound_invoices (engagement_id, anchor_month, overage_month, retainer_amount_cents, '
            . 'overage_amount_cents, total_amount_cents, payment_status, retainer_due_date, public_token) '
            . 'VALUES (:e, :a, :o, 100, 0, 100, \'published\', :d, :t)'
        );
        $st->bindValue(':e', $eid, SQLITE3_INTEGER);
        $st->bindValue(':a', '2026-05', SQLITE3_TEXT);
        $st->bindValue(':o', '2026-04', SQLITE3_TEXT);
        $st->bindValue(':d', $past, SQLITE3_TEXT);
        $st->bindValue(':t', bin2hex(random_bytes(16)), SQLITE3_TEXT);
        $st->execute();

        $rows = dsc_billing_list_unpaid_aging($db);
        $this->assertNotEmpty($rows);
        $hit = null;
        foreach ($rows as $r) {
            if ((int) $r['engagement_id'] === $eid) {
                $hit = $r;
                break;
            }
        }
        $this->assertNotNull($hit);
        $this->assertSame('31-60', $hit['aging_bucket']);
        $this->assertGreaterThanOrEqual(31, (int) $hit['days_past_due']);
    }

    public function testTasksSourceForEngagement(): void
    {
        $db = getDbConnection();
        initializeDatabase();
        $db->exec("INSERT INTO companies (name) VALUES ('Src Co')");
        $cid = (int) $db->lastInsertRowID();
        $ins = $db->prepare(
            'INSERT INTO engagements (company_id, name, hourly_rate_cents, included_hours_per_month, status, '
            . 'tasks_project_id, tasks_directory_path) VALUES (:c, \'E\', 10000, 0, \'active\', 99, \'acct-docs\')'
        );
        $ins->bindValue(':c', $cid, SQLITE3_INTEGER);
        $ins->execute();
        $eid = (int) $db->lastInsertRowID();
        $src = dsc_tasks_source_for_engagement($db, $eid);
        $this->assertSame(99, $src['project_id']);
        $this->assertSame('acct-docs', $src['directory_path']);
    }

    public function testWebhookRefreshEventTypes(): void
    {
        $types = dsc_invoicing_square_webhook_refresh_event_types();
        $this->assertContains('invoice.payment_made', $types);
        $this->assertContains('invoice.canceled', $types);
    }

    public function testStatusPillHelper(): void
    {
        require_once dirname(__DIR__, 2) . '/public/admin/includes/helpers.php';
        $this->assertStringContainsString('paid', inv_status_pill_class('paid'));
        $this->assertStringContainsString('canceled', inv_status_pill_class('canceled'));
        $this->assertStringContainsString('published', inv_status_pill_class('published'));
    }

    public function testOutboundPeriodLabel(): void
    {
        $this->assertSame(
            'July 2026 retainer',
            dsc_billing_outbound_period_label([
                'anchor_month' => '2026-07-R',
                'overage_month' => '2026-06',
                'retainer_amount_cents' => 50000,
                'overage_amount_cents' => 0,
            ])
        );
        $this->assertSame(
            'June 2026 overage',
            dsc_billing_outbound_period_label([
                'anchor_month' => '2026-07-O',
                'overage_month' => '2026-06',
                'retainer_amount_cents' => 0,
                'overage_amount_cents' => 100000,
            ])
        );
        $this->assertSame(
            'August 2026 retainer + July 2026 overage',
            dsc_billing_outbound_period_label([
                'anchor_month' => '2026-08',
                'overage_month' => '2026-07',
                'retainer_amount_cents' => 50000,
                'overage_amount_cents' => 242500,
            ])
        );
    }

    public function testCancelOutboundInvoice(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();
        unset($GLOBALS['_dsc_square_mock_canceled'], $GLOBALS['_dsc_square_mock_paid']);

        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 212500, 510000);
        $pub = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-07', null, 'tier1');
        $this->assertTrue($pub['ok'], $pub['error'] ?? '');
        $oid = (int) $pub['outbound_id'];

        $cancel = dsc_billing_cancel_outbound_invoice($db, $oid);
        $this->assertTrue($cancel['ok'], $cancel['error'] ?? '');
        $this->assertNotEmpty($cancel['canceled'] ?? []);

        $status = (string) $db->querySingle('SELECT payment_status FROM outbound_invoices WHERE id = ' . $oid);
        $this->assertSame('canceled', strtolower($status));

        $paidBlock = dsc_billing_cancel_outbound_invoice($db, $oid);
        $this->assertTrue($paidBlock['ok']); // already canceled → success no-op
    }
}
