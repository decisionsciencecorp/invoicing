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
    // PHPUnit / local mocks — never used in production unless INVOICING_TASKS_MOCK=1.
    if (getenv('INVOICING_TASKS_MOCK') === '1'
        && isset($GLOBALS['_dsc_tasks_mock_handler'])
        && is_callable($GLOBALS['_dsc_tasks_mock_handler'])
    ) {
        /** @var callable $handler */
        $handler = $GLOBALS['_dsc_tasks_mock_handler'];
        return $handler($documentId);
    }
    require_once __DIR__ . '/tasks-live-http.php';
    return dsc_tasks_fetch_document_live($documentId);
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
    require_once __DIR__ . '/tasks-live-http.php';
    return dsc_tasks_list_accounting_documents_live($projectId);
}

function dsc_tasks_admin_document_url(int $documentId): string {
    $cfg = dsc_tasks_api_config();
    $base = $cfg['base_url'] !== '' ? $cfg['base_url'] : 'https://tasks.decisionsciencecorp.com';
    return rtrim($base, '/') . '/admin/doc.php?id=' . rawurlencode((string) $documentId);
}
