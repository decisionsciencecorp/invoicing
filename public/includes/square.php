<?php
/**
 * Square API — curl-based (Kitchen POS pattern).
 * Credentials: env > config table > optional .env repo root.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

function square_load_env_file(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $vars[trim($key)] = trim($val);
    }
    return $vars;
}

function square_config_reset(): void {
    unset($GLOBALS['_dsc_invoicing_square_config']);
}

function dsc_invoicing_square_config(): array {
    if (isset($GLOBALS['_dsc_invoicing_square_config'])) {
        return $GLOBALS['_dsc_invoicing_square_config'];
    }

    $token = getenv('SQUARE_ACCESS_TOKEN') ?: null;
    $env = getenv('SQUARE_ENVIRONMENT') ?: null;
    $locId = getenv('SQUARE_LOCATION_ID') ?: null;
    $appId = getenv('SQUARE_APPLICATION_ID') ?: null;

    if (function_exists('get_config')) {
        $token = $token ?: get_config('square_access_token');
        $env = $env ?: get_config('square_environment');
        $locId = $locId ?: get_config('square_location_id');
        $appId = $appId ?: get_config('square_application_id');
    }

    if (!$token && getenv('INVOICING_SQUARE_SKIP_ENV_FILE') !== '1') {
        $root = __DIR__ . '/../..';
        $envFile = ($env === 'production')
            ? "$root/.env.production"
            : "$root/.env.sandbox";
        if (!file_exists($envFile)) {
            $envFile = "$root/.env.sandbox";
        }
        $vars = square_load_env_file($envFile);
        $token = $token ?: ($vars['SQUARE_ACCESS_TOKEN'] ?? null);
        $env = $env ?: ($vars['SQUARE_ENVIRONMENT'] ?? 'sandbox');
        $locId = $locId ?: ($vars['SQUARE_LOCATION_ID'] ?? null);
        $appId = $appId ?: ($vars['SQUARE_APPLICATION_ID'] ?? null);
    }

    $env = $env ?: 'sandbox';
    $baseUrl = ($env === 'production')
        ? 'https://connect.squareup.com/v2'
        : 'https://connect.squareupsandbox.com/v2';

    $config = [
        'access_token' => $token,
        'environment' => $env,
        'location_id' => $locId,
        'application_id' => $appId,
        'base_url' => $baseUrl,
    ];
    $GLOBALS['_dsc_invoicing_square_config'] = $config;
    return $config;
}

function square_is_configured(): bool {
    $c = dsc_invoicing_square_config();
    return !empty($c['access_token']);
}

/**
 * @return array{ok:bool, status?:int, data?:array, error?:string}
 */
function dsc_invoicing_square_request(string $method, string $path, ?array $body = null): array {
    $cfg = dsc_invoicing_square_config();
    if (empty($cfg['access_token'])) {
        return ['ok' => false, 'status' => 0, 'error' => 'Square not configured (no access token)'];
    }

    $url = $cfg['base_url'] . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['access_token'],
            'Content-Type: application/json',
        ],
    ]);

    if ($method === 'GET') {
        // default
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl: ' . $curlErr];
    }

    $data = json_decode((string) $raw, true) ?: [];
    $ok = $status >= 200 && $status < 300;
    if (!$ok) {
        $errors = $data['errors'] ?? [];
        $msg = $errors ? json_encode($errors) : 'HTTP ' . $status;
        return ['ok' => false, 'status' => $status, 'error' => $msg, 'data' => $data];
    }
    return ['ok' => true, 'status' => $status, 'data' => $data];
}

// ---------------------------------------------------------------------------
// Webhooks — HMAC matches Kitchen POS (notification URL + raw body → base64(HMAC)).
// ---------------------------------------------------------------------------

/**
 * @return array{url:string, key:string}
 */
function dsc_invoicing_square_webhook_notification_config(): array {
    $url = getenv('SQUARE_WEBHOOK_NOTIFICATION_URL');
    $url = is_string($url) ? trim($url) : '';
    $key = getenv('SQUARE_WEBHOOK_SIGNATURE_KEY');
    $key = is_string($key) ? trim($key) : '';
    if ($url === '' && function_exists('get_config')) {
        $u = get_config('square_webhook_notification_url');
        $url = is_string($u) ? trim($u) : '';
    }
    if ($key === '' && function_exists('get_config')) {
        $k = get_config('square_webhook_signature_key');
        $key = is_string($k) ? trim($k) : '';
    }
    return ['url' => $url, 'key' => $key];
}

function dsc_invoicing_square_webhook_is_configured(): bool {
    $c = dsc_invoicing_square_webhook_notification_config();
    return $c['url'] !== '' && $c['key'] !== '';
}

function dsc_invoicing_square_webhook_compute_expected_signature(
    string $notificationUrl,
    string $rawBody,
    string $signatureKey,
): string {
    return base64_encode(hash_hmac('sha256', $notificationUrl . $rawBody, $signatureKey, true));
}

function dsc_invoicing_square_webhook_verify_signature(string $rawBody, string $signatureHeader): bool {
    $c = dsc_invoicing_square_webhook_notification_config();
    if ($c['key'] === '' || $c['url'] === '') {
        return false;
    }
    $sig = trim($signatureHeader);
    if ($sig === '') {
        return false;
    }
    $expected = dsc_invoicing_square_webhook_compute_expected_signature($c['url'], $rawBody, $c['key']);
    return hash_equals($expected, $sig);
}

/**
 * Find first Square invoice id (inv:…) inside nested payload.
 */
function dsc_invoicing_square_webhook_find_invoice_id(array $payload): ?string {
    $stack = [$payload];
    while ($stack !== []) {
        $cur = array_pop($stack);
        if (!is_array($cur)) {
            continue;
        }
        foreach ($cur as $k => $v) {
            if ($k === 'invoice_id' && is_string($v)) {
                $id = trim($v);
                if (str_starts_with($id, 'inv:')) {
                    return $id;
                }
            }
            if ($k === 'id' && is_string($v)) {
                $id = trim($v);
                if (str_starts_with($id, 'inv:')) {
                    return $id;
                }
            }
            if (is_array($v)) {
                $stack[] = $v;
            }
        }
    }
    return null;
}

/**
 * @return array{code:int, payload:array<string,mixed>}
 */
function dsc_invoicing_square_webhook_run(SQLite3 $db, string $rawBody, string $signatureHeader): array {
    if (!dsc_invoicing_square_webhook_verify_signature($rawBody, $signatureHeader)) {
        app_log('warning', 'Square webhook signature verify failed.');
        return ['code' => 401, 'payload' => ['success' => false, 'error' => 'Invalid signature.']];
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        return ['code' => 400, 'payload' => ['success' => false, 'error' => 'Invalid JSON.']];
    }
    $type = isset($payload['type']) ? (string) $payload['type'] : '';
    if ($type === 'invoice.payment_made') {
        $invId = dsc_invoicing_square_webhook_find_invoice_id($payload);
        if ($invId === null) {
            return ['code' => 200, 'payload' => ['success' => true, 'ignored' => 'no_invoice_id']];
        }
        $sel = $db->prepare(
            'SELECT id FROM outbound_invoices WHERE square_invoice_id = :i '
            . 'OR square_retainer_invoice_id = :i OR square_overage_invoice_id = :i LIMIT 1'
        );
        $sel->bindValue(':i', $invId, SQLITE3_TEXT);
        $r = $sel->execute();
        $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : false;
        if (!$row || empty($row['id'])) {
            app_log('info', 'Square invoice.payment_made for unknown invoice ' . $invId);
            return ['code' => 200, 'payload' => ['success' => true, 'ignored' => 'unknown_invoice', 'invoice_id' => $invId]];
        }
        $refresh = dsc_billing_refresh_outbound_payment_status($db, (int) $row['id']);
        app_log('info', 'Square invoice.payment_made reconciled for ' . $invId . ' outbound #' . (int) $row['id']);
        return [
            'code' => 200,
            'payload' => [
                'success' => true,
                'invoice_id' => $invId,
                'outbound_id' => (int) $row['id'],
                'payment_status' => $refresh['payment_status'] ?? 'paid',
            ],
        ];
    }
    return ['code' => 200, 'payload' => ['success' => true, 'ignored' => 'event_type', 'type' => $type]];
}
