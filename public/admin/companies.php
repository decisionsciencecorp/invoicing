<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$db = getDbConnection();
$list = [];
$r = $db->query(
    'SELECT c.id, c.name, c.billing_email, c.square_customer_id, ' .
    '(SELECT COUNT(*) FROM engagements e WHERE e.company_id = c.id) AS engagement_count ' .
    'FROM companies c ORDER BY c.name COLLATE NOCASE'
);
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $modes = [];
    $ms = $db->prepare(
        'SELECT DISTINCT COALESCE(billing_mode, \'hourly\') AS billing_mode '
        . 'FROM engagements WHERE company_id = :c ORDER BY billing_mode'
    );
    $ms->bindValue(':c', (int) $row['id'], SQLITE3_INTEGER);
    $mx = $ms->execute();
    while ($m = $mx->fetchArray(SQLITE3_ASSOC)) {
        $bm = (string) ($m['billing_mode'] ?? 'hourly');
        $modes[] = $bm === 'flat_tier' ? 'Flat / tier' : 'Hourly';
    }
    $row['billing_modes_label'] = $modes === [] ? '—' : implode(', ', $modes);
    $list[] = $row;
}

$adminPageTitle = 'Companies';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Companies',
    'subtitle' => 'One Square customer ID per company (optional until wired).',
    'actions_html' => '<a class="btn btn-primary" href="'
        . htmlspecialchars(dsc_invoicing_href('admin/company-edit.php'), ENT_QUOTES, 'UTF-8')
        . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add company</a>',
]);
?>

<div class="surface surface-pad">
    <?php if ($list === []): ?>
        <p class="mb-0">No companies yet. <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/company-edit.php'), ENT_QUOTES, 'UTF-8') ?>">Add one</a>.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="inv-table inv-table-cards">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Billing</th>
                        <th>Billing email</th>
                        <th>Square customer</th>
                        <th>Engagements</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $c): ?>
                        <tr>
                            <td data-label="Name">
                                <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/company-edit.php?id=' . (int) $c['id']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?></a>
                            </td>
                            <td data-label="Billing"><?= htmlspecialchars((string) ($c['billing_modes_label'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Email"><?= htmlspecialchars((string) ($c['billing_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Square"><code><?= htmlspecialchars((string) ($c['square_customer_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td data-label="Engagements">
                                <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/engagements.php?company_id=' . (int) $c['id']), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $c['engagement_count'] ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
