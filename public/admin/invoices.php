<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/tasks-dsc.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'attach_tasks_doc') {
    requireCsrfToken();
    $outboundId = (int) ($_POST['outbound_id'] ?? 0);
    $tasksDocId = (int) ($_POST['tasks_document_id'] ?? 0);
    dsc_billing_hydrate_legacy_outbound_row($db, $outboundId);
    $res = dsc_billing_attach_tasks_document_to_outbound($db, $outboundId, $tasksDocId);
    if (!empty($res['ok'])) {
        $flash = 'Accounting document attached. Client page: ' . ($res['canonical_url'] ?? '');
        $flashType = 'ok';
    } else {
        $flash = $res['error'] ?? 'Attach failed.';
        $flashType = 'err';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'backfill_psf_docs') {
    requireCsrfToken();
    $res = dsc_billing_backfill_psf_invoice_documents($db);
    if (!empty($res['ok'])) {
        $flash = 'Backfill complete — ' . (int) ($res['updated'] ?? 0) . ' invoice row(s) updated from PSF Tasks docs.';
        $flashType = 'ok';
    } else {
        $flash = 'Backfill partial: ' . implode('; ', $res['errors'] ?? []);
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

$accountingDocs = dsc_tasks_list_accounting_documents();
$tasksBase = dsc_tasks_api_config()['base_url'] !== ''
    ? dsc_tasks_api_config()['base_url']
    : 'https://tasks.decisionsciencecorp.com';

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
        <label for="tasks_document_id">Accounting document (Tasks)</label>
        <?php if ($accountingDocs !== []): ?>
            <select id="tasks_document_id" name="tasks_document_id" required style="display:block;margin-bottom:.35rem;max-width:40rem;width:100%;">
                <option value="">Select time log…</option>
                <?php foreach ($accountingDocs as $ad): ?>
                    <option value="<?= (int) $ad['id'] ?>" <?= $selDoc === (int) $ad['id'] ? 'selected' : '' ?>>
                        #<?= (int) $ad['id'] ?> — <?= htmlspecialchars((string) $ad['title'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p style="color:#8b949e;font-size:.875rem;margin:0 0 .75rem;">
                From <a href="<?= htmlspecialchars($tasksBase . '/admin/project.php?id=' . dsc_tasks_psf_project_id(), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">ProSpikeFlow Work</a> → docs → <code>client-facing</code> time logs.
            </p>
        <?php else: ?>
            <input id="tasks_document_id" name="tasks_document_id" type="number" min="1" required
                   value="<?= $selDoc > 0 ? (int) $selDoc : '' ?>"
                   placeholder="Tasks document id"
                   style="display:block;margin-bottom:.75rem;max-width:12rem;">
            <p style="color:#8b949e;font-size:.875rem;margin:-.35rem 0 .75rem;">Configure Tasks API under Square settings, or enter a document id from the PSF board.</p>
        <?php endif; ?>
        <p style="color:#8b949e;font-size:.875rem;margin:-.35rem 0 .75rem;">The markdown body becomes the <strong>client invoice page</strong> (snapshotted at publish).</p>
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
    <?php if ($accountingDocs !== []): ?>
        <form method="POST" style="margin-bottom:1rem;">
            <?= csrfField() ?>
            <input type="hidden" name="form" value="backfill_psf_docs">
            <button type="submit" class="btn btn-outline">Backfill PSF time logs onto prior invoices</button>
            <span style="color:#8b949e;font-size:.875rem;margin-left:.5rem;">Outbound #3 (June retainer) and #4 (May overage) → Tasks doc 332. All rows get client-page tokens.</span>
        </form>
    <?php endif; ?>
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
                        <th style="padding:0.4rem;">Client page (accounting MD)</th>
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
                                } elseif (!empty(trim((string) ($x['accounting_markdown'] ?? ''))) && !empty(trim((string) ($x['public_url'] ?? '')))) {
                                    $pu = (string) $x['public_url'];
                                    if (!str_contains($pu, 'squareup.com')) {
                                        $clientUrl = $pu;
                                    }
                                }
                                $docTitle = trim((string) ($x['tasks_document_title'] ?? ''));
                                $docId = (int) ($x['tasks_document_id'] ?? 0);
                                $hasMd = trim((string) ($x['accounting_markdown'] ?? '')) !== '';
                                ?>
                                <?php if ($clientUrl !== '' && $hasMd): ?>
                                    <a href="<?= htmlspecialchars($clientUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                        <?= htmlspecialchars($docTitle !== '' ? $docTitle : 'Client breakdown', ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                    <?php if ($docId > 0): ?>
                                        <span style="color:#8b949e;"> · </span>
                                        <a href="<?= htmlspecialchars(dsc_tasks_admin_document_url($docId), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" style="color:#8b949e;">Tasks #<?= $docId ?></a>
                                    <?php endif; ?>
                                <?php elseif ($clientUrl !== ''): ?>
                                    <a href="<?= htmlspecialchars($clientUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Client page</a>
                                    <span style="color:#8b949e;"> — attach time log for breakdown</span>
                                    <form method="POST" style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;margin-top:.35rem;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="form" value="attach_tasks_doc">
                                        <input type="hidden" name="outbound_id" value="<?= (int) $x['id'] ?>">
                                        <?php if ($accountingDocs !== []): ?>
                                            <select name="tasks_document_id" required style="max-width:14rem;font-size:.8rem;">
                                                <option value="">Attach time log…</option>
                                                <?php foreach ($accountingDocs as $ad): ?>
                                                    <option value="<?= (int) $ad['id'] ?>">#<?= (int) $ad['id'] ?> — <?= htmlspecialchars((string) $ad['title'], ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input type="number" name="tasks_document_id" min="1" required placeholder="Doc id" style="width:5rem;font-size:.8rem;">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-outline" style="padding:0.2rem 0.45rem;font-size:.75rem;">Attach</button>
                                    </form>
                                <?php elseif ($docId > 0): ?>
                                    <a href="<?= htmlspecialchars(dsc_tasks_admin_document_url($docId), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Tasks #<?= $docId ?></a>
                                    <span style="color:#8b949e;"> — not snapshotted yet</span>
                                <?php else: ?>
                                    <form method="POST" style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="form" value="attach_tasks_doc">
                                        <input type="hidden" name="outbound_id" value="<?= (int) $x['id'] ?>">
                                        <?php if ($accountingDocs !== []): ?>
                                            <select name="tasks_document_id" required style="max-width:14rem;font-size:.8rem;">
                                                <option value="">Attach time log…</option>
                                                <?php foreach ($accountingDocs as $ad): ?>
                                                    <option value="<?= (int) $ad['id'] ?>">#<?= (int) $ad['id'] ?> — <?= htmlspecialchars((string) $ad['title'], ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input type="number" name="tasks_document_id" min="1" required placeholder="Doc id" style="width:5rem;font-size:.8rem;">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-outline" style="padding:0.2rem 0.45rem;font-size:.75rem;">Attach</button>
                                    </form>
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
