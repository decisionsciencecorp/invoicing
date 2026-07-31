<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/skin-lab-env.php';
requireAuth();

$flash = '';
$flashType = 'ok';
$user = getCurrentUser();
$labels = invSkinLabels();
$slugs = invSkinAvailableSlugs();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_appearance') {
    requireCsrfToken();
    $choice = (string) ($_POST['skin_choice'] ?? '');
    $uid = (int) ($user['id'] ?? 0);
    if ($choice === '__site__') {
        $res = invSkinSaveUserPreference($uid, null);
    } else {
        $res = invSkinSaveUserPreference($uid, $choice);
    }
    if (!empty($res['success'])) {
        $user = getCurrentUser();
        $flash = 'Appearance saved.';
        $flashType = 'ok';
    } else {
        $flash = (string) ($res['error'] ?? 'Could not save appearance.');
        $flashType = 'err';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_site_default') {
    requireCsrfToken();
    $site = invSkinNormalizeSlug((string) ($_POST['default_skin_slug'] ?? ''));
    if ($site === null) {
        $flash = 'Pick a valid site default.';
        $flashType = 'err';
    } else {
        invSkinSaveSiteDefault($site);
        $flash = 'Site default theme saved.';
        $flashType = 'ok';
        $user = getCurrentUser();
    }
}

$userOverride = invSkinUserOverrideSlug(is_array($user) ? $user : null);
$siteDefault = invSkinMasterSlug();
$effective = invSkinEffectiveSlug(is_array($user) ? $user : null);

$adminPageTitle = 'Appearance';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Appearance',
    'subtitle' => 'How Invoicing looks for your account',
]);
?>

<?php if ($flash !== ''): ?>
    <div class="message <?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="info-box">
    <h2 class="h5 mt-0"><i class="bi bi-palette me-1" aria-hidden="true"></i> Appearance</h2>
    <p class="text-secondary">
        Choose how DSC Invoicing looks for your account. The site default is
        <strong><?= htmlspecialchars($labels[$siteDefault] ?? $siteDefault, ENT_QUOTES, 'UTF-8') ?></strong>
        unless you pick a personal override below.
    </p>
    <form method="POST" style="max-width:32rem;">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="save_appearance">
        <label class="form-label">Skin preference</label>
        <div class="d-flex flex-column gap-2 mb-3">
            <label class="form-check">
                <input class="form-check-input" type="radio" name="skin_choice" value="__site__"
                    <?= $userOverride === null ? 'checked' : '' ?>>
                <span class="form-check-label">Use site default (<?= htmlspecialchars($labels[$siteDefault] ?? $siteDefault, ENT_QUOTES, 'UTF-8') ?>)</span>
            </label>
            <?php foreach ($slugs as $slug): ?>
                <label class="form-check">
                    <input class="form-check-input" type="radio" name="skin_choice" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $userOverride === $slug ? 'checked' : '' ?>>
                    <span class="form-check-label"><?= htmlspecialchars($labels[$slug] ?? $slug, ENT_QUOTES, 'UTF-8') ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="text-secondary small">Currently active: <strong><?= htmlspecialchars($labels[$effective] ?? $effective, ENT_QUOTES, 'UTF-8') ?></strong></p>
        <button type="submit" class="btn btn-primary">Save appearance</button>
    </form>
</div>

<div class="info-box mt-3">
    <h2 class="h5 mt-0"><i class="bi bi-sliders me-1" aria-hidden="true"></i> Site default</h2>
    <p class="text-secondary">
        Fallback theme for login and for accounts that use the site default (same role as CRM System settings → Theme).
    </p>
    <form method="POST" style="max-width:32rem;">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="save_site_default">
        <label class="form-label" for="default_skin_slug">Site default theme</label>
        <select class="form-select" id="default_skin_slug" name="default_skin_slug">
            <?php foreach ($slugs as $slug): ?>
                <option value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" <?= $siteDefault === $slug ? 'selected' : '' ?>>
                    <?= htmlspecialchars($labels[$slug] ?? $slug, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline mt-3">Save site default</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
