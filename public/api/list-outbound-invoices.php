<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/handlers/invoicing-crud-handlers.php';

initializeDatabase();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed. Use GET.', 405);
    exit;
}

$eng = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$engagementId = $eng > 0 ? $eng : null;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

$apiKey = getApiKey();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$result = runInvoicingApiListOutboundInvoices($apiKey, $ip, $engagementId, $limit, $offset);

http_response_code($result['code']);
if ($result['success']) {
    jsonSuccess($result['data']);
} else {
    jsonError($result['error'], $result['code']);
}
