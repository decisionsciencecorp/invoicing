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

$companyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : 0;
$c = $companyId > 0 ? $companyId : null;
$apiKey = getApiKey();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$result = runInvoicingApiListEngagements($apiKey, $ip, $c);

http_response_code($result['code']);
if ($result['success']) {
    jsonSuccess($result['data']);
} else {
    jsonError($result['error'], $result['code']);
}
