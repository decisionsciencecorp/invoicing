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
 * Default Tasks directory folder for accounting docs (overridable per engagement).
 */
function dsc_tasks_default_directory_path(): string {
    if (function_exists('get_config')) {
        $from = get_config('tasks_accounting_directory_path');
        if (is_string($from) && trim($from) !== '') {
            return trim($from);
        }
    }
    $env = getenv('TASKS_ACCOUNTING_DIRECTORY_PATH');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return 'client-facing';
}

/**
 * Resolve Tasks project + directory for an engagement (falls back to global PSF defaults).
 *
 * @return array{project_id:int, directory_path:string}
 */
function dsc_tasks_source_for_engagement(SQLite3 $db, int $engagementId): array {
    $projectId = dsc_tasks_psf_project_id();
    $directory = dsc_tasks_default_directory_path();
    if ($engagementId > 0) {
        $st = $db->prepare(
            'SELECT tasks_project_id, tasks_directory_path FROM engagements WHERE id = :id LIMIT 1'
        );
        $st->bindValue(':id', $engagementId, SQLITE3_INTEGER);
        $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        if (is_array($row)) {
            if (isset($row['tasks_project_id']) && (int) $row['tasks_project_id'] > 0) {
                $projectId = (int) $row['tasks_project_id'];
            }
            $dir = trim((string) ($row['tasks_directory_path'] ?? ''));
            if ($dir !== '') {
                $directory = $dir;
            }
        }
    }
    return ['project_id' => $projectId, 'directory_path' => $directory];
}

/**
 * Time-log / accounting documents for invoice picker.
 *
 * @return list<array{id:int,title:string,directory_path:string}>
 */
function dsc_tasks_list_accounting_documents(?int $projectId = null, ?string $directoryPath = null): array {
    $projectId = $projectId ?? dsc_tasks_psf_project_id();
    $directoryPath = $directoryPath ?? dsc_tasks_default_directory_path();
    $cfg = dsc_tasks_api_config();
    if ($cfg['base_url'] === '' || $cfg['api_key'] === '') {
        return [];
    }
    require_once __DIR__ . '/tasks-live-http.php';
    return dsc_tasks_list_accounting_documents_live($projectId, $directoryPath);
}

/**
 * @return list<array{id:int,title:string,directory_path:string}>
 */
function dsc_tasks_list_accounting_documents_for_engagement(SQLite3 $db, int $engagementId): array {
    $src = dsc_tasks_source_for_engagement($db, $engagementId);
    return dsc_tasks_list_accounting_documents($src['project_id'], $src['directory_path']);
}

function dsc_tasks_admin_document_url(int $documentId): string {
    $cfg = dsc_tasks_api_config();
    $base = $cfg['base_url'] !== '' ? $cfg['base_url'] : 'https://tasks.decisionsciencecorp.com';
    return rtrim($base, '/') . '/admin/doc.php?id=' . rawurlencode((string) $documentId);
}
