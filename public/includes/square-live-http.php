<?php
/**
 * Live Square curl transport (excluded from PHPUnit coverage; exercised via mocks / sandbox group).
 *
 * @return array{ok:bool, status?:int, data?:array, error?:string}
 */
function dsc_invoicing_square_request_live(string $method, string $path, ?array $body = null): array {
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
