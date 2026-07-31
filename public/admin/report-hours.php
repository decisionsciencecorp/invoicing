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
inv_render_page_header([
    'title' => 'Hours rollup',
    'subtitle' => 'Hours by engagement and month',
]);
?>

<p class="text-secondary">Per engagement per <strong>billing period month</strong> (from logged work dates). Overage = max(0, logged − included hours/mo).</p>

<div class="info-box">
    <?php if ($rows === []): ?>
        <p>No time entries yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="inv-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Company</th>
                        <th>Engagement</th>
                        <th class="text-end">Logged</th>
                        <th class="text-end">Included</th>
                        <th class="text-end">Overage</th>
                        <th class="text-end">$/hr</th>
                        <th class="text-end">Est. overage $</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $x): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $x['billing_period_month'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $x['company_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php?engagement_id=' . (int) $x['engagement_id']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $x['engagement_name'], ENT_QUOTES, 'UTF-8') ?></a>
                            </td>
                            <td class="text-end"><?= htmlspecialchars((string) $x['logged_hours'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end"><?= (int) $x['included_hours_per_month'] ?></td>
                            <td class="text-end"><?= htmlspecialchars((string) $x['overage_hours'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end">$<?= number_format(((int) $x['hourly_rate_cents']) / 100, 2) ?></td>
                            <td class="text-end">$<?= number_format($x['estimated_overage_cents'] / 100, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-secondary small mb-0 mt-3">Estimated overage billing is illustrative (PRD billing rules).</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
