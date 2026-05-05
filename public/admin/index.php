<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/square.php';
requireAuth();

try {
    $dbHealth = checkDatabaseHealth();
} catch (Throwable $e) {
    $dbHealth = ['ok' => false, 'message' => $e->getMessage()];
}

$sqEnv = dsc_invoicing_square_config()['environment'] ?? 'sandbox';
$squareOk = square_is_configured();

$adminPageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1>Dashboard</h1>
    <div class="stack">
        <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/companies.php'), ENT_QUOTES, 'UTF-8') ?>">Companies</a>
        <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>">Time</a>
        <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/config.php'), ENT_QUOTES, 'UTF-8') ?>">Square config</a>
        <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
            <?= csrfField() ?>
            <button type="submit" class="btn">Logout</button>
        </form>
    </div>
</div>

<div class="info-box">
    <p><strong>Logged in:</strong> <?= htmlspecialchars((string) ($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong>Database:</strong>
        <?php if ($dbHealth['ok']): ?>
            <span class="success"><?= htmlspecialchars($dbHealth['message'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php else: ?>
            <span class="error"><?= htmlspecialchars($dbHealth['message'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </p>
    <p><strong>Square:</strong>
        <?= $squareOk
            ? 'token configured · environment <code>' . htmlspecialchars($sqEnv, ENT_QUOTES, 'UTF-8') . '</code>'
            : '<span class="error">No access token — add in Config</span>'
        ?>
    </p>
    <p style="margin-top:1rem;color:#8b949e;font-size:.875rem;">PRD milestones: Companies / engagements / time (P1) and combined invoices (P2) follow this bootstrap.</p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
