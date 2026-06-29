#!/usr/bin/env php
<?php
/**
 * Smoke test: outbound invoice client URLs must not point at localhost.
 * Exit 0 when all OK; exit 1 and print offenders otherwise.
 *
 * Usage (on server): php tools/verify-invoice-urls.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

// Simulate production vhost when run from CLI on multihost.
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'invoicing.decisionsciencecorp.com';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
}

require_once $root . '/public/includes/config.php';
require_once $root . '/public/includes/square.php';

initializeDatabase();
$db = getDbConnection();

$bad = [];
$rows = $db->query(
    'SELECT id, anchor_month, public_url, public_token, tasks_document_title '
    . 'FROM outbound_invoices ORDER BY id'
);
while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
    $id = (int) ($row['id'] ?? 0);
    $url = dsc_billing_client_page_url($row);
    $canonical = !empty($row['public_token'])
        ? dsc_billing_canonical_invoice_url((string) $row['public_token'])
        : '';
    foreach (['client_page_url' => $url, 'canonical_rebuild' => $canonical, 'stored_public_url' => (string) ($row['public_url'] ?? '')] as $label => $u) {
        $u = trim($u);
        if ($u === '') {
            continue;
        }
        if (str_contains($u, 'localhost') || str_contains($u, '127.0.0.1')) {
            $bad[] = "outbound #{$id} ({$row['anchor_month']}) {$label}: {$u}";
        }
    }
    fwrite(STDOUT, "#{$id} client=" . ($url !== '' ? $url : '(none)') . "\n");
}

fwrite(STDOUT, 'SITE_URL=' . SITE_URL . "\n");

if ($bad !== []) {
    fwrite(STDERR, "FAIL — localhost URLs detected:\n" . implode("\n", $bad) . "\n");
    exit(1);
}

fwrite(STDOUT, "OK — no localhost invoice URLs.\n");
exit(0);
