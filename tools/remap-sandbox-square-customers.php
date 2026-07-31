#!/usr/bin/env php
<?php
/**
 * Dev helper: create Square *sandbox* customers for each company and rewrite
 * companies.square_customer_id. Does not touch production Square or prod DB.
 *
 * Usage (on multihost, against the DEV database):
 *   SQUARE_ACCESS_TOKEN=… SQUARE_ENVIRONMENT=sandbox \
 *   DB_PATH=/var/www/dev.invoicing.decisionsciencecorp.com/db/invoicing.db \
 *   php tools/remap-sandbox-square-customers.php
 *
 * Idempotency keys are derived from company id + billing email so re-runs reuse
 * the same sandbox customer when Square still has it.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('INVOICING_SQUARE_SKIP_ENV_FILE=1');
require_once $root . '/public/includes/config.php';
require_once $root . '/public/includes/database.php';
require_once $root . '/public/includes/square.php';

$cfg = dsc_invoicing_square_config();
if (($cfg['environment'] ?? '') !== 'sandbox') {
    fwrite(STDERR, "Refusing to run unless square_environment is sandbox (got "
        . ($cfg['environment'] ?? 'unset') . ").\n");
    exit(1);
}
if (empty($cfg['access_token'])) {
    fwrite(STDERR, "No Square sandbox access token configured.\n");
    exit(1);
}

initializeDatabase();
$db = getDbConnection();
$res = $db->query('SELECT id, name, billing_email, square_customer_id FROM companies ORDER BY id');
$updated = 0;
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $id = (int) ($row['id'] ?? 0);
    $name = trim((string) ($row['name'] ?? ''));
    $email = trim((string) ($row['billing_email'] ?? ''));
    if ($id <= 0 || $name === '') {
        continue;
    }
    $idem = 'dev-inv-remap-co' . $id . '-' . substr(sha1($email !== '' ? $email : $name), 0, 12);
    $body = [
        'idempotency_key' => $idem,
        'given_name' => 'DSC',
        'family_name' => 'Billing',
        'company_name' => $name,
        'reference_id' => 'dev-invoicing-remap',
    ];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $body['email_address'] = $email;
    }
    $resp = dsc_invoicing_square_request('POST', '/customers', $body);
    if (empty($resp['ok'])) {
        fwrite(STDERR, "company #{$id} {$name}: " . ($resp['error'] ?? 'create failed') . "\n");
        continue;
    }
    $newId = (string) ($resp['data']['customer']['id'] ?? '');
    if ($newId === '') {
        fwrite(STDERR, "company #{$id}: no customer id in response\n");
        continue;
    }
    $up = $db->prepare(
        'UPDATE companies SET square_customer_id = :c, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $up->bindValue(':c', $newId, SQLITE3_TEXT);
    $up->bindValue(':id', $id, SQLITE3_INTEGER);
    $up->execute();
    $old = (string) ($row['square_customer_id'] ?? '');
    echo "company #{$id} {$name}: {$old} → {$newId}\n";
    $updated++;
}
echo "Updated {$updated} company row(s).\n";
echo "Note: existing outbound_invoices still reference prior Square invoice IDs; new publishes use sandbox customers.\n";
