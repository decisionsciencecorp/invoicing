<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/square.php';
require_once __DIR__ . '/../includes/billing.php';
requireAuth();

try {
    $dbHealth = checkDatabaseHealth();
} catch (Throwable $e) {
    $dbHealth = ['ok' => false, 'message' => $e->getMessage()];
}

$sqEnv = dsc_invoicing_square_config()['environment'] ?? 'sandbox';
$squareOk = square_is_configured();
$db = getDbConnection();
$companyCount = (int) $db->querySingle('SELECT COUNT(*) FROM companies');
$activeEng = (int) $db->querySingle("SELECT COUNT(*) FROM engagements WHERE status = 'active'");
$unpaidCount = (int) $db->querySingle(
    "SELECT COUNT(*) FROM outbound_invoices WHERE LOWER(COALESCE(payment_status, '')) NOT IN ('paid', 'canceled')"
);
$outboundCount = (int) $db->querySingle('SELECT COUNT(*) FROM outbound_invoices');

$adminPageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Dashboard',
    'subtitle' => 'Billing health at a glance',
    'actions_html' => '<a class="btn btn-outline" href="' . htmlspecialchars(dsc_invoicing_href('admin/invoices.php'), ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-plus-lg me-1"></i>Invoices</a>',
]);
?>

<div class="inv-kpi-row">
    <div class="inv-kpi">
        <div class="inv-kpi__label">Companies</div>
        <div class="inv-kpi__value"><?= $companyCount ?></div>
    </div>
    <div class="inv-kpi">
        <div class="inv-kpi__label">Active engagements</div>
        <div class="inv-kpi__value"><?= $activeEng ?></div>
    </div>
    <div class="inv-kpi">
        <div class="inv-kpi__label">Outbound invoices</div>
        <div class="inv-kpi__value"><?= $outboundCount ?></div>
    </div>
    <div class="inv-kpi">
        <div class="inv-kpi__label">Unpaid / AR</div>
        <div class="inv-kpi__value"><?= $unpaidCount ?></div>
    </div>
</div>

<div class="surface surface-pad">
    <p class="mb-2"><strong>Signed in as</strong> <?= htmlspecialchars((string) ($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="mb-2"><strong>Database:</strong>
        <?php if ($dbHealth['ok']): ?>
            <span class="success"><?= htmlspecialchars($dbHealth['message'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php else: ?>
            <span class="error"><?= htmlspecialchars($dbHealth['message'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </p>
    <p class="mb-0"><strong>Square:</strong>
        <?php if ($squareOk): ?>
            configured · <code><?= htmlspecialchars($sqEnv, ENT_QUOTES, 'UTF-8') ?></code>
        <?php else: ?>
            <span class="error">No access token — add under Settings → Square</span>
        <?php endif; ?>
    </p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
