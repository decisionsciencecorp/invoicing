<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$section = strtolower(trim((string) ($_GET['section'] ?? 'overview')));
$sections = [
    'overview' => 'Overview',
    'publish' => 'Publishing invoices',
    'flat-tier' => 'Flat / tier billing',
    'client-page' => 'Client invoice page',
    'webhooks' => 'Square webhooks',
    'api' => 'HTTP API',
    'dev' => 'Dev vs production',
];
if (!isset($sections[$section])) {
    $section = 'overview';
}

$adminPageTitle = 'Help';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1>Help</h1>
    <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrfField() ?>
        <button type="submit" class="btn">Logout</button>
    </form>
</div>

<div class="inv-help-layout" style="display:grid;grid-template-columns:minmax(10rem,14rem) 1fr;gap:1.25rem;align-items:start;">
    <nav class="info-box" aria-label="Help topics" style="padding:.75rem;">
        <ul style="list-style:none;margin:0;padding:0;">
            <?php foreach ($sections as $key => $label): ?>
                <li style="margin:0 0 .35rem;">
                    <a href="?section=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                       style="text-decoration:none;<?= $section === $key ? 'font-weight:600;color:#e6edf3;' : 'color:#8b949e;' ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <article class="info-box inv-help-body">
        <?php if ($section === 'overview'): ?>
            <h2 style="margin-top:0;">What this app does</h2>
            <p>DSC Invoicing publishes professional-services invoices through Square and keeps a local record for admin, AR, and client share links.</p>
            <ul>
                <li><strong>Companies / engagements</strong> — who you bill and how (hourly retainer+overage or flat/tier).</li>
                <li><strong>Time</strong> — optional local time entries; hourly invoices still require a Tasks accounting document.</li>
                <li><strong>Invoices</strong> — publish, list, unpaid/AR, refresh status, cancel unpaid Square invoices.</li>
                <li><strong>Settings</strong> — users, API keys, Square + webhooks, audit log.</li>
            </ul>
        <?php elseif ($section === 'publish'): ?>
            <h2 style="margin-top:0;">Publishing invoices</h2>
            <ol>
                <li>Open <strong>Invoices → Publish</strong>.</li>
                <li>Pick an active engagement and billing month.</li>
                <li><strong>Hourly:</strong> select a Tasks time-log / accounting document (required). The markdown becomes the client page body.</li>
                <li><strong>Flat/tier:</strong> pick Tier 1 or Tier 2; accounting doc is optional.</li>
                <li>Confirm the preview totals, then publish. Square gets the payment invoice(s); the client gets a tokenized page.</li>
            </ol>
            <p>Do not re-publish a month that already has a paid Square invoice for that engagement.</p>
        <?php elseif ($section === 'flat-tier'): ?>
            <h2 style="margin-top:0;">Flat / tier billing</h2>
            <p>On the engagement, set billing mode to <strong>Flat / tier monthly fee</strong> and enter Tier 1 / Tier 2 amounts. At publish you choose which tier to bill. Due date is Net 30 from publish.</p>
        <?php elseif ($section === 'client-page'): ?>
            <h2 style="margin-top:0;">Client invoice page</h2>
            <p>Share link shape: <code>/invoice.php?t=&lt;token&gt;</code>. When the invoice is unpaid, the page refreshes status from Square on load so clients see paid after they pay. Public access is rate-limited liberally per IP/token.</p>
        <?php elseif ($section === 'webhooks'): ?>
            <h2 style="margin-top:0;">Square webhooks</h2>
            <p>Configure the notification URL and signature key under Settings → Square. Subscribe to invoice payment/update/cancel events. Deliveries refresh local status and appear in the audit log. See Settings → Webhooks for the live endpoint checklist.</p>
        <?php elseif ($section === 'api'): ?>
            <h2 style="margin-top:0;">HTTP API</h2>
            <p>JSON endpoints live under <code>/api/*.php</code>. Authenticate with <code>X-API-Key</code> or Bearer. Full reference: <code>docs/api.md</code> in the repo. Python SDK: <code>invoicing_sdk/</code>; SMCP plugin: <code>smcp_plugin/invoicing/</code>.</p>
        <?php else: ?>
            <h2 style="margin-top:0;">Dev vs production</h2>
            <ul>
                <li><strong>Production</strong> — <code>invoicing.decisionsciencecorp.com</code>, Square production.</li>
                <li><strong>Dev</strong> — <code>dev.invoicing.decisionsciencecorp.com</code>, branch <code>dev</code>, Square sandbox. After copying a prod DB, remap Square customer ids to sandbox customers before publishing test invoices.</li>
            </ul>
        <?php endif; ?>
    </article>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
