<?php
declare(strict_types=1);

$navPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$isTimeNav = str_contains($navPath, '/admin/time-entries.php')
    || str_contains($navPath, '/admin/report-hours.php');
$isSettingsNav = str_contains($navPath, '/admin/api-keys.php')
    || str_contains($navPath, '/admin/users.php')
    || str_contains($navPath, '/admin/change-password.php')
    || str_contains($navPath, '/admin/config.php')
    || str_contains($navPath, '/admin/webhooks.php')
    || str_contains($navPath, '/admin/audit-log.php');
$isHelpNav = str_contains($navPath, '/admin/help.php');
?>
<nav class="stack inv-admin-nav" style="margin-bottom: 1.25rem; display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/index.php'), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/companies.php'), ENT_QUOTES, 'UTF-8') ?>">Companies</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>"<?= $isTimeNav ? ' aria-current="page"' : '' ?>>Time</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/invoices.php'), ENT_QUOTES, 'UTF-8') ?>">Invoices</a>
    <details class="inv-nav-settings" style="position:relative;">
        <summary class="btn btn-outline" style="list-style:none;cursor:pointer;<?= $isSettingsNav ? 'border-color:#58a6ff;' : '' ?>">
            Settings
        </summary>
        <div style="position:absolute;z-index:20;margin-top:.35rem;min-width:11rem;background:#161b22;border:1px solid #30363d;border-radius:6px;padding:.35rem;display:flex;flex-direction:column;gap:.25rem;">
            <a class="btn btn-outline" style="text-align:left;" href="<?= htmlspecialchars(dsc_invoicing_href('admin/change-password.php'), ENT_QUOTES, 'UTF-8') ?>">Password</a>
            <a class="btn btn-outline" style="text-align:left;" href="<?= htmlspecialchars(dsc_invoicing_href('admin/users.php'), ENT_QUOTES, 'UTF-8') ?>">Users</a>
            <a class="btn btn-outline" style="text-align:left;" href="<?= htmlspecialchars(dsc_invoicing_href('admin/api-keys.php'), ENT_QUOTES, 'UTF-8') ?>">API keys</a>
            <a class="btn btn-outline" style="text-align:left;" href="<?= htmlspecialchars(dsc_invoicing_href('admin/config.php'), ENT_QUOTES, 'UTF-8') ?>">Square</a>
            <a class="btn btn-outline" style="text-align:left;" href="<?= htmlspecialchars(dsc_invoicing_href('admin/webhooks.php'), ENT_QUOTES, 'UTF-8') ?>">Webhooks</a>
            <a class="btn btn-outline" style="text-align:left;" href="<?= htmlspecialchars(dsc_invoicing_href('admin/audit-log.php'), ENT_QUOTES, 'UTF-8') ?>">Audit log</a>
        </div>
    </details>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/help.php'), ENT_QUOTES, 'UTF-8') ?>"<?= $isHelpNav ? ' aria-current="page"' : '' ?>>Help</a>
</nav>
<?php if ($isTimeNav): ?>
    <?php
    $timeTab = str_contains($navPath, 'report-hours.php') ? 'rollup' : 'entries';
    ?>
    <nav class="inv-tabbar" aria-label="Time sections" style="display:flex;gap:0;margin:-.5rem 0 1.25rem;border-bottom:1px solid #30363d;">
        <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>"
           style="padding:.55rem 1rem;text-decoration:none;border-bottom:2px solid <?= $timeTab === 'entries' ? '#58a6ff' : 'transparent' ?>;color:<?= $timeTab === 'entries' ? '#e6edf3' : '#8b949e' ?>;font-weight:<?= $timeTab === 'entries' ? '600' : '400' ?>;">
            Time entries
        </a>
        <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/report-hours.php'), ENT_QUOTES, 'UTF-8') ?>"
           style="padding:.55rem 1rem;text-decoration:none;border-bottom:2px solid <?= $timeTab === 'rollup' ? '#58a6ff' : 'transparent' ?>;color:<?= $timeTab === 'rollup' ? '#e6edf3' : '#8b949e' ?>;font-weight:<?= $timeTab === 'rollup' ? '600' : '400' ?>;">
            Hours rollup
        </a>
    </nav>
<?php endif; ?>
<?php if ($isSettingsNav): ?>
    <?php
    $settingsTab = 'square';
    if (str_contains($navPath, 'change-password.php')) {
        $settingsTab = 'password';
    } elseif (str_contains($navPath, 'users.php')) {
        $settingsTab = 'users';
    } elseif (str_contains($navPath, 'api-keys.php')) {
        $settingsTab = 'api-keys';
    } elseif (str_contains($navPath, 'webhooks.php')) {
        $settingsTab = 'webhooks';
    } elseif (str_contains($navPath, 'audit-log.php')) {
        $settingsTab = 'audit';
    }
    $settingsTabs = [
        'password' => ['Password', 'admin/change-password.php'],
        'users' => ['Users', 'admin/users.php'],
        'api-keys' => ['API keys', 'admin/api-keys.php'],
        'square' => ['Square', 'admin/config.php'],
        'webhooks' => ['Webhooks', 'admin/webhooks.php'],
        'audit' => ['Audit log', 'admin/audit-log.php'],
    ];
    ?>
    <nav class="inv-tabbar" aria-label="Settings sections" style="display:flex;gap:0;flex-wrap:wrap;margin:-.5rem 0 1.25rem;border-bottom:1px solid #30363d;">
        <?php foreach ($settingsTabs as $key => [$label, $href]): ?>
            <a href="<?= htmlspecialchars(dsc_invoicing_href($href), ENT_QUOTES, 'UTF-8') ?>"
               style="padding:.55rem 1rem;text-decoration:none;border-bottom:2px solid <?= $settingsTab === $key ? '#58a6ff' : 'transparent' ?>;color:<?= $settingsTab === $key ? '#e6edf3' : '#8b949e' ?>;font-weight:<?= $settingsTab === $key ? '600' : '400' ?>;">
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
