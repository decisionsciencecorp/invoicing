<?php
/**
 * Seed a flat outbound invoice for local e2e. Args via env: DB_PATH, SITE_URL.
 */
declare(strict_types=1);

if (getenv('DB_PATH') === false || getenv('DB_PATH') === '') {
    fwrite(STDERR, "DB_PATH required\n");
    exit(1);
}
define('DSC_INVOICING_SKIP_SESSION', true);
require dirname(__DIR__, 2) . '/public/includes/config.php';
require dirname(__DIR__, 2) . '/public/includes/billing.php';

initializeDatabase();
$db = getDbConnection();
$db->exec("INSERT INTO companies (name, billing_email) VALUES ('E2E Flat Co', 'e2e@example.com')");
$cid = (int) $db->lastInsertRowID();
$st = $db->prepare(
    "INSERT INTO engagements (company_id, name, hourly_rate_cents, included_hours_per_month, status, billing_mode, tier1_amount_cents, tier2_amount_cents) "
    . "VALUES (:c, 'Siemens SQ', 0, 0, 'active', 'flat_tier', 212500, 510000)"
);
$st->bindValue(':c', $cid, SQLITE3_INTEGER);
$st->execute();
$eid = (int) $db->lastInsertRowID();
$token = dsc_billing_generate_public_token();
$url = dsc_billing_canonical_invoice_url($token);
$fee = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d');
$ins = $db->prepare(
    'INSERT INTO outbound_invoices (engagement_id, anchor_month, overage_month, retainer_amount_cents, overage_amount_cents, '
    . 'total_amount_cents, public_url, payment_status, public_token, billing_mode, tier_key, fee_due_date, retainer_due_date) '
    . "VALUES (:e, '2026-07', '2026-06', 212500, 0, 212500, :pu, 'published', :pt, 'flat_tier', 'tier1', :fd, :fd)"
);
$ins->bindValue(':e', $eid, SQLITE3_INTEGER);
$ins->bindValue(':pu', $url, SQLITE3_TEXT);
$ins->bindValue(':pt', $token, SQLITE3_TEXT);
$ins->bindValue(':fd', $fee, SQLITE3_TEXT);
$ins->execute();
echo $token;
