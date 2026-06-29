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

/** ProSpikeFlow Work directory project id on Tasks (accounting docs). */
function dsc_tasks_psf_project_id(): int {
    if (function_exists('get_config')) {
        $from = get_config('tasks_psf_project_id');
        if (is_string($from) && ctype_digit(trim($from))) {
            return (int) trim($from);
        }
    }
    $env = getenv('TASKS_PSF_PROJECT_ID');
    if (is_string($env) && ctype_digit(trim($env))) {
        return (int) trim($env);
    }
    return 4;
}

/**
 * Time-log / accounting documents for invoice picker (ProSpikeFlow client-facing folder).
 *
 * @return list<array{id:int,title:string,directory_path:string}>
 */
function dsc_tasks_list_accounting_documents(?int $projectId = null): array {
    $projectId = $projectId ?? dsc_tasks_psf_project_id();
    $cfg = dsc_tasks_api_config();
    if ($cfg['base_url'] === '' || $cfg['api_key'] === '') {
        return [];
    }
    $url = $cfg['base_url'] . '/api/list-documents.php?project_id=' . rawurlencode((string) $projectId)
        . '&directory_path=' . rawurlencode('client-facing') . '&limit=200';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . $cfg['api_key'], 'Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return [];
    }
    $docs = $data['documents'] ?? ($data['data']['documents'] ?? []);
    if (!is_array($docs)) {
        return [];
    }
    $out = [];
    foreach ($docs as $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $title = trim((string) ($doc['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        if (!preg_match('/time\s*log|accounting|hours\s*log/i', $title)) {
            continue;
        }
        $out[] = [
            'id' => (int) ($doc['id'] ?? 0),
            'title' => $title,
            'directory_path' => (string) ($doc['directory_path'] ?? ''),
        ];
    }
    usort($out, static fn ($a, $b) => ($b['id'] <=> $a['id']));
    return $out;
}

function dsc_tasks_admin_document_url(int $documentId): string {
    $cfg = dsc_tasks_api_config();
    $base = $cfg['base_url'] !== '' ? $cfg['base_url'] : 'https://tasks.decisionsciencecorp.com';
    return rtrim($base, '/') . '/admin/doc.php?id=' . rawurlencode((string) $documentId);
}
