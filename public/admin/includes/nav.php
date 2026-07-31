<?php
declare(strict_types=1);

$navPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$isTimeNav = str_contains($navPath, '/admin/time-entries.php')
    || str_contains($navPath, '/admin/report-hours.php');
$isInvoicesNav = str_contains($navPath, '/admin/invoices.php');
$isCompaniesNav = str_contains($navPath, '/admin/companies.php')
    || str_contains($navPath, '/admin/engagement');
$isSettingsNav = str_contains($navPath, '/admin/api-keys.php')
    || str_contains($navPath, '/admin/users.php')
    || str_contains($navPath, '/admin/change-password.php')
    || str_contains($navPath, '/admin/config.php')
    || str_contains($navPath, '/admin/webhooks.php')
    || str_contains($navPath, '/admin/audit-log.php');
$isHelpNav = str_contains($navPath, '/admin/help.php');
$isDashNav = str_contains($navPath, '/admin/index.php');
$username = (string) ($_SESSION['username'] ?? '');

// Expose for header tabbars (same request).
$GLOBALS['inv_is_time_nav'] = $isTimeNav;
$GLOBALS['inv_is_settings_nav'] = $isSettingsNav;
$GLOBALS['inv_nav_path'] = $navPath;
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark admin-nav">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-semibold d-inline-flex align-items-center gap-2" href="<?= htmlspecialchars(dsc_invoicing_href('admin/index.php'), ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            <span>Invoicing</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#invAdminNavbar"
                aria-controls="invAdminNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="invAdminNavbar">
            <div class="d-flex flex-column flex-lg-row flex-wrap gap-2 ms-lg-auto align-items-stretch align-items-lg-center inv-nav-cluster py-3 py-lg-0">
                <a class="btn btn-outline-light text-center text-lg-start<?= $isDashNav ? ' active' : '' ?>"
                   href="<?= htmlspecialchars(dsc_invoicing_href('admin/index.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi bi-house-door me-1" aria-hidden="true"></i>Dashboard
                </a>
                <a class="btn btn-outline-light text-center text-lg-start<?= $isCompaniesNav ? ' active' : '' ?>"
                   href="<?= htmlspecialchars(dsc_invoicing_href('admin/companies.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi bi-buildings me-1" aria-hidden="true"></i>Companies
                </a>
                <a class="btn btn-outline-light text-center text-lg-start<?= $isTimeNav ? ' active' : '' ?>"
                   href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi bi-clock me-1" aria-hidden="true"></i>Time
                </a>
                <a class="btn btn-outline-light text-center text-lg-start<?= $isInvoicesNav ? ' active' : '' ?>"
                   href="<?= htmlspecialchars(dsc_invoicing_href('admin/invoices.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Invoices
                </a>
                <div class="dropdown inv-nav-end-dropdown">
                    <button class="btn btn-outline-light text-center text-lg-start dropdown-toggle<?= $isSettingsNav ? ' active' : '' ?>"
                            type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear me-1" aria-hidden="true"></i>Settings
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(dsc_invoicing_href('admin/change-password.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-key me-2" aria-hidden="true"></i>Password</a></li>
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(dsc_invoicing_href('admin/users.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-people me-2" aria-hidden="true"></i>Users</a></li>
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(dsc_invoicing_href('admin/api-keys.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-braces me-2" aria-hidden="true"></i>API keys</a></li>
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(dsc_invoicing_href('admin/config.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-credit-card me-2" aria-hidden="true"></i>Square</a></li>
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(dsc_invoicing_href('admin/webhooks.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-broadcast me-2" aria-hidden="true"></i>Webhooks</a></li>
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(dsc_invoicing_href('admin/audit-log.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-journal-text me-2" aria-hidden="true"></i>Audit log</a></li>
                    </ul>
                </div>
                <a class="btn btn-outline-light text-center text-lg-start<?= $isHelpNav ? ' active' : '' ?>"
                   href="<?= htmlspecialchars(dsc_invoicing_href('admin/help.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi bi-question-circle me-1" aria-hidden="true"></i>Help
                </a>
                <hr class="d-lg-none border-secondary opacity-50 my-1 mx-0 w-100">
                <?php if ($username !== ''): ?>
                    <span class="navbar-text text-white-50 small px-lg-2 py-1 text-center text-lg-start"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>" class="m-0">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-outline-light" aria-label="Log out">
                        <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
