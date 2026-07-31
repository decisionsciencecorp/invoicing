<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/handlers/invoicing-crud-handlers.php';

initializeDatabase(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed. Use POST.', 405);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw !== false ? $raw : '', true);
if (!is_array($data)) {
    $data = $_POST;
}
$apiKey = dsc_invoicing_resolve_api_key(is_array($data) ? $data : null) ?? getApiKey();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$result = runInvoicingApiRefreshOutboundInvoice($apiKey, $ip, is_array($data) ? $data : []);

http_response_code($result['code']);
if ($result['success']) {
    jsonSuccess($result['data']);
} else {
    jsonError($result['error'], $result['code']);
}
