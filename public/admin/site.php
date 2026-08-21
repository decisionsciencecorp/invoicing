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
    set_config('site_name', trim((string) ($_POST['site_name'] ?? '')));
    set_config('invoice_brand_name', trim((string) ($_POST['invoice_brand_name'] ?? '')));
    set_config('invoice_brand_url', trim((string) ($_POST['invoice_brand_url'] ?? '')));
    $flash = 'Site settings saved.';
    $flashType = 'ok';
}

$adminPageTitle = 'Site';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Site',
    'subtitle' => 'Brand, public origin, and optional web base path for share links',
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
        App name and invoice brand override chrome / public footer when set.
    </p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="app_paths">
        <label for="site_name">App display name</label>
        <input class="form-control" type="text" id="site_name" name="site_name" value="<?= htmlspecialchars((string) (get_config('site_name') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'DSC Invoicing', ENT_QUOTES, 'UTF-8') ?>">
        <label for="invoice_brand_name">Invoice brand name</label>
        <input class="form-control" type="text" id="invoice_brand_name" name="invoice_brand_name" value="<?= htmlspecialchars((string) (get_config('invoice_brand_name') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Decision Science Corp">
        <label for="invoice_brand_url">Invoice brand URL</label>
        <input class="form-control" type="url" id="invoice_brand_url" name="invoice_brand_url" value="<?= htmlspecialchars((string) (get_config('invoice_brand_url') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://decisionsciencecorp.com/">
        <label for="site_url">Site URL</label>
        <input class="form-control" type="url" id="site_url" name="site_url" value="<?= htmlspecialchars((string) (get_config('site_url') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://invoicing.decisionsciencecorp.com">
        <label for="web_base_path">Web base path</label>
        <input class="form-control" type="text" id="web_base_path" name="web_base_path" value="<?= htmlspecialchars((string) (get_config('web_base_path') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="/public">
        <button type="submit" class="btn btn-primary mt-3">Save</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
