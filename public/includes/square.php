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
    // PHPUnit / local mocks — never used in production unless INVOICING_SQUARE_MOCK=1.
    if (getenv('INVOICING_SQUARE_MOCK') === '1'
        && isset($GLOBALS['_dsc_square_mock_handler'])
        && is_callable($GLOBALS['_dsc_square_mock_handler'])
    ) {
        /** @var callable $handler */
        $handler = $GLOBALS['_dsc_square_mock_handler'];
        return $handler($method, $path, $body);
    }

    require_once __DIR__ . '/square-live-http.php';
    return dsc_invoicing_square_request_live($method, $path, $body);
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
/**
 * Invoice events that should refresh local outbound payment/lifecycle status.
 *
 * @return list<string>
 */
function dsc_invoicing_square_webhook_refresh_event_types(): array {
    return [
        'invoice.payment_made',
        'invoice.updated',
        'invoice.scheduled',
        'invoice.canceled',
        'invoice.refunded',
        'invoice.payment_made.v2',
    ];
}

function dsc_invoicing_square_webhook_run(SQLite3 $db, string $rawBody, string $signatureHeader): array {
    if (!function_exists('dsc_invoicing_audit_log')) {
        require_once __DIR__ . '/audit.php';
    }
    if (!dsc_invoicing_square_webhook_verify_signature($rawBody, $signatureHeader)) {
        app_log('warning', 'Square webhook signature verify failed.');
        dsc_invoicing_audit_log('square.webhook.bad_signature', 'webhook', null, null, null, 'warning');
        return ['code' => 401, 'payload' => ['success' => false, 'error' => 'Invalid signature.']];
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        dsc_invoicing_audit_log('square.webhook.bad_json', 'webhook', null, null, null, 'warning');
        return ['code' => 400, 'payload' => ['success' => false, 'error' => 'Invalid JSON.']];
    }
    $type = isset($payload['type']) ? (string) $payload['type'] : '';
    if (in_array($type, dsc_invoicing_square_webhook_refresh_event_types(), true)) {
        $invId = dsc_invoicing_square_webhook_find_invoice_id($payload);
        if ($invId === null) {
            dsc_invoicing_audit_log('square.webhook.ignored', 'webhook', 'event', $type, ['reason' => 'no_invoice_id']);
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
            app_log('info', 'Square ' . $type . ' for unknown invoice ' . $invId);
            dsc_invoicing_audit_log(
                'square.webhook.unknown_invoice',
                'webhook',
                'square_invoice',
                $invId,
                ['type' => $type]
            );
            return ['code' => 200, 'payload' => ['success' => true, 'ignored' => 'unknown_invoice', 'invoice_id' => $invId]];
        }
        $refresh = dsc_billing_refresh_outbound_payment_status($db, (int) $row['id']);
        app_log('info', 'Square ' . $type . ' reconciled for ' . $invId . ' outbound #' . (int) $row['id']);
        dsc_invoicing_audit_log(
            'square.webhook.refresh',
            'webhook',
            'outbound_invoice',
            (string) (int) $row['id'],
            [
                'type' => $type,
                'square_invoice_id' => $invId,
                'payment_status' => $refresh['payment_status'] ?? null,
                'ok' => !empty($refresh['ok']),
            ]
        );
        return [
            'code' => 200,
            'payload' => [
                'success' => true,
                'type' => $type,
                'invoice_id' => $invId,
                'outbound_id' => (int) $row['id'],
                'payment_status' => $refresh['payment_status'] ?? null,
            ],
        ];
    }
    dsc_invoicing_audit_log('square.webhook.ignored', 'webhook', 'event', $type, ['reason' => 'event_type']);
    return ['code' => 200, 'payload' => ['success' => true, 'ignored' => 'event_type', 'type' => $type]];
}
