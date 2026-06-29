#!/usr/bin/env php
<?php
/**
 * One-shot: hydrate legacy outbound rows + attach PSF Tasks time logs (#621, #332).
 * Usage: php tools/backfill-psf-invoice-docs.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/public/includes/config.php';
require_once $root . '/public/includes/billing.php';

initializeDatabase();
$db = getDbConnection();

$res = dsc_billing_backfill_psf_invoice_documents($db);
if (!empty($res['ok'])) {
    fwrite(STDOUT, 'OK — updated ' . (int) ($res['updated'] ?? 0) . " row(s).\n");
    exit(0);
}

fwrite(STDERR, 'Partial failure — updated ' . (int) ($res['updated'] ?? 0) . " row(s).\n");
foreach ($res['errors'] ?? [] as $err) {
    fwrite(STDERR, $err . "\n");
}
exit(1);
