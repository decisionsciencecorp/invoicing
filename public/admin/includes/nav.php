<?php
declare(strict_types=1);
?>
<nav class="stack" style="margin-bottom: 1.25rem;">
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/index.php'), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/companies.php'), ENT_QUOTES, 'UTF-8') ?>">Companies</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>">Time</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/report-hours.php'), ENT_QUOTES, 'UTF-8') ?>">Rollup</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/invoices.php'), ENT_QUOTES, 'UTF-8') ?>">Invoices</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/api-keys.php'), ENT_QUOTES, 'UTF-8') ?>">API keys</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/users.php'), ENT_QUOTES, 'UTF-8') ?>">Users</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/change-password.php'), ENT_QUOTES, 'UTF-8') ?>">Password</a>
    <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/config.php'), ENT_QUOTES, 'UTF-8') ?>">Square</a>
</nav>
