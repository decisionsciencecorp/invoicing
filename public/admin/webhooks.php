<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/square.php';
requireAuth();

$cfg = dsc_invoicing_square_webhook_notification_config();
$configured = dsc_invoicing_square_webhook_is_configured();
$events = dsc_invoicing_square_webhook_refresh_event_types();
$endpoint = dsc_invoicing_public_base_url() . dsc_invoicing_href('api/square-webhook.php');

$adminPageTitle = 'Webhooks';
require_once __DIR__ . '/includes/header.php';
?>

<div class="nav-row">
    <h1>Webhooks</h1>
    <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrfField() ?>
        <button type="submit" class="btn">Logout</button>
    </form>
</div>

<p style="color:#8b949e;">
    Square pushes invoice lifecycle events here so admin status stays fresh without waiting for a client to open their link.
    Signature key and notification URL are edited under
    <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/config.php'), ENT_QUOTES, 'UTF-8') ?>">Settings → Square</a>.
</p>

<div class="info-box">
    <h2 style="margin-top:0;">Receiver</h2>
    <p><strong>Status:</strong>
        <?php if ($configured): ?>
            <span style="color:#3fb950;">Configured</span>
        <?php else: ?>
            <span style="color:#f85149;">Not configured</span> — set webhook signature key + notification URL on Square settings.
        <?php endif; ?>
    </p>
    <p><strong>Endpoint:</strong> <code><?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?></code></p>
    <p><strong>Configured notification URL:</strong>
        <code><?= htmlspecialchars((string) ($cfg['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
    </p>
    <h3 style="margin-top:1.25rem;">Events that refresh local status</h3>
    <ul>
        <?php foreach ($events as $ev): ?>
            <li><code><?= htmlspecialchars($ev, ENT_QUOTES, 'UTF-8') ?></code></li>
        <?php endforeach; ?>
    </ul>
    <p style="color:#8b949e;font-size:.875rem;margin-bottom:0;">
        Successful (and ignored) deliveries are written to
        <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/audit-log.php'), ENT_QUOTES, 'UTF-8') ?>">Settings → Audit log</a>.
    </p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
