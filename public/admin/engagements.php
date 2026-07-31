<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$companyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : 0;
if ($companyId <= 0) {
    http_response_code(400);
    die('company_id required');
}

$db = getDbConnection();
$cst = $db->prepare('SELECT id, name FROM companies WHERE id = :id');
$cst->bindValue(':id', $companyId, SQLITE3_INTEGER);
$cr = $cst->execute();
$company = $cr ? $cr->fetchArray(SQLITE3_ASSOC) : false;
if (!$company) {
    http_response_code(404);
    die('Company not found.');
}

$list = [];
$st = $db->prepare(
    'SELECT id, name, hourly_rate_cents, included_hours_per_month, status, square_subscription_id, '
    . 'COALESCE(billing_mode, \'hourly\') AS billing_mode, '
    . 'COALESCE(tier1_amount_cents, 0) AS tier1_amount_cents, '
    . 'COALESCE(tier2_amount_cents, 0) AS tier2_amount_cents '
    . 'FROM engagements WHERE company_id = :cid ORDER BY name COLLATE NOCASE'
);
$st->bindValue(':cid', $companyId, SQLITE3_INTEGER);
$er = $st->execute();
while ($row = $er->fetchArray(SQLITE3_ASSOC)) {
    $list[] = $row;
}

$adminPageTitle = 'Engagements';
require_once __DIR__ . '/includes/header.php';
?>

<div class="nav-row">
    <h1>Engagements — <?= htmlspecialchars((string) $company['name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="stack">
        <a class="btn" href="<?= htmlspecialchars(dsc_invoicing_href('admin/engagement-edit.php?company_id=' . $companyId), ENT_QUOTES, 'UTF-8') ?>">Add engagement</a>
        <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/companies.php'), ENT_QUOTES, 'UTF-8') ?>">Companies</a>
        <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
            <?= csrfField() ?>
            <button type="submit" class="btn">Logout</button>
        </form>
    </div>
</div>

<div class="info-box">
    <p style="color:#8b949e;margin-top:0;">Hourly default <strong>$100/hr</strong>. Flat/tier engagements show Tier 1 / Tier 2 invoice amounts instead.</p>
    <?php if ($list === []): ?>
        <p>No engagements. <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/engagement-edit.php?company_id=' . $companyId), ENT_QUOTES, 'UTF-8') ?>">Add one</a>.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid #30363d;">
                    <th style="padding:0.4rem 0;">Name</th>
                    <th style="padding:0.4rem 0;">Mode</th>
                    <th style="padding:0.4rem 0;">Rate / tiers</th>
                    <th style="padding:0.4rem 0;">Status</th>
                    <th style="padding:0.4rem 0;">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $e): ?>
                    <?php $isFlat = (($e['billing_mode'] ?? '') === 'flat_tier'); ?>
                    <tr style="border-bottom:1px solid #21262d;">
                        <td style="padding:0.35rem 0;">
                            <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/engagement-edit.php?id=' . (int) $e['id'] . '&company_id=' . $companyId), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $e['name'], ENT_QUOTES, 'UTF-8') ?></a>
                        </td>
                        <td style="padding:0.35rem 0;"><?= $isFlat ? 'flat/tier' : 'hourly' ?></td>
                        <td style="padding:0.35rem 0;">
                            <?php if ($isFlat): ?>
                                T1 $<?= number_format(((int) $e['tier1_amount_cents']) / 100, 2) ?>
                                · T2 $<?= number_format(((int) $e['tier2_amount_cents']) / 100, 2) ?>
                            <?php else: ?>
                                $<?= number_format(((int) $e['hourly_rate_cents']) / 100, 2) ?>/hr
                                · <?= (int) $e['included_hours_per_month'] ?> hrs
                            <?php endif; ?>
                        </td>
                        <td style="padding:0.35rem 0;"><?= htmlspecialchars((string) $e['status'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:0.35rem 0;">
                            <?php if ($isFlat): ?>
                                <span style="color:#8b949e;">n/a</span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php?engagement_id=' . (int) $e['id']), ENT_QUOTES, 'UTF-8') ?>">Log</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
