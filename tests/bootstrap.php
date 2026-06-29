<?php
/**
 * PHPUnit bootstrap for DSC Invoicing.
 */
$projectRoot = dirname(__DIR__);
putenv('INVOICING_TEST=1');
$dbPath = getenv('DB_PATH');
if ($dbPath === false || $dbPath === '' || $dbPath === ':memory:') {
    $dbPath = sys_get_temp_dir() . '/dsc_invoicing_test_' . getmypid() . '.db';
    putenv('DB_PATH=' . $dbPath);
}
$_ENV['DB_PATH'] = $dbPath;

defined('DSC_INVOICING_SKIP_SESSION') || define('DSC_INVOICING_SKIP_SESSION', true);

require_once $projectRoot . '/public/includes/config.php';
require_once $projectRoot . '/public/includes/markdown.php';
require_once $projectRoot . '/public/includes/billing.php';

function invoicing_test_seed_company_engagement(SQLite3 $db, int $includedHours = 5, int $rateCents = 10000): array {
    $db->exec("INSERT INTO companies (name, billing_email) VALUES ('Test Co', 'billing@example.com')");
    $companyId = (int) $db->lastInsertRowID();
    $st = $db->prepare(
        'INSERT INTO engagements (company_id, name, hourly_rate_cents, included_hours_per_month, status) '
        . 'VALUES (:c, :n, :r, :h, \'active\')'
    );
    $st->bindValue(':c', $companyId, SQLITE3_INTEGER);
    $st->bindValue(':n', 'Retainer', SQLITE3_TEXT);
    $st->bindValue(':r', $rateCents, SQLITE3_INTEGER);
    $st->bindValue(':h', $includedHours, SQLITE3_INTEGER);
    $st->execute();
    return ['company_id' => $companyId, 'engagement_id' => (int) $db->lastInsertRowID()];
}
