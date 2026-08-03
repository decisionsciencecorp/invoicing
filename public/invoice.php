<?php
declare(strict_types=1);

/**
 * Public client invoice breakdown — canonical share link (no admin session).
 * GET /invoice.php?t=<public_token>
 *
 * Branding is fixed DSC (marketing) — not admin Appearance skins.
 */

defined('DSC_INVOICING_SKIP_SESSION') || define('DSC_INVOICING_SKIP_SESSION', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/invoice-page-view.php';

initializeDatabase();

$token = trim((string) ($_GET['t'] ?? ''));
if ($token === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing invoice token.';
    exit;
}

// Liberal public rate limits (abuse brake, not harsh UX).
require_once __DIR__ . '/includes/functions.php';
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$tokenBucket = substr(hash('sha256', $token), 0, 16);
if (!checkRateLimit('public_invoice:ip:' . $clientIp, 180, 60)
    || !checkRateLimit('public_invoice:tok:' . $tokenBucket . ':' . $clientIp, 90, 60)
) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 60');
    echo 'Too many requests. Please try again in a minute.';
    exit;
}

$db = getDbConnection();
$row = dsc_billing_get_outbound_by_public_token($db, $token);
if ($row === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invoice not found.';
    exit;
}

// Keep client page honest after Square pay: refresh unpaid/partial rows on load.
// Fail soft — show last known local status if Square is unreachable.
if (dsc_billing_outbound_needs_payment_refresh($row)) {
    $refresh = dsc_billing_refresh_outbound_payment_status($db, (int) ($row['id'] ?? 0));
    if (!empty($refresh['ok'])) {
        $reloaded = dsc_billing_get_outbound_by_public_token($db, $token);
        if (is_array($reloaded)) {
            $row = $reloaded;
        }
    }
}

$canonical = dsc_billing_canonical_invoice_url($token);
$view = [
    'is_draft' => false,
    'company_name' => (string) ($row['company_name'] ?? ''),
    'engagement_name' => (string) ($row['engagement_name'] ?? ''),
    'anchor_month' => (string) ($row['anchor_month'] ?? ''),
    'overage_month' => (string) ($row['overage_month'] ?? ''),
    'retainer_amount_cents' => (int) ($row['retainer_amount_cents'] ?? 0),
    'overage_amount_cents' => (int) ($row['overage_amount_cents'] ?? 0),
    'total_amount_cents' => (int) ($row['total_amount_cents'] ?? 0),
    'retainer_due_date' => (string) ($row['retainer_due_date'] ?? ''),
    'overage_due_date' => (string) ($row['overage_due_date'] ?? ''),
    'fee_due_date' => (string) ($row['fee_due_date'] ?? ''),
    'retainer_public_url' => trim((string) ($row['retainer_public_url'] ?? '')),
    'overage_public_url' => trim((string) ($row['overage_public_url'] ?? '')),
    'retainer_payment_status' => (string) ($row['retainer_payment_status'] ?? $row['payment_status'] ?? 'published'),
    'overage_payment_status' => (string) ($row['overage_payment_status'] ?? ''),
    'tasks_document_title' => trim((string) ($row['tasks_document_title'] ?? '')),
    'accounting_markdown' => (string) ($row['accounting_markdown'] ?? ''),
    'billing_mode' => (string) ($row['billing_mode'] ?? ''),
    'tier_key' => (string) ($row['tier_key'] ?? 'tier1'),
];

header('Content-Type: text/html; charset=utf-8');
header('Link: <' . $canonical . '>; rel="canonical"');
dsc_invoice_render_page($view);
