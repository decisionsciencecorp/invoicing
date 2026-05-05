<?php
/**
 * Square API — curl-based (Kitchen POS pattern).
 * Credentials: env > config table > optional .env repo root.
 */

require_once __DIR__ . '/database.php';

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
