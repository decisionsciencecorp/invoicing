#!/usr/bin/env php
<?php
/**
 * Re-fetch Tasks accounting markdown into outbound rows (fixes poisoned snapshots).
 * Usage: TASKS_DSC_* env set, or config.site_url + tasks_dsc_* in DB.
 *   php tools/resnapshot-accounting-markdown.php [document_id] [outbound_id ...]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$deployedPublic = '/var/www/invoicing.decisionsciencecorp.com/html';
$bootstrap = (is_dir($deployedPublic) && is_file($deployedPublic . '/includes/config.php'))
    ? $deployedPublic . '/includes/config.php'
    : $root . '/public/includes/config.php';
require_once $bootstrap;
require_once dirname($bootstrap) . '/billing.php';

initializeDatabase();
$db = getDbConnection();

$docId = isset($argv[1]) ? (int) $argv[1] : 332;
$outboundIds = array_slice($argv, 2);
if ($outboundIds === []) {
    $outboundIds = ['3', '4'];
}

$fetch = dsc_tasks_fetch_document($docId);
if (!$fetch['ok']) {
    fwrite(STDERR, ($fetch['error'] ?? 'fetch failed') . "\n");
    exit(1);
}
$doc = $fetch['document'] ?? null;
if (!is_array($doc)) {
    fwrite(STDERR, "document missing\n");
    exit(1);
}
$title = (string) ($doc['title'] ?? '');
$body = (string) ($doc['body'] ?? '');

foreach ($outboundIds as $oidRaw) {
    $oid = (int) $oidRaw;
    if ($oid <= 0) {
        continue;
    }
    $up = $db->prepare(
        'UPDATE outbound_invoices SET tasks_document_id = :tdi, tasks_document_title = :tdt, '
        . 'accounting_markdown = :md, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $up->bindValue(':tdi', $docId, SQLITE3_INTEGER);
    $up->bindValue(':tdt', $title, SQLITE3_TEXT);
    $up->bindValue(':md', $body, SQLITE3_TEXT);
    $up->bindValue(':id', $oid, SQLITE3_INTEGER);
    $up->execute();
    fwrite(STDOUT, "Resnapshotted outbound #{$oid} from Tasks doc #{$docId}\n");
}

exit(0);
