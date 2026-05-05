<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$db = getDbConnection();

$sql =
    'SELECT t.billing_period_month, c.id AS company_id, c.name AS company_name, ' .
    'e.id AS engagement_id, e.name AS engagement_name, e.hourly_rate_cents, e.included_hours_per_month, ' .
    'SUM(t.hours) AS total_hours ' .
    'FROM time_entries t ' .
    'JOIN engagements e ON e.id = t.engagement_id ' .
    'JOIN companies c ON c.id = e.company_id ' .
    'GROUP BY t.billing_period_month, e.id ' .
    'ORDER BY t.billing_period_month DESC, c.name COLLATE NOCASE, e.name COLLATE NOCASE';

$rows = [];
$r = $db->query($sql);
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $logged = (float) $row['total_hours'];
    $included = (int) $row['included_hours_per_month'];
    $overage = max(0.0, $logged - (float) $included);
    $row['logged_hours'] = $logged;
    $row['overage_hours'] = $overage;
    $row['estimated_overage_cents'] = (int) round($overage * (int) $row['hourly_rate_cents']);
    $rows[] = $row;
}

$adminPageTitle = 'Hours by billing period';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1>Hours rollup</h1>
    <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrfField() ?>
        <button type="submit" class="btn">Logout</button>
    </form>
</div>

<p style="color:#8b949e;margin-top:0;">Per engagement per <strong>billing_period_month</strong> (from logged work dates). Overage = max(0, logged − included hours/mo).</p>

<div class="info-box">
    <?php if ($rows === []): ?>
        <p>No time entries yet.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid #30363d;">
                        <th style="padding:0.4rem;">Period</th>
                        <th style="padding:0.4rem;">Company</th>
                        <th style="padding:0.4rem;">Engagement</th>
                        <th style="padding:0.4rem;text-align:right;">Logged</th>
                        <th style="padding:0.4rem;text-align:right;">Included</th>
                        <th style="padding:0.4rem;text-align:right;">Overage</th>
                        <th style="padding:0.4rem;text-align:right;">$/hr</th>
                        <th style="padding:0.4rem;text-align:right;">Est. overage $</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $x): ?>
                        <tr style="border-bottom:1px solid #21262d;">
                            <td style="padding:0.35rem 0;"><?= htmlspecialchars((string) $x['billing_period_month'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;"><?= htmlspecialchars((string) $x['company_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;">
                                <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php?engagement_id=' . (int) $x['engagement_id']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $x['engagement_name'], ENT_QUOTES, 'UTF-8') ?></a>
                            </td>
                            <td style="padding:0.35rem 0;text-align:right;"><?= htmlspecialchars((string) $x['logged_hours'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;text-align:right;"><?= (int) $x['included_hours_per_month'] ?></td>
                            <td style="padding:0.35rem 0;text-align:right;"><?= htmlspecialchars((string) $x['overage_hours'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;text-align:right;">$<?= number_format(((int) $x['hourly_rate_cents']) / 100, 2) ?></td>
                            <td style="padding:0.35rem 0;text-align:right;">$<?= number_format($x['estimated_overage_cents'] / 100, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="color:#8b949e;font-size:0.8rem;margin-bottom:0;">Estimated overage billing is illustrative (PRD billing rules + invoicing automation P2).</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
