<?php
/**
 * Live Tasks API curl (excluded from PHPUnit coverage; exercised via INVOICING_TASKS_MOCK).
 *
 * @return array{ok:bool, document?:array{id:int,title:string,body:string,project_id?:int}, error?:string}
 */
function dsc_tasks_fetch_document_live(int $documentId): array {
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

/**
 * @return list<array{id:int,title:string,directory_path:string}>
 */
function dsc_tasks_list_accounting_documents_live(int $projectId, ?string $directoryPath = null): array {
    $cfg = dsc_tasks_api_config();
    if ($cfg['base_url'] === '' || $cfg['api_key'] === '') {
        return [];
    }
    $directoryPath = trim((string) ($directoryPath ?? 'client-facing'));
    if ($directoryPath === '') {
        $directoryPath = 'client-facing';
    }
    $url = $cfg['base_url'] . '/api/list-documents.php?project_id=' . rawurlencode((string) $projectId)
        . '&directory_path=' . rawurlencode($directoryPath) . '&limit=200';
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
