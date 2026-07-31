#!/usr/bin/env php
<?php
/**
 * Optional schema ensure (same idempotent path as login/API).
 *
 * App entrypoints already call initializeDatabase() and self-heal older DBs —
 * you do not need to run this after deploy. Kept for ops who want to warm
 * schema before traffic or point at a vhost DB via INVOICING_DB_PATH.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/public/includes/config.php';
require_once $root . '/public/includes/database.php';

initializeDatabase();
fwrite(STDOUT, "invoicing migrate: ok (idempotent; also runs automatically on app use)\n");
exit(0);
