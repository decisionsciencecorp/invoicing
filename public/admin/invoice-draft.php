<?php
declare(strict_types=1);

/**
 * Admin-only draft invoice preview — same layout as the client page, no Square.
 * GET /admin/invoice-draft.php?engagement_id=&anchor_month=&tasks_document_id=&tier_key=
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/invoice-page-view.php';
requireAuth();

$db = getDbConnection();

$engagementId = (int) ($_GET['engagement_id'] ?? 0);
$anchorMonth = trim((string) ($_GET['anchor_month'] ?? ''));
$tasksDocId = (int) ($_GET['tasks_document_id'] ?? 0);
$tierKey = trim((string) ($_GET['tier_key'] ?? 'tier1'));

if ($engagementId <= 0 || !dsc_billing_valid_month($anchorMonth)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Pick an engagement and billing month on Invoices → Publish, then open View draft details.';
    exit;
}

$built = dsc_billing_build_draft_invoice_view(
    $db,
    $engagementId,
    $anchorMonth,
    $tasksDocId > 0 ? $tasksDocId : null,
    $tierKey !== '' ? $tierKey : 'tier1'
);

if (empty($built['ok'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo (string) ($built['error'] ?? 'Could not build draft.');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
dsc_invoice_render_page($built['view']);
