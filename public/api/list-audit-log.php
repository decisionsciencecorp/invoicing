<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/handlers/invoicing-crud-handlers.php';

initializeDatabase(false);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed. Use GET.', 405);
    exit;
}

$apiKey = getApiKey();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$result = runInvoicingApiListAuditLog($apiKey, $ip, $limit, $offset);

http_response_code($result['code']);
if ($result['success']) {
    jsonSuccess($result['data']);
} else {
    jsonError($result['error'], $result['code']);
}
