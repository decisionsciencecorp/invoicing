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
require_once __DIR__ . '/includes/markdown.php';

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

$company = (string) ($row['company_name'] ?? '');
$engagement = (string) ($row['engagement_name'] ?? '');
$anchor = (string) ($row['anchor_month'] ?? '');
$overageMonth = (string) ($row['overage_month'] ?? '');
$retainerCents = (int) ($row['retainer_amount_cents'] ?? 0);
$overageCents = (int) ($row['overage_amount_cents'] ?? 0);
$totalCents = (int) ($row['total_amount_cents'] ?? 0);
$retainerDue = (string) ($row['retainer_due_date'] ?? '');
$overageDue = (string) ($row['overage_due_date'] ?? '');
$retainerPayUrl = trim((string) ($row['retainer_public_url'] ?? ''));
$overagePayUrl = trim((string) ($row['overage_public_url'] ?? ''));
$retainerStatus = (string) ($row['retainer_payment_status'] ?? $row['payment_status'] ?? 'published');
$overageStatus = (string) ($row['overage_payment_status'] ?? '');
$docTitle = trim((string) ($row['tasks_document_title'] ?? ''));
$markdown = (string) ($row['accounting_markdown'] ?? '');
$canonical = dsc_billing_canonical_invoice_url($token);
$isFlat = (($row['billing_mode'] ?? '') === 'flat_tier');
$tierKey = (string) ($row['tier_key'] ?? 'tier1');
$feeDue = trim((string) ($row['fee_due_date'] ?? ''));
if ($feeDue === '' && $isFlat) {
    $feeDue = $retainerDue;
}

$cssHref = dsc_invoicing_href('assets/css/invoice-public.css') . '?v=2';
$logoHref = dsc_invoicing_href('assets/images/dsc-logo-white.svg');

header('Content-Type: text/html; charset=utf-8');
header('Link: <' . $canonical . '>; rel="canonical"');
?>
<!DOCTYPE html>
<html lang="en" class="inv-invoice">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($company !== '' ? $company . ' — Invoice' : 'Invoice', ENT_QUOTES, 'UTF-8') ?> — Decision Science Corp</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="inv-invoice">
<main class="invoice-page">
    <header class="invoice-brand">
        <div class="invoice-brand__lockup" aria-label="Decision Science Corp">
            <img class="invoice-brand__mark" src="<?= htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <span class="invoice-brand__text">Decision Science Corp</span>
        </div>
        <p class="invoice-brand__tag">Invoice</p>
    </header>

    <div class="invoice-hero">
        <p class="invoice-hero__eyebrow">Bill to</p>
        <h1><?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="invoice-hero__meta">
            <?= htmlspecialchars($engagement, ENT_QUOTES, 'UTF-8') ?>
            · Anchor month <code><?= htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') ?></code>
            <?php if ($isFlat): ?>
                · <?= htmlspecialchars(dsc_billing_tier_label($tierKey), ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($overageMonth !== ''): ?>
                · Overage from <code><?= htmlspecialchars($overageMonth, ENT_QUOTES, 'UTF-8') ?></code>
            <?php endif; ?>
        </p>
    </div>

    <div class="invoice-grid">
        <?php if ($retainerCents > 0): ?>
            <div class="pay-card <?= $retainerStatus === 'paid' ? 'paid' : '' ?>">
                <h3><?= $isFlat ? htmlspecialchars(dsc_billing_tier_label($tierKey) . ' program fee', ENT_QUOTES, 'UTF-8') : 'Monthly retainer' ?></h3>
                <div class="amount">$<?= number_format($retainerCents / 100, 2) ?></div>
                <?php if ($isFlat && $feeDue !== ''): ?>
                    <div class="due">Due: <?= htmlspecialchars($feeDue, ENT_QUOTES, 'UTF-8') ?> (net 30)</div>
                <?php elseif (!$isFlat && $retainerDue !== ''): ?>
                    <div class="due">Due: <?= htmlspecialchars($retainerDue, ENT_QUOTES, 'UTF-8') ?> (upon receipt)</div>
                <?php endif; ?>
                <span class="status-pill <?= htmlspecialchars($retainerStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($retainerStatus, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($retainerPayUrl !== '' && $retainerStatus !== 'paid'): ?>
                    <p class="pay-actions"><a class="btn" href="<?= htmlspecialchars($retainerPayUrl, ENT_QUOTES, 'UTF-8') ?>" rel="noopener"><?= $isFlat ? 'Pay via Square' : 'Pay retainer via Square' ?></a></p>
                <?php elseif ($retainerStatus === 'paid'): ?>
                    <p class="paid-note">Paid — thank you.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$isFlat && $overageCents > 0): ?>
            <div class="pay-card <?= $overageStatus === 'paid' ? 'paid' : '' ?>">
                <h3>Prior-month overage</h3>
                <div class="amount">$<?= number_format($overageCents / 100, 2) ?></div>
                <?php if ($overageDue !== ''): ?>
                    <div class="due">Due: <?= htmlspecialchars($overageDue, ENT_QUOTES, 'UTF-8') ?> (net 30)</div>
                <?php endif; ?>
                <span class="status-pill <?= htmlspecialchars($overageStatus !== '' ? $overageStatus : 'published', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($overageStatus !== '' ? $overageStatus : 'published', ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($overagePayUrl !== '' && $overageStatus !== 'paid'): ?>
                    <p class="pay-actions"><a class="btn" href="<?= htmlspecialchars($overagePayUrl, ENT_QUOTES, 'UTF-8') ?>" rel="noopener">Pay overage via Square</a></p>
                <?php elseif ($overageStatus === 'paid'): ?>
                    <p class="paid-note">Paid — thank you.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <p class="invoice-total">Total due: $<?= number_format($totalCents / 100, 2) ?></p>

    <?php if ($markdown !== ''): ?>
        <section class="breakdown">
            <h2>Accounting breakdown</h2>
            <?php if ($docTitle !== ''): ?>
                <p class="breakdown__source">From Tasks document: <?= htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <div class="markdown-body"><?= dsc_markdown_to_html($markdown) ?></div>
        </section>
    <?php endif; ?>

    <footer class="invoice-footer">
        Questions? Contact Decision Science Corp ·
        <a href="https://decisionsciencecorp.com/" rel="noopener">decisionsciencecorp.com</a>
    </footer>
</main>
</body>
</html>
