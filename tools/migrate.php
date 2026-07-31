#!/usr/bin/env php
<?php
/**
 * Idempotent schema migrate (CREATE + ALTER ensure_* helpers).
 * Run on deploy after sync: php tools/migrate.php
 *
 * HTTP/API request paths call initializeDatabase(false) — CREATE only.
 * Column ALTERs for older DB files run here (and in PHPUnit).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/public/includes/config.php';
require_once $root . '/public/includes/database.php';

initializeDatabase(true);
fwrite(STDOUT, "invoicing migrate: ok\n");
exit(0);
