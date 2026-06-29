<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/billing.php';
requireAuth();

$db = getDbConnection();

$flash = '';
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'publish_invoice') {
    requireCsrfToken();
    $eid = (int) ($_POST['engagement_id'] ?? 0);
    $anchor = trim((string) ($_POST['anchor_month'] ?? ''));
    $tasksDocId = (int) ($_POST['tasks_document_id'] ?? 0);
    $res = dsc_billing_publish_combined_invoice($db, $eid, $anchor, $tasksDocId > 0 ? $tasksDocId : null);
    if (!empty($res['ok'])) {
        $flash = $res['message'] ?? 'Published.';
        if (!empty($res['canonical_url'])) {
            $flash .= ' Client page: ' . $res['canonical_url'];
        } elseif (!empty($res['public_url'])) {
            $flash .= ' Payment link: ' . $res['public_url'];
        }
        $flashType = 'ok';
    } else {
        $flash = $res['error'] ?? 'Publish failed.';
        $flashType = 'err';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'refresh_invoice_status') {
    requireCsrfToken();
    $outboundId = (int) ($_POST['outbound_id'] ?? 0);
    $res = dsc_billing_refresh_outbound_payment_status($db, $outboundId);
    if (!empty($res['ok'])) {
        $flash = 'Payment status refreshed: ' . (string) ($res['payment_status'] ?? 'updated') . '.';
        $flashType = 'ok';
    } else {
        $flash = $res['error'] ?? 'Refresh failed.';
        $flashType = 'err';
    }
}

$selE = (int) ($_GET['engagement_id'] ?? ($_POST['engagement_id'] ?? 0));
$selM = trim((string) ($_GET['anchor_month'] ?? ($_POST['anchor_month'] ?? gmdate('Y-m'))));
$selDoc = (int) ($_GET['tasks_document_id'] ?? ($_POST['tasks_document_id'] ?? 0));
if (!dsc_billing_valid_month($selM)) {
    $selM = gmdate('Y-m');
}

$preview = null;
if ($selE > 0 && dsc_billing_valid_month($selM)) {
    $preview = dsc_billing_combined_totals($db, $selE, $selM);
}

$engList = [];
$er = $db->query(
    'SELECT e.id, e.name AS en, c.name AS cn FROM engagements e '
    . 'JOIN companies c ON c.id = e.company_id WHERE e.status = \'active\' '
    . 'ORDER BY c.name COLLATE NOCASE, e.name COLLATE NOCASE'
);
while ($row = $er->fetchArray(SQLITE3_ASSOC)) {
    $engList[] = $row;
}

$rows = [];
$ir = $db->query(
    'SELECT o.*, e.name AS engagement_name, c.name AS company_name '
    . 'FROM outbound_invoices o '
    . 'JOIN engagements e ON e.id = o.engagement_id '
    . 'JOIN companies c ON c.id = e.company_id '
    . 'ORDER BY o.anchor_month DESC, c.name COLLATE NOCASE LIMIT 100'
);
while ($row = $ir->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $row;
}

$adminPageTitle = 'Invoices';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1>Combined monthly invoices</h1>
    <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrfField() ?>
        <button type="submit" class="btn">Logout</button>
    </form>
</div>

<p style="color:#8b949e;margin-top:0;">Retainer for <strong>anchor month</strong> M plus overage from prior month <strong>M−1</strong>. Requires a <strong>Tasks accounting document</strong> (markdown). Publishes separate Square payment links for retainer and overage when applicable, plus a canonical client breakdown page on this site.</p>

<?php if ($flash !== ''): ?>
    <div class="message <?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="info-box">
    <h2 style="margin-top:0;">Publish invoice</h2>
    <form method="GET" style="margin-bottom:1rem;">
        <label for="engagement_id">Engagement</label>
        <select id="engagement_id" name="engagement_id" required style="display:block;margin-bottom:.75rem;max-width:40rem;width:100%;">
            <option value="">Select…</option>
            <?php foreach ($engList as $eg): ?>
                <option value="<?= (int) $eg['id'] ?>" <?= $selE === (int) $eg['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $eg['cn'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string) $eg['en'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="anchor_month">Anchor month</label>
        <input id="anchor_month" name="anchor_month" type="month" required value="<?= htmlspecialchars($selM, ENT_QUOTES, 'UTF-8') ?>" style="display:block;margin-bottom:.75rem;">
        <label for="tasks_document_id">Tasks accounting document id</label>
        <input id="tasks_document_id" name="tasks_document_id" type="number" min="1" required
               value="<?= $selDoc > 0 ? (int) $selDoc : '' ?>"
               placeholder="e.g. 615"
               style="display:block;margin-bottom:.75rem;max-width:12rem;">
        <p style="color:#8b949e;font-size:.875rem;margin:-.35rem 0 .75rem;">Document from <code>tasks.decisionsciencecorp.com</code> — markdown body is snapshotted on publish and shown on the client invoice page.</p>
        <button type="submit" class="btn btn-outline">Preview totals</button>
    </form>

    <?php if ($preview !== null): ?>
        <?php if (isset($preview['error'])): ?>
            <div class="message err"><?= htmlspecialchars($preview['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
            <table style="width:100%;max-width:36rem;font-size:.875rem;border-collapse:collapse;">
                <tbody>
                    <tr><td style="padding:.25rem 0;">Anchor (retainer) month</td><td style="text-align:right;"><code><?= htmlspecialchars($preview['retainer_month'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                    <tr><td style="padding:.25rem 0;">Prior month (overage basis)</td><td style="text-align:right;"><code><?= htmlspecialchars((string) ($preview['overage_month'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                    <tr><td style="padding:.25rem 0;">Retainer</td><td style="text-align:right;">$<?= number_format($preview['retainer_amount_cents'] / 100, 2) ?></td></tr>
                    <tr><td style="padding:.25rem 0;">Overage</td><td style="text-align:right;">$<?= number_format($preview['overage_amount_cents'] / 100, 2) ?></td></tr>
                    <tr style="font-weight:600;"><td style="padding:.25rem 0;">Total</td><td style="text-align:right;">$<?= number_format($preview['total_cents'] / 100, 2) ?></td></tr>
                </tbody>
            </table>
            <?php if ($selE > 0 && $preview['total_cents'] > 0): ?>
                <form method="POST" style="margin-top:1rem;">
                    <?= csrfField() ?>
                    <input type="hidden" name="form" value="publish_invoice">
                    <input type="hidden" name="engagement_id" value="<?= (int) $selE ?>">
                    <input type="hidden" name="anchor_month" value="<?= htmlspecialchars($selM, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="tasks_document_id" value="<?= (int) $selDoc ?>">
                    <button type="submit" class="btn" <?= $selDoc <= 0 ? 'disabled title="Enter Tasks document id first"' : '' ?>>Publish to Square + client page</button>
                </form>
                <?php if ($selDoc <= 0): ?>
                    <p class="message err" style="margin-top:.75rem;margin-bottom:0;">Enter a Tasks document id before publishing.</p>
                <?php endif; ?>
            <?php elseif ($preview['total_cents'] <= 0): ?>
                <p class="message err" style="margin-top:.75rem;margin-bottom:0;">Nothing to bill for this pairing.</p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="info-box">
    <h2 style="margin-top:0;">Recent outbound invoices</h2>
    <?php if ($rows === []): ?>
        <p style="margin:0;color:#8b949e;">None yet.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid #30363d;">
                        <th style="padding:0.4rem;">Anchor</th>
                        <th style="padding:0.4rem;">Company / engagement</th>
                        <th style="padding:0.4rem;text-align:right;">Total</th>
                        <th style="padding:0.4rem;">Paid</th>
                        <th style="padding:0.4rem;">Client page</th>
                        <th style="padding:0.4rem;">Tasks doc</th>
                        <th style="padding:0.4rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $x): ?>
                        <tr style="border-bottom:1px solid #21262d;">
                            <td style="padding:0.35rem 0;"><code><?= htmlspecialchars((string) $x['anchor_month'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td style="padding:0.35rem 0;">
                                <?= htmlspecialchars((string) $x['company_name'], ENT_QUOTES, 'UTF-8') ?>
                                <span style="color:#8b949e;"> · <?= htmlspecialchars((string) $x['engagement_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td style="padding:0.35rem 0;text-align:right;">$<?= number_format(((int) $x['total_amount_cents']) / 100, 2) ?></td>
                            <td style="padding:0.35rem 0;"><code><?= htmlspecialchars((string) $x['payment_status'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td style="padding:0.35rem 0;">
                                <?php
                                $clientUrl = '';
                                if (!empty(trim((string) ($x['public_token'] ?? '')))) {
                                    $clientUrl = dsc_billing_canonical_invoice_url((string) $x['public_token']);
                                } elseif (!empty(trim((string) ($x['public_url'] ?? '')))) {
                                    $clientUrl = (string) $x['public_url'];
                                }
                                ?>
                                <?php if ($clientUrl !== ''): ?>
                                    <a href="<?= htmlspecialchars($clientUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.35rem 0;">
                                <?php if (!empty($x['tasks_document_id'])): ?>
                                    <code>#<?= (int) $x['tasks_document_id'] ?></code>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.35rem 0;">
                                <form method="POST" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="form" value="refresh_invoice_status">
                                    <input type="hidden" name="outbound_id" value="<?= (int) $x['id'] ?>">
                                    <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;">Refresh</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
