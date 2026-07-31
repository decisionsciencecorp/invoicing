<?php

require_once __DIR__ . '/config.php';

/**
 * Public absolute base URL for share links (CLI/cron may lack HTTP_HOST — use config or env).
 */
function dsc_invoicing_public_base_url(): string {
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }
    $envSite = getenv('SITE_URL');
    if (is_string($envSite) && trim($envSite) !== '') {
        return $cached = rtrim(trim($envSite), '/');
    }
    if (function_exists('get_config')) {
        try {
            $fromCfg = get_config('site_url');
            if (is_string($fromCfg) && trim($fromCfg) !== '') {
                return $cached = rtrim(trim($fromCfg), '/');
            }
        } catch (Throwable $e) {
            // config table may not be ready during early bootstrap.
        }
    }
    if (function_exists('dsc_invoicing_resolve_site_url')) {
        $resolved = dsc_invoicing_resolve_site_url();
        if ($resolved !== 'http://localhost') {
            return $cached = $resolved;
        }
    }
    return $cached = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'http://localhost';
}

function dsc_invoicing_web_base_path(): string {
    $env = getenv('INVOICING_WEB_BASE');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }
    if (function_exists('get_config')) {
        try {
            $fromConfig = get_config('web_base_path');
            if (is_string($fromConfig) && trim($fromConfig) !== '') {
                return rtrim(trim($fromConfig), '/');
            }
        } catch (Throwable $e) {
            // DB/config table may not be available very early in bootstrap.
        }
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('#^(.*)/admin/[^/]+\.php$#', $script, $m)) {
        return rtrim($m[1], '/');
    }
    if (preg_match('#^(.*)/api/[^/]+\.php$#', $script, $m)) {
        return rtrim($m[1], '/');
    }
    if (preg_match('#^(.+)/index\.php$#', $script, $m)) {
        return rtrim($m[1], '/');
    }

    $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($scriptFilename) && is_string($documentRoot) && $scriptFilename !== '' && $documentRoot !== '') {
        $sf = str_replace('\\', '/', $scriptFilename);
        $dr = rtrim(str_replace('\\', '/', $documentRoot), '/');
        if (str_starts_with($sf, $dr . '/')) {
            $rel = substr($sf, strlen($dr));
            if (preg_match('#^(/.+)/[^/]+\.php$#', $rel, $m)) {
                return rtrim($m[1], '/');
            }
        }
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (is_string($uri) && preg_match('#^(/.+)/(admin|api)/[^/?]+(?:\.php)?(?:\?|$)#', $uri, $m)) {
        return rtrim($m[1], '/');
    }

    return '';
}

/** URL path under the app document root (public/). */
function dsc_invoicing_href(string $path): string {
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    $base = dsc_invoicing_web_base_path();
    if ($base === '') {
        return $path;
    }
    return $base . $path;
}

/**
 * Safe post-login redirect target (same-app /admin/*.php only).
 */
function dsc_invoicing_safe_admin_return(?string $raw): string {
    $default = dsc_invoicing_href('admin/index.php');
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $default;
    }
    // Absolute URLs are never accepted as return targets (open-redirect brake).
    if (preg_match('#^https?://#i', $raw) || str_starts_with($raw, '//')) {
        return $default;
    }
    $pathOnly = $raw;
    $query = '';
    if (str_contains($raw, '?')) {
        [$pathOnly, $query] = explode('?', $raw, 2);
    }
    $pathOnly = '/' . ltrim(str_replace('\\', '/', $pathOnly), '/');
    $base = dsc_invoicing_web_base_path();
    if ($base !== '' && str_starts_with($pathOnly, $base . '/')) {
        $pathOnly = substr($pathOnly, strlen($base));
        if ($pathOnly === '' || $pathOnly[0] !== '/') {
            $pathOnly = '/' . ltrim($pathOnly, '/');
        }
    }
    if (!preg_match('#^/admin/[a-zA-Z0-9_-]+\.php$#', $pathOnly)) {
        return $default;
    }
    if (str_ends_with($pathOnly, '/login.php') || str_ends_with($pathOnly, '/logout.php')) {
        return $default;
    }
    if ($query !== '' && !preg_match('#^[a-zA-Z0-9_=&%./+-]+$#', $query)) {
        $query = '';
    }
    $rel = ltrim($pathOnly, '/') . ($query !== '' ? '?' . $query : '');

    return dsc_invoicing_href($rel);
}

/** Current request path+query for login ?return= (empty when not useful). */
function dsc_invoicing_current_request_return(): string {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '' || str_contains($uri, 'login.php')) {
        return '';
    }

    return $uri;
}

function app_log(string $level, string $message): void {
    if (!defined('LOG_PATH')) {
        return;
    }
    $dir = dirname(LOG_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = date('c') . ' [' . $level . '] ' . $message . "\n";
    @file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}

function dsc_invoicing_authorization_header(): string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return (string) $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0) {
                return is_string($v) ? $v : '';
            }
        }
    }

    return '';
}

/**
 * API key from header (X-API-Key), Bearer token, query (?api_key=), or JSON body api_key (POST).
 *
 * @return string|null
 */
/**
 * Resolve API key without reading php://input — use after you have decoded JSON ($body may contain api_key).
 *
 * @param array<string,mixed>|null $parsedJsonBody
 */
function dsc_invoicing_resolve_api_key(?array $parsedJsonBody = null): ?string {
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return trim((string) $_SERVER['HTTP_X_API_KEY']);
    }
    $auth = dsc_invoicing_authorization_header();
    if ($auth !== '' && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return trim($m[1]);
    }
    if (isset($_GET['api_key'])) {
        return trim((string) $_GET['api_key']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['api_key'])) {
        return trim((string) $_POST['api_key']);
    }
    if (is_array($parsedJsonBody) && isset($parsedJsonBody['api_key'])) {
        return trim((string) $parsedJsonBody['api_key']);
    }

    return null;
}

function getApiKey() {
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return trim((string) $_SERVER['HTTP_X_API_KEY']);
    }
    $auth = dsc_invoicing_authorization_header();
    if ($auth !== '' && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return trim($m[1]);
    }
    if (isset($_GET['api_key'])) {
        return trim((string) $_GET['api_key']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (isset($_POST['api_key'])) {
            return trim((string) $_POST['api_key']);
        }
        $input = file_get_contents('php://input');
        if ($input !== false && $input !== '') {
            $data = json_decode($input, true);
            if (is_array($data) && isset($data['api_key'])) {
                return trim((string) $data['api_key']);
            }
        }
    }

    return null;
}

function validateApiKey(?string $apiKey): bool {
    if ($apiKey === null || $apiKey === '') {
        return false;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT id FROM api_keys WHERE api_key = :key');
    $stmt->bindValue(':key', $apiKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        $up = $db->prepare('UPDATE api_keys SET last_used = CURRENT_TIMESTAMP WHERE id = :id');
        $up->bindValue(':id', $row['id'], SQLITE3_INTEGER);
        $up->execute();

        return true;
    }

    return false;
}

function checkRateLimit(string $rateKey, int $limit = 60, int $windowSeconds = 60): bool {
    $db = getDbConnection();
    $now = time();
    $stmt = $db->prepare('SELECT window_start, count FROM api_rate_limits WHERE rate_key = :rate_key');
    $stmt->bindValue(':rate_key', $rateKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        $ins = $db->prepare(
            'INSERT INTO api_rate_limits (rate_key, window_start, count) VALUES (:rate_key, :window_start, :count)'
        );
        $ins->bindValue(':rate_key', $rateKey, SQLITE3_TEXT);
        $ins->bindValue(':window_start', $now, SQLITE3_INTEGER);
        $ins->bindValue(':count', 1, SQLITE3_INTEGER);
        $ins->execute();

        return true;
    }
    $windowStart = (int) $row['window_start'];
    $count = (int) $row['count'];
    if ($now - $windowStart >= $windowSeconds) {
        $reset = $db->prepare(
            'UPDATE api_rate_limits SET window_start = :window_start, count = :count WHERE rate_key = :rate_key'
        );
        $reset->bindValue(':window_start', $now, SQLITE3_INTEGER);
        $reset->bindValue(':count', 1, SQLITE3_INTEGER);
        $reset->bindValue(':rate_key', $rateKey, SQLITE3_TEXT);
        $reset->execute();

        return true;
    }
    if ($count >= $limit) {
        return false;
    }
    $up = $db->prepare('UPDATE api_rate_limits SET count = count + 1 WHERE rate_key = :rate_key');
    $up->bindValue(':rate_key', $rateKey, SQLITE3_TEXT);
    $up->execute();

    return true;
}

function jsonSuccess(array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => true], $data));
}

function jsonError(string $message, int $code = 400): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
}

/** @return array<int, array<string,mixed>> */
function getAllApiKeys(): array {
    $db = getDbConnection();
    $r = $db->query('SELECT id, key_name, api_key, created_at, last_used FROM api_keys ORDER BY created_at DESC');
    $out = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $row['api_key'] = substr((string) $row['api_key'], 0, 8) . '…';
        $out[] = $row;
    }

    return $out;
}

function createApiKey(string $keyName): string {
    $keyName = trim($keyName);
    if ($keyName === '') {
        $keyName = 'Unnamed';
    }
    $db = getDbConnection();
    $apiKey = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO api_keys (key_name, api_key) VALUES (:name, :key)');
    $stmt->bindValue(':name', $keyName, SQLITE3_TEXT);
    $stmt->bindValue(':key', $apiKey, SQLITE3_TEXT);
    $stmt->execute();

    return $apiKey;
}

function deleteApiKey(int $id): void {
    $db = getDbConnection();
    $stmt = $db->prepare('DELETE FROM api_keys WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function dsc_invoicing_format_date(?string $date): string {
    if ($date === null || trim($date) === '') {
        return '';
    }
    $t = strtotime($date);

    return $t !== false ? date('M j, Y', $t) : '';
}
