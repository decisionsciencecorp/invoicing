<?php
declare(strict_types=1);

/**
 * Shared client invoice page body (published token page + admin draft preview).
 *
 * @param array<string,mixed> $view
 */
function dsc_invoice_render_page(array $view): void {
    require_once __DIR__ . '/markdown.php';
    require_once __DIR__ . '/billing.php';

    $company = (string) ($view['company_name'] ?? '');
    $engagement = (string) ($view['engagement_name'] ?? '');
    $anchor = (string) ($view['anchor_month'] ?? '');
    $overageMonth = (string) ($view['overage_month'] ?? '');
    $retainerCents = (int) ($view['retainer_amount_cents'] ?? 0);
    $overageCents = (int) ($view['overage_amount_cents'] ?? 0);
    $totalCents = (int) ($view['total_amount_cents'] ?? ($retainerCents + $overageCents));
    $retainerDue = (string) ($view['retainer_due_date'] ?? '');
    $overageDue = (string) ($view['overage_due_date'] ?? '');
    $retainerPayUrl = trim((string) ($view['retainer_public_url'] ?? ''));
    $overagePayUrl = trim((string) ($view['overage_public_url'] ?? ''));
    $retainerStatus = (string) ($view['retainer_payment_status'] ?? $view['payment_status'] ?? 'published');
    $overageStatus = (string) ($view['overage_payment_status'] ?? '');
    $docTitle = trim((string) ($view['tasks_document_title'] ?? ''));
    $markdown = (string) ($view['accounting_markdown'] ?? '');
    $isFlat = (($view['billing_mode'] ?? '') === 'flat_tier');
    $tierKey = (string) ($view['tier_key'] ?? 'tier1');
    $feeDue = trim((string) ($view['fee_due_date'] ?? ''));
    if ($feeDue === '' && $isFlat) {
        $feeDue = $retainerDue;
    }
    $isDraft = !empty($view['is_draft']);
    $priorHours = isset($view['prior_month_hours']) ? (float) $view['prior_month_hours'] : null;
    $overageHours = isset($view['overage_hours']) ? (float) $view['overage_hours'] : null;
    $includedHours = (int) ($view['included_hours_per_month'] ?? 0);
    $rateCents = (int) ($view['hourly_rate_cents'] ?? 0);

    $cssHref = dsc_invoicing_href('assets/css/invoice-public.css') . '?v=3';
    $logoHref = dsc_invoicing_href('assets/images/dsc-logo-white.svg');
    $pageTitle = $company !== '' ? $company . ' — Invoice' : 'Invoice';
    if ($isDraft) {
        $pageTitle .= ' (draft)';
    }
    ?>
<!DOCTYPE html>
<html lang="en" class="inv-invoice">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — Decision Science Corp</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="inv-invoice<?= $isDraft ? ' inv-invoice--draft' : '' ?>">
<main class="invoice-page">
    <?php if ($isDraft): ?>
        <?php
        $adminBar = is_array($view['draft_admin_bar'] ?? null) ? $view['draft_admin_bar'] : null;
        ?>
        <p class="invoice-draft-banner" role="status">
            Draft preview — not published. No Square payment links. Nothing has been sent to the client.
            <?php if ($adminBar !== null): ?>
                <br>
                <a href="<?= htmlspecialchars((string) ($adminBar['drafts_href'] ?? 'invoices.php?tab=drafts'), ENT_QUOTES, 'UTF-8') ?>">← Back to Drafts</a>
                ·
                <a href="<?= htmlspecialchars((string) ($adminBar['publish_href'] ?? 'invoices.php?tab=publish'), ENT_QUOTES, 'UTF-8') ?>">Publish tab</a>
                <?php if (!empty($adminBar['saved'])): ?>
                    · Saved — reopen anytime from <strong>Invoices → Drafts</strong>
                <?php endif; ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <header class="invoice-brand">
        <div class="invoice-brand__lockup" aria-label="Decision Science Corp">
            <img class="invoice-brand__mark" src="<?= htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <span class="invoice-brand__text">Decision Science Corp</span>
        </div>
        <p class="invoice-brand__tag"><?= $isDraft ? 'Invoice draft' : 'Invoice' ?></p>
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

    <?php if (!$isFlat && $priorHours !== null && $rateCents > 0): ?>
        <section class="breakdown" style="margin-bottom:1.25rem;">
            <h2>Hours basis (draft)</h2>
            <div class="markdown-body">
                <ul>
                    <li>Prior month (<code><?= htmlspecialchars($overageMonth, ENT_QUOTES, 'UTF-8') ?></code>) logged:
                        <strong><?= htmlspecialchars(number_format($priorHours, 2), ENT_QUOTES, 'UTF-8') ?> h</strong></li>
                    <li>Included retainer hours:
                        <strong><?= (int) $includedHours ?> h</strong>
                        @ $<?= number_format($rateCents / 100, 2) ?>/hr
                        → retainer <strong>$<?= number_format($retainerCents / 100, 2) ?></strong></li>
                    <li>Billable overage:
                        <strong><?= htmlspecialchars(number_format((float) $overageHours, 2), ENT_QUOTES, 'UTF-8') ?> h</strong>
                        → <strong>$<?= number_format($overageCents / 100, 2) ?></strong></li>
                </ul>
            </div>
        </section>
    <?php endif; ?>

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
                <?php elseif ($isDraft): ?>
                    <p class="paid-note">Pay link appears after publish.</p>
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
                <?php elseif ($isDraft): ?>
                    <p class="paid-note">Pay link appears after publish.</p>
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
    <?php elseif ($isDraft && !$isFlat): ?>
        <section class="breakdown">
            <h2>Accounting breakdown</h2>
            <p class="breakdown__source">Select a Tasks time-log document on the Publish tab to preview the client memo here.</p>
        </section>
    <?php endif; ?>

    <?php
    $brand = function_exists('dsc_invoicing_invoice_brand')
        ? dsc_invoicing_invoice_brand()
        : ['name' => 'Decision Science Corp', 'url' => 'https://decisionsciencecorp.com/'];
    $brandName = (string) ($brand['name'] ?? 'Decision Science Corp');
    $brandUrl = trim((string) ($brand['url'] ?? ''));
    ?>
    <footer class="invoice-footer">
        Questions? Contact <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?>
        <?php if ($brandUrl !== ''): ?>
            · <a href="<?= htmlspecialchars($brandUrl, ENT_QUOTES, 'UTF-8') ?>" rel="noopener"><?= htmlspecialchars(preg_replace('#^https?://#', '', $brandUrl) ?: $brandUrl, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endif; ?>
    </footer>
</main>
</body>
</html>
    <?php
}
