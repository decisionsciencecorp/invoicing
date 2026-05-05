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
    $list[] = $row;
}

$adminPageTitle = 'Companies';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1>Companies</h1>
    <div class="stack">
        <a class="btn" href="<?= htmlspecialchars(dsc_invoicing_href('admin/company-edit.php'), ENT_QUOTES, 'UTF-8') ?>">Add company</a>
        <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
            <?= csrfField() ?>
            <button type="submit" class="btn">Logout</button>
        </form>
    </div>
</div>

<div class="info-box">
    <p style="color:#8b949e;margin-top:0;">One Square Customer ID per company (optional until Square Customers API wiring).</p>
    <?php if ($list === []): ?>
        <p>No companies yet. <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/company-edit.php'), ENT_QUOTES, 'UTF-8') ?>">Add one</a>.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid #30363d;">
                    <th style="padding:0.4rem 0;">Name</th>
                    <th style="padding:0.4rem 0;">Billing email</th>
                    <th style="padding:0.4rem 0;">Square customer</th>
                    <th style="padding:0.4rem 0;">Engagements</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $c): ?>
                    <tr style="border-bottom:1px solid #21262d;">
                        <td style="padding:0.35rem 0;">
                            <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/company-edit.php?id=' . (int) $c['id']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?></a>
                        </td>
                        <td style="padding:0.35rem 0;"><?= htmlspecialchars((string) ($c['billing_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:0.35rem 0;"><code style="font-size:0.8rem;"><?= htmlspecialchars((string) ($c['square_customer_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td style="padding:0.35rem 0;">
                            <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/engagements.php?company_id=' . (int) $c['id']), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $c['engagement_count'] ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
