<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/skin-lab-env.php';
requireAuth();

$flash = '';
$flashType = 'ok';
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_skin') {
    requireCsrfToken();
    $mine = invSkinNormalizeSlug((string) ($_POST['skin_slug'] ?? ''));
    $site = invSkinNormalizeSlug((string) ($_POST['default_skin_slug'] ?? ''));
    if ($mine === null || $site === null) {
        $flash = 'Pick a valid skin.';
        $flashType = 'err';
    } else {
        invSkinSaveUserPreference((int) ($user['id'] ?? 0), $mine);
        invSkinSaveSiteDefault($site);
        $user = getCurrentUser();
        $flash = 'Appearance saved.';
        $flashType = 'ok';
    }
}

$userSkin = invSkinUserOverrideSlug(is_array($user) ? $user : null) ?? invSkinMasterSlug();
$defaultSkin = invSkinMasterSlug();
$slugs = invSkinAvailableSlugs();

$adminPageTitle = 'Appearance';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Appearance',
    'subtitle' => 'UI skins shared with Tasks / CRM (hey, ledger, brutalist, obsidian)',
]);
?>

<?php if ($flash !== ''): ?>
    <div class="message <?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="info-box">
    <p class="text-secondary">
        Your skin applies when you are signed in. Site default applies on login and for users without a personal pick.
        Preview any page with <code>?preview_skin=hey</code> (does not save).
        On <strong>dev.invoicing</strong>, use the top SKIN bar to flip comps live.
    </p>
    <form method="POST" class="mt-3">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="save_skin">
        <label for="skin_slug">Your skin</label>
        <select class="form-select" id="skin_slug" name="skin_slug">
            <?php foreach ($slugs as $slug): ?>
                <option value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" <?= $userSkin === $slug ? 'selected' : '' ?>><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <label for="default_skin_slug">Site default</label>
        <select class="form-select" id="default_skin_slug" name="default_skin_slug">
            <?php foreach ($slugs as $slug): ?>
                <option value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" <?= $defaultSkin === $slug ? 'selected' : '' ?>><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary mt-3">Save appearance</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
