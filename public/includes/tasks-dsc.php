<?php
/**
 * Fetch accounting documents from tasks.decisionsciencecorp.com (read-only).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * @return array{base_url:string, api_key:string}
 */
function dsc_tasks_api_config(): array {
    $base = getenv('TASKS_DSC_BASE_URL');
    $key = getenv('TASKS_DSC_OTTOVERNAL_API_KEY');
    if ((!is_string($base) || trim($base) === '') && function_exists('get_config')) {
        $from = get_config('tasks_dsc_base_url');
        $base = is_string($from) ? $from : '';
    }
    if ((!is_string($key) || trim($key) === '') && function_exists('get_config')) {
        $from = get_config('tasks_dsc_api_key');
        $key = is_string($from) ? $from : '';
    }
    return [
        'base_url' => rtrim(trim((string) $base), '/'),
        'api_key' => trim((string) $key),
    ];
}

function dsc_tasks_api_is_configured(): bool {
    $c = dsc_tasks_api_config();
    return $c['base_url'] !== '' && $c['api_key'] !== '';
}

/**
 * @return array{ok:bool, document?:array{id:int,title:string,body:string,project_id?:int}, error?:string}
 */
function dsc_tasks_fetch_document(int $documentId): array {
    if ($documentId <= 0) {
        return ['ok' => false, 'error' => 'Invalid document id.'];
    }
    $cfg = dsc_tasks_api_config();
    if ($cfg['base_url'] === '' || $cfg['api_key'] === '') {
        return ['ok' => false, 'error' => 'Tasks API not configured (base URL + API key).'];
    }
    $url = $cfg['base_url'] . '/api/get-document.php?id=' . rawurlencode((string) $documentId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . $cfg['api_key'],
            'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) {
        return ['ok' => false, 'error' => 'Tasks API curl: ' . $curlErr];
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Tasks API returned invalid JSON.'];
    }
    if ($status === 404 || empty($data['success'])) {
        $err = $data['error'] ?? $data['message'] ?? ('HTTP ' . $status);
        return ['ok' => false, 'error' => 'Tasks document not found or denied: ' . (is_string($err) ? $err : json_encode($err))];
    }
    $doc = $data['document'] ?? ($data['data']['document'] ?? null);
    if (!is_array($doc)) {
        return ['ok' => false, 'error' => 'Tasks API response missing document payload.'];
    }
    $id = (int) ($doc['id'] ?? 0);
    $title = trim((string) ($doc['title'] ?? ''));
    $body = (string) ($doc['body'] ?? '');
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Tasks document payload missing id.'];
    }
    return [
        'ok' => true,
        'document' => [
            'id' => $id,
            'title' => $title !== '' ? $title : ('Document #' . $id),
            'body' => $body,
            'project_id' => isset($doc['project_id']) ? (int) $doc['project_id'] : null,
        ],
    ];
}
