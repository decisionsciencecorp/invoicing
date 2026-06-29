<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/square.php';
requireAuth();

$squareMessage = '';
$squareMessageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'square') {
    requireCsrfToken();
    $fields = [
        'square_access_token' => trim((string) ($_POST['square_access_token'] ?? '')),
        'square_application_id' => trim((string) ($_POST['square_application_id'] ?? '')),
        'square_environment' => in_array($_POST['square_environment'] ?? '', ['sandbox', 'production'], true)
            ? $_POST['square_environment']
            : 'sandbox',
        'square_location_id' => trim((string) ($_POST['square_location_id'] ?? '')),
    ];
    foreach ($fields as $k => $v) {
        if ($k === 'square_access_token' && $v === '') {
            continue;
        }
        set_config($k, $v);
    }
    square_config_reset();
    if ($fields['square_location_id'] === '' && $fields['square_access_token'] !== '') {
        $resp = dsc_invoicing_square_request('GET', '/locations', null);
        if ($resp['ok']) {
            $locs = $resp['data']['locations'] ?? [];
            if ($locs !== [] && isset($locs[0]['id'])) {
                set_config('square_location_id', (string) $locs[0]['id']);
            }
        }
    }
    square_config_reset();
    $squareMessage = 'Square configuration saved.';
    $squareMessageType = 'ok';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'square_webhook') {
    requireCsrfToken();
    $whUrl = trim((string) ($_POST['square_webhook_notification_url'] ?? ''));
    $whKey = trim((string) ($_POST['square_webhook_signature_key'] ?? ''));
    set_config('square_webhook_notification_url', $whUrl);
    if ($whKey !== '') {
        set_config('square_webhook_signature_key', $whKey);
    }
    $squareMessage = 'Webhook settings saved. Notification URL must match the subscription byte-for-byte (HMAC includes this exact string).';
    $squareMessageType = 'ok';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'app_paths') {
    requireCsrfToken();
    $webBasePath = trim((string) ($_POST['web_base_path'] ?? ''));
    set_config('web_base_path', $webBasePath);
    $squareMessage = 'App path settings saved.';
    $squareMessageType = 'ok';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'tasks_dsc') {
    requireCsrfToken();
    set_config('tasks_dsc_base_url', trim((string) ($_POST['tasks_dsc_base_url'] ?? '')));
    $key = trim((string) ($_POST['tasks_dsc_api_key'] ?? ''));
    if ($key !== '') {
        set_config('tasks_dsc_api_key', $key);
    }
    $squareMessage = 'Tasks API settings saved.';
    $squareMessageType = 'ok';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'square_test') {
    requireCsrfToken();
    square_config_reset();
    $resp = dsc_invoicing_square_request('GET', '/locations', null);
    if ($resp['ok']) {
        $locs = $resp['data']['locations'] ?? [];
        $names = array_map(static fn ($l) => ($l['name'] ?? '') . ' · ' . ($l['id'] ?? ''), $locs);
        $squareMessage = 'Connection OK — ' . count($locs) . ' location(s): ' . implode(' | ', $names);
        $squareMessageType = 'ok';
    } else {
        $squareMessage = 'Connection failed: ' . ($resp['error'] ?? 'unknown');
        $squareMessageType = 'err';
    }
}

square_config_reset();
$cfg = dsc_invoicing_square_config();

$masked = '';
$t = get_config('square_access_token');
if (is_string($t) && strlen($t) > 12) {
    $masked = substr($t, 0, 4) . str_repeat('·', strlen($t) - 8) . substr($t, -4);
} elseif (is_string($t) && $t !== '') {
    $masked = '(stored)';
}

$whSigStored = trim((string) (get_config('square_webhook_signature_key') ?? '')) !== '';

$adminPageTitle = 'Square configuration';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1>Square configuration</h1>
    <div class="stack">
        <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/index.php'), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
        <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
            <?= csrfField() ?>
            <button type="submit" class="btn">Logout</button>
        </form>
    </div>
</div>

<p style="color:#8b949e;font-size:.875rem;">Use <strong>sandbox only</strong> until production is blessed (PRD D10).</p>

<?php if ($squareMessage !== ''): ?>
    <div class="message <?= $squareMessageType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($squareMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="info-box">
    <h2 style="margin-top:0;">Credentials</h2>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="square">
        <label for="square_environment">Environment</label>
        <select id="square_environment" name="square_environment">
            <option value="sandbox" <?= ($cfg['environment'] ?? '') === 'sandbox' ? 'selected' : '' ?>>sandbox</option>
            <option value="production" <?= ($cfg['environment'] ?? '') === 'production' ? 'selected' : '' ?>>production</option>
        </select>
        <label for="square_access_token">Access token (leave blank to keep existing)</label>
        <textarea id="square_access_token" name="square_access_token" rows="2" autocomplete="off" placeholder="<?= $masked !== '' ? 'stored: ' . $masked : 'EAAA…' ?>"></textarea>
        <label for="square_application_id">Application ID (optional)</label>
        <input type="text" id="square_application_id" name="square_application_id" value="<?= htmlspecialchars((string) (get_config('square_application_id') ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="square_location_id">Location ID (optional)</label>
        <input type="text" id="square_location_id" name="square_location_id" value="<?= htmlspecialchars((string) (get_config('square_location_id') ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn" style="margin-top:1rem;">Save</button>
    </form>
    <form method="POST" style="margin-top:1rem;">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="square_test">
        <button type="submit" class="btn btn-outline">Test connection (GET /locations)</button>
    </form>
</div>

<div class="info-box" style="margin-top:1.5rem;">
    <h2 style="margin-top:0;">App paths</h2>
    <p style="color:#8b949e;font-size:.875rem;">Leave blank when vhost document root points at <code>public/</code>. Set to <code>/public</code> if this app is served from the repo root.</p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="app_paths">
        <label for="web_base_path">Web base path</label>
        <input type="text" id="web_base_path" name="web_base_path" value="<?= htmlspecialchars((string) (get_config('web_base_path') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="/public">
        <button type="submit" class="btn" style="margin-top:1rem;">Save app path</button>
    </form>
</div>

<div class="info-box" style="margin-top:1.5rem;">
    <h2 style="margin-top:0;">Webhook (invoice.payment_made)</h2>
    <p style="color:#8b949e;font-size:.875rem;">Used by <code>public/api/square-webhook.php</code>. The notification URL Square stores must match <strong>exactly</strong> what you enter here so HMAC verification succeeds.</p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="square_webhook">
        <label for="square_webhook_notification_url">Notification URL</label>
        <input type="url" id="square_webhook_notification_url" name="square_webhook_notification_url" spellcheck="false" style="max-width:100%;width:100%;box-sizing:border-box;" value="<?= htmlspecialchars((string) (get_config('square_webhook_notification_url') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://your-host/.../api/square-webhook.php">
        <label for="square_webhook_signature_key">Signature key (starts with typically whsec_…)</label>
        <textarea id="square_webhook_signature_key" name="square_webhook_signature_key" rows="2" autocomplete="off" placeholder="<?= $whSigStored ? 'Stored — paste a new key to replace' : 'Signature key (whsec…)' ?>"></textarea>
        <button type="submit" class="btn" style="margin-top:1rem;">Save webhook settings</button>
    </form>
</div>

<div class="info-box" style="margin-top:1.5rem;">
    <h2 style="margin-top:0;">Tasks API (accounting documents)</h2>
    <p style="color:#8b949e;font-size:.875rem;">Used when publishing invoices to fetch and snapshot markdown from <code>tasks.decisionsciencecorp.com</code>. Env vars <code>TASKS_DSC_BASE_URL</code> / <code>TASKS_DSC_OTTOVERNAL_API_KEY</code> override when set on the server.</p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="tasks_dsc">
        <label for="tasks_dsc_base_url">Base URL</label>
        <input type="url" id="tasks_dsc_base_url" name="tasks_dsc_base_url" style="max-width:100%;width:100%;box-sizing:border-box;" value="<?= htmlspecialchars((string) (get_config('tasks_dsc_base_url') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://tasks.decisionsciencecorp.com">
        <label for="tasks_dsc_api_key">API key (leave blank to keep existing)</label>
        <textarea id="tasks_dsc_api_key" name="tasks_dsc_api_key" rows="2" autocomplete="off" placeholder="X-API-Key value"></textarea>
        <button type="submit" class="btn" style="margin-top:1rem;">Save Tasks API</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
