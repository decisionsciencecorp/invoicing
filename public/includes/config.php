<?php
/**
 * DSC Invoicing — LEMP-compatible, no .htaccess routing.
 */

if (getenv('INVOICING_TEST') && getenv('DB_PATH')) {
    define('DB_PATH', getenv('DB_PATH'));
} else {
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
 * Prefers SITE_URL env; otherwise derives from the incoming request on real vhosts.
 */
function dsc_invoicing_resolve_site_url(): string {
    $envSite = getenv('SITE_URL');
    if (is_string($envSite) && trim($envSite) !== '') {
        return rtrim(trim($envSite), '/');
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
