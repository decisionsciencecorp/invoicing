<?php
declare(strict_types=1);

/**
 * Admin-only draft invoice preview — same layout as the client page, no Square.
 * GET /admin/invoice-draft.php?engagement_id=&anchor_month=&tasks_document_id=&tier_key=
 * Autosaves to invoice_drafts so Invoices → Drafts can reopen it.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/invoice-page-view.php';
requireAuth();

$db = getDbConnection();
dsc_billing_prune_published_drafts($db);

$engagementId = (int) ($_GET['engagement_id'] ?? 0);
$anchorMonth = trim((string) ($_GET['anchor_month'] ?? ''));
$tasksDocId = (int) ($_GET['tasks_document_id'] ?? 0);
$tierKey = trim((string) ($_GET['tier_key'] ?? 'tier1'));

if ($engagementId <= 0 || !dsc_billing_valid_month($anchorMonth)) {
    header('Location: ' . dsc_invoicing_href('admin/invoices.php?tab=drafts'));
    exit;
}

// Already published → send to the live client page / list, don't recreate a draft.
if (dsc_billing_outbound_exists_for_anchor($db, $engagementId, $anchorMonth)) {
    $st = $db->prepare(
        'SELECT public_token, public_url FROM outbound_invoices WHERE engagement_id = :e '
        . 'AND (anchor_month = :a OR anchor_month LIKE :apref) ORDER BY id DESC LIMIT 1'
    );
    $st->bindValue(':e', $engagementId, SQLITE3_INTEGER);
    $st->bindValue(':a', $anchorMonth, SQLITE3_TEXT);
    $st->bindValue(':apref', $anchorMonth . '-%', SQLITE3_TEXT);
    $pub = $st->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
    $clientUrl = $pub ? dsc_billing_client_page_url($pub) : '';
    if ($clientUrl !== '') {
        header('Location: ' . $clientUrl);
        exit;
    }
    header('Location: ' . dsc_invoicing_href('admin/invoices.php?tab=list'));
    exit;
}

$saved = dsc_billing_upsert_invoice_draft(
    $db,
    $engagementId,
    $anchorMonth,
    $tasksDocId > 0 ? $tasksDocId : null,
    $tierKey !== '' ? $tierKey : 'tier1'
);

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

$draftsHref = dsc_invoicing_href('admin/invoices.php?tab=drafts');
$publishQs = http_build_query(array_filter([
    'tab' => 'publish',
    'engagement_id' => $engagementId,
    'anchor_month' => $anchorMonth,
    'tasks_document_id' => $tasksDocId > 0 ? $tasksDocId : null,
    'tier_key' => $tierKey,
], static fn ($v) => $v !== null && $v !== ''));
$publishHref = dsc_invoicing_href('admin/invoices.php?' . $publishQs);

$view = $built['view'];
$view['is_draft'] = true;
$view['draft_admin_bar'] = [
    'drafts_href' => $draftsHref,
    'publish_href' => $publishHref,
    'saved' => !empty($saved['ok']),
    'draft_id' => (int) ($saved['draft_id'] ?? 0),
];

header('Content-Type: text/html; charset=utf-8');
dsc_invoice_render_page($view);
