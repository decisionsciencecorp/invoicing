#!/usr/bin/env php
<?php
/**
 * Idempotent schema migrate (ALTER/ensure helpers).
 * Run on deploy: php tools/migrate.php
 * Also invoked from initializeDatabase() so existing hosts keep self-healing
 * until deploy hooks call this explicitly.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/public/includes/config.php';
require_once $root . '/public/includes/database.php';

initializeDatabase();
fwrite(STDOUT, "invoicing migrate: ok\n");
exit(0);
