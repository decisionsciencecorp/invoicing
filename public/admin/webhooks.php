<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/square.php';
requireAuth();

$section = (string) ($_GET['section'] ?? 'overview');
if (!in_array($section, ['overview', 'signing'], true)) {
    $section = 'overview';
}

$flash = '';
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'square_webhook') {
    requireCsrfToken();
    $section = 'signing';
    $whUrl = trim((string) ($_POST['square_webhook_notification_url'] ?? ''));
    $whKey = trim((string) ($_POST['square_webhook_signature_key'] ?? ''));
    set_config('square_webhook_notification_url', $whUrl);
    if ($whKey !== '') {
        set_config('square_webhook_signature_key', $whKey);
    }
    $flash = 'Webhook signing settings saved. Notification URL must match the Square subscription byte-for-byte (HMAC includes this exact string).';
    $flashType = 'ok';
}

$cfg = dsc_invoicing_square_webhook_notification_config();
$configured = dsc_invoicing_square_webhook_is_configured();
$events = dsc_invoicing_square_webhook_refresh_event_types();
$endpoint = dsc_invoicing_public_base_url() . dsc_invoicing_href('api/square-webhook.php');
$whSigStored = trim((string) (get_config('square_webhook_signature_key') ?? '')) !== '';

$adminPageTitle = 'Webhooks';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Webhooks',
    'subtitle' => 'Square notification endpoint, signing, and subscribed events',
]);

inv_render_subtabbar([
    'overview' => ['Overview', dsc_invoicing_href('admin/webhooks.php?section=overview')],
    'signing' => ['Signing', dsc_invoicing_href('admin/webhooks.php?section=signing')],
], $section, 'Webhook sections');
?>

<?php if ($flash !== ''): ?>
    <div class="message <?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($section === 'signing'): ?>
    <div class="info-box">
        <h2 class="h5 mt-0">Signing (HMAC)</h2>
        <p class="text-secondary">
            Used by <code>public/api/square-webhook.php</code>. The notification URL Square stores must match
            <strong>exactly</strong> what you enter here so verification succeeds.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="form" value="square_webhook">
            <label for="square_webhook_notification_url">Notification URL</label>
            <input class="form-control" type="url" id="square_webhook_notification_url" name="square_webhook_notification_url" spellcheck="false" value="<?= htmlspecialchars((string) (get_config('square_webhook_notification_url') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://your-host/.../api/square-webhook.php">
            <label for="square_webhook_signature_key">Signature key</label>
            <textarea class="form-control" id="square_webhook_signature_key" name="square_webhook_signature_key" rows="2" autocomplete="off" placeholder="<?= $whSigStored ? 'Stored — paste a new key to replace' : 'Signature key (whsec…)' ?>"></textarea>
            <button type="submit" class="btn btn-primary mt-3">Save signing settings</button>
        </form>
    </div>
<?php else: ?>
    <div class="info-box">
        <h2 class="h5 mt-0">Receiver</h2>
        <p class="text-secondary">
            Square pushes invoice lifecycle events here so admin status stays fresh without waiting for a client to open their link.
        </p>
        <p><strong>Status:</strong>
            <?php if ($configured): ?>
                <span class="text-success">Configured</span>
            <?php else: ?>
                <span class="text-danger">Not configured</span> —
                <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/webhooks.php?section=signing'), ENT_QUOTES, 'UTF-8') ?>">set signing</a>.
            <?php endif; ?>
        </p>
        <p><strong>Endpoint:</strong> <code><?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?></code></p>
        <p><strong>Configured notification URL:</strong>
            <code><?= htmlspecialchars((string) ($cfg['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
        </p>
        <h3 class="h6 mt-4">Events that refresh local status</h3>
        <ul>
            <?php foreach ($events as $ev): ?>
                <li><code><?= htmlspecialchars($ev, ENT_QUOTES, 'UTF-8') ?></code></li>
            <?php endforeach; ?>
        </ul>
        <p class="text-secondary small mb-0">
            Deliveries land in
            <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/audit-log.php'), ENT_QUOTES, 'UTF-8') ?>">Settings → Audit log</a>.
        </p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
