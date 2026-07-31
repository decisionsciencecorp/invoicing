<?php

declare(strict_types=1);

/**
 * POST /api/square-webhook.php — Square Notifications (invoices: invoice.payment_made).
 * Authenticates with Square HMAC only — not admin session.
 */

defined('DSC_INVOICING_SKIP_SESSION') || define('DSC_INVOICING_SKIP_SESSION', true);

require_once __DIR__ . '/../includes/config.php';

initializeDatabase(false);

require_once __DIR__ . '/../includes/square.php';
require_once __DIR__ . '/../includes/billing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw)) {
    $raw = '';
}

$sig = '';
if (!empty($_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'])) {
    $sig = trim((string) $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE']);
} elseif (function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $name => $value) {
        if (strcasecmp((string) $name, 'x-square-hmacsha256-signature') === 0) {
            $sig = is_string($value) ? trim($value) : '';
            break;
        }
    }
}

$db = getDbConnection();
$result = dsc_invoicing_square_webhook_run($db, $raw, $sig);

header('Content-Type: application/json; charset=utf-8');
http_response_code($result['code']);
echo json_encode($result['payload']);
