<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/skin-lab-env.php';
require_once __DIR__ . '/helpers.php';

$adminPageTitle = $adminPageTitle ?? 'Admin';
$invHideNav = !empty($invHideNav);
$invCssVersion = '10';
$cssHref = dsc_invoicing_href('assets/css/invoicing.css') . '?v=' . $invCssVersion;
$bsCssHref = dsc_invoicing_href('assets/vendor/bootstrap/css/bootstrap.min.css') . '?v=5.3.3';
$biCssHref = dsc_invoicing_href('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') . '?v=1.11.3';

$invUserForSkin = (function_exists('isLoggedIn') && isLoggedIn() && function_exists('getCurrentUser'))
    ? getCurrentUser()
    : null;
$invSkinSlug = invSkinEffectiveSlug(is_array($invUserForSkin) ? $invUserForSkin : null);
$invBsTheme = invSkinBootstrapTheme($invSkinSlug);
$invNavLight = invSkinUsesLightNav($invSkinSlug);
$GLOBALS['inv_skin_slug'] = $invSkinSlug;
$GLOBALS['inv_nav_light'] = $invNavLight;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= htmlspecialchars($invBsTheme, ENT_QUOTES, 'UTF-8') ?>" data-skin-comp="<?= htmlspecialchars($invSkinSlug, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title><?= htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($bsCssHref, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($biCssHref, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(invSkinStylesheetHref($invSkinSlug), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="inv-app">
<?php if (!$invHideNav): ?>
    <?php require __DIR__ . '/nav.php'; ?>
<?php endif; ?>
    <div class="inv-shell">
<?php if (!$invHideNav && !empty($GLOBALS['inv_is_time_nav'])): ?>
    <?php
    $navPath = (string) ($GLOBALS['inv_nav_path'] ?? '');
    $timeTab = str_contains($navPath, 'report-hours.php') ? 'rollup' : 'entries';
    ?>
        <nav class="tabbar" aria-label="Time sections">
            <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>"
               class="<?= $timeTab === 'entries' ? 'active' : '' ?>">Time entries</a>
            <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/report-hours.php'), ENT_QUOTES, 'UTF-8') ?>"
               class="<?= $timeTab === 'rollup' ? 'active' : '' ?>">Hours rollup</a>
        </nav>
<?php endif; ?>
<?php if (!$invHideNav && !empty($GLOBALS['inv_is_settings_nav'])): ?>
    <?php
    $navPath = (string) ($GLOBALS['inv_nav_path'] ?? '');
    $settingsTab = 'square';
    if (str_contains($navPath, 'change-password.php')) {
        $settingsTab = 'password';
    } elseif (str_contains($navPath, 'appearance.php')) {
        $settingsTab = 'appearance';
    } elseif (str_contains($navPath, 'users.php')) {
        $settingsTab = 'users';
    } elseif (str_contains($navPath, 'api-keys.php')) {
        $settingsTab = 'api-keys';
    } elseif (str_contains($navPath, 'webhooks.php')) {
        $settingsTab = 'webhooks';
    } elseif (str_contains($navPath, 'tasks-settings.php')) {
        $settingsTab = 'tasks';
    } elseif (str_contains($navPath, 'site.php')) {
        $settingsTab = 'site';
    } elseif (str_contains($navPath, 'audit-log.php')) {
        $settingsTab = 'audit';
    } elseif (str_contains($navPath, 'config.php')) {
        $settingsTab = 'square';
    }
    $settingsTabs = [
        'password' => ['Password', 'admin/change-password.php'],
        'appearance' => ['Appearance', 'admin/appearance.php'],
        'users' => ['Users', 'admin/users.php'],
        'api-keys' => ['API keys', 'admin/api-keys.php'],
        'square' => ['Square', 'admin/config.php'],
        'webhooks' => ['Webhooks', 'admin/webhooks.php'],
        'tasks' => ['Tasks', 'admin/tasks-settings.php'],
        'site' => ['Site', 'admin/site.php'],
        'audit' => ['Audit log', 'admin/audit-log.php'],
    ];
    ?>
        <nav class="tabbar" aria-label="Settings sections">
            <?php foreach ($settingsTabs as $key => [$label, $href]): ?>
                <a href="<?= htmlspecialchars(dsc_invoicing_href($href), ENT_QUOTES, 'UTF-8') ?>"
                   class="<?= $settingsTab === $key ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
        </nav>
<?php endif; ?>
        <main class="main-content">
