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

$adminPageTitle = 'Square configuration';
require_once __DIR__ . '/includes/header.php';
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
