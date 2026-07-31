<?php
/**
 * DSC Invoicing — LEMP-compatible, no .htaccess routing.
 */

if (getenv('INVOICING_TEST') && getenv('DB_PATH')) {
    define('DB_PATH', getenv('DB_PATH'));
} elseif (getenv('INVOICING_DB_PATH')) {
    // Deploy CLI: point migrate at the vhost DB (not the empty repo db/ stub).
    define('DB_PATH', (string) getenv('INVOICING_DB_PATH'));
} else {
    // Web root layout: …/html/includes → …/db/invoicing.db (DB_PARENT/db).
    define('DB_PATH', __DIR__ . '/../../db/invoicing.db');
}

define('DB_TIMEOUT', 30);
define('SESSION_NAME', 'dsc_invoicing_admin');
define('ADMIN_SESSION_LIFETIME_SECONDS', 4 * 3600);
define('PASSWORD_COST', 12);
define('SITE_NAME', 'DSC Invoicing');

if (!defined('LOG_PATH')) {
    define('LOG_PATH', __DIR__ . '/../../logs/app.log');
}

/**
 * Canonical site origin for absolute URLs (invoice links, webhooks, cookies).
 * Order: SITE_URL env → config.site_url in SQLite → request Host header → localhost (dev only).
 */
function dsc_invoicing_site_url_from_config_db(): ?string {
    if (!defined('DB_PATH')) {
        return null;
    }
    $path = DB_PATH;
    if (!is_string($path) || $path === '' || !is_file($path)) {
        return null;
    }
    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(5000);
        $st = $db->prepare('SELECT value FROM config WHERE key = :k LIMIT 1');
        $st->bindValue(':k', 'site_url', SQLITE3_TEXT);
        $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        if (!is_array($row)) {
            return null;
        }
        $val = trim((string) ($row['value'] ?? ''));
        return $val !== '' ? rtrim($val, '/') : null;
    } catch (Throwable $e) {
        return null;
    }
}

function dsc_invoicing_resolve_site_url(): string {
    $envSite = getenv('SITE_URL');
    if (is_string($envSite) && trim($envSite) !== '') {
        return rtrim(trim($envSite), '/');
    }
    $fromDb = dsc_invoicing_site_url_from_config_db();
    if ($fromDb !== null) {
        return $fromDb;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '' && $host !== 'localhost' && !str_contains($host, '127.0.0.1')) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        return ($https ? 'https' : 'http') . '://' . $host;
    }
    return 'http://localhost';
}

if (!defined('SITE_URL')) {
    define('SITE_URL', dsc_invoicing_resolve_site_url());
}

if (session_status() === PHP_SESSION_NONE && !(defined('DSC_INVOICING_SKIP_SESSION') && DSC_INVOICING_SKIP_SESSION)) {
    $secure = defined('SITE_URL') && strpos(SITE_URL, 'https://') === 0;
    $sessionTtl = (int) ADMIN_SESSION_LIFETIME_SECONDS;
    if ($sessionTtl < 4 * 3600) {
        $sessionTtl = 4 * 3600;
    }
    ini_set('session.gc_maxlifetime', (string) $sessionTtl);
    session_set_cookie_params([
        'lifetime' => $sessionTtl,
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
    ]);
    session_name(SESSION_NAME);
    session_start();
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$isDevelopment = (
    $host === 'localhost'
    || str_contains($host, '127.0.0.1')
    || (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development')
);
if ($isDevelopment) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../../logs/php-errors.log');
}

date_default_timezone_set('UTC');

require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/database.php';
