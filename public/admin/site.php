<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$flash = '';
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'app_paths') {
    requireCsrfToken();
    set_config('web_base_path', trim((string) ($_POST['web_base_path'] ?? '')));
    set_config('site_url', trim((string) ($_POST['site_url'] ?? '')));
    $flash = 'Site path settings saved.';
    $flashType = 'ok';
}

$adminPageTitle = 'Site';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Site',
    'subtitle' => 'Public origin and optional web base path for share links',
]);
?>

<?php if ($flash !== ''): ?>
    <div class="message <?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="info-box">
    <p class="text-secondary">
        Leave web base path blank when the vhost document root points at <code>public/</code>.
        Set <strong>Site URL</strong> when invoice share links must be absolute
        (e.g. <code>https://invoicing.decisionsciencecorp.com</code>) — used by CLI/cron when
        <code>SITE_URL</code> env is unset.
    </p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="app_paths">
        <label for="site_url">Site URL</label>
        <input class="form-control" type="url" id="site_url" name="site_url" value="<?= htmlspecialchars((string) (get_config('site_url') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://invoicing.decisionsciencecorp.com">
        <label for="web_base_path">Web base path</label>
        <input class="form-control" type="text" id="web_base_path" name="web_base_path" value="<?= htmlspecialchars((string) (get_config('web_base_path') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="/public">
        <button type="submit" class="btn btn-primary mt-3">Save</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
