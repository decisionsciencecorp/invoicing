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
$forceTab = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'publish_invoice') {
    requireCsrfToken();
    $eid = (int) ($_POST['engagement_id'] ?? 0);
    $anchor = trim((string) ($_POST['anchor_month'] ?? ''));
    $tasksDocId = (int) ($_POST['tasks_document_id'] ?? 0);
    $tierKey = trim((string) ($_POST['tier_key'] ?? 'tier1'));
    $res = dsc_billing_publish_combined_invoice(
        $db,
        $eid,
        $anchor,
        $tasksDocId > 0 ? $tasksDocId : null,
        $tierKey !== '' ? $tierKey : 'tier1'
    );
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
    $forceTab = 'list';
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
    $forceTab = 'list';
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
    $forceTab = (string) ($_POST['return_tab'] ?? 'list');
    if (!in_array($forceTab, ['list', 'unpaid'], true)) {
        $forceTab = 'list';
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cancel_invoice') {
    requireCsrfToken();
    $forceTab = (string) ($_POST['return_tab'] ?? 'list');
    if ($forceTab !== 'unpaid') {
        $forceTab = 'list';
    }
    $outboundId = (int) ($_POST['outbound_id'] ?? 0);
    require_once __DIR__ . '/../includes/audit.php';
    $res = dsc_billing_cancel_outbound_invoice($db, $outboundId);
    if (!empty($res['ok'])) {
        $flash = 'Invoice canceled in Square. Local status: ' . (string) ($res['payment_status'] ?? 'canceled') . '.';
        $flashType = 'ok';
        dsc_invoicing_audit_log(
            'outbound.cancel',
            'admin:' . (string) ($_SESSION['username'] ?? 'admin'),
            'outbound_invoice',
            (string) $outboundId,
            ['payment_status' => $res['payment_status'] ?? null]
        );
    } else {
        $flash = $res['error'] ?? 'Cancel failed.';
        $flashType = 'err';
    }
}

$selE = (int) ($_GET['engagement_id'] ?? ($_POST['engagement_id'] ?? 0));
$selM = trim((string) ($_GET['anchor_month'] ?? ($_POST['anchor_month'] ?? gmdate('Y-m'))));
$selDoc = (int) ($_GET['tasks_document_id'] ?? ($_POST['tasks_document_id'] ?? 0));
$selTier = dsc_billing_normalize_tier_key((string) ($_GET['tier_key'] ?? ($_POST['tier_key'] ?? 'tier1')));
if (!dsc_billing_valid_month($selM)) {
    $selM = gmdate('Y-m');
}

$tab = strtolower(trim((string) ($forceTab ?? $_GET['tab'] ?? 'publish')));
if (!in_array($tab, ['publish', 'list', 'unpaid'], true)) {
    $tab = 'publish';
}
$listPageSize = 25;
$listPage = max(1, (int) ($_GET['page'] ?? 1));

$selEngMode = 'hourly';
$selEngRow = null;
if ($selE > 0) {
    $est = $db->prepare(
        'SELECT id, COALESCE(billing_mode, \'hourly\') AS billing_mode, '
        . 'COALESCE(tier1_amount_cents, 0) AS tier1_amount_cents, '
        . 'COALESCE(tier2_amount_cents, 0) AS tier2_amount_cents FROM engagements WHERE id = :id'
    );
    $est->bindValue(':id', $selE, SQLITE3_INTEGER);
    $selEngRow = $est->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
    if ($selEngRow) {
        $selEngMode = (($selEngRow['billing_mode'] ?? '') === 'flat_tier') ? 'flat_tier' : 'hourly';
    }
}
$isFlatPreview = $selEngMode === 'flat_tier';

$preview = null;
if ($selE > 0 && dsc_billing_valid_month($selM)) {
    $preview = dsc_billing_combined_totals($db, $selE, $selM, $isFlatPreview ? $selTier : null);
}

$engList = [];
$er = $db->query(
    'SELECT e.id, e.name AS en, c.name AS cn, COALESCE(e.billing_mode, \'hourly\') AS billing_mode '
    . 'FROM engagements e '
    . 'JOIN companies c ON c.id = e.company_id WHERE e.status = \'active\' '
    . 'ORDER BY c.name COLLATE NOCASE, e.name COLLATE NOCASE'
);
while ($row = $er->fetchArray(SQLITE3_ASSOC)) {
    $engList[] = $row;
}

$listTotal = (int) $db->querySingle('SELECT COUNT(*) FROM outbound_invoices');
$listPages = max(1, (int) ceil($listTotal / $listPageSize));
if ($listPage > $listPages) {
    $listPage = $listPages;
}
$listOffset = ($listPage - 1) * $listPageSize;

$rows = [];
$ir = $db->prepare(
    'SELECT o.*, e.name AS engagement_name, c.name AS company_name '
    . 'FROM outbound_invoices o '
    . 'JOIN engagements e ON e.id = o.engagement_id '
    . 'JOIN companies c ON c.id = e.company_id '
    . 'ORDER BY o.anchor_month DESC, o.id DESC, c.name COLLATE NOCASE '
    . 'LIMIT :lim OFFSET :off'
);
$ir->bindValue(':lim', $listPageSize, SQLITE3_INTEGER);
$ir->bindValue(':off', $listOffset, SQLITE3_INTEGER);
$irq = $ir->execute();
while ($row = $irq->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $row;
}

$tasksSource = $selE > 0
    ? dsc_tasks_source_for_engagement($db, $selE)
    : ['project_id' => dsc_tasks_psf_project_id(), 'directory_path' => dsc_tasks_default_directory_path()];
$accountingDocs = $selE > 0
    ? dsc_tasks_list_accounting_documents_for_engagement($db, $selE)
    : dsc_tasks_list_accounting_documents();
$tasksBase = dsc_tasks_api_config()['base_url'] !== ''
    ? dsc_tasks_api_config()['base_url']
    : 'https://tasks.decisionsciencecorp.com';

$unpaidRows = $tab === 'unpaid' ? dsc_billing_list_unpaid_aging($db) : [];
$unpaidCount = $tab === 'unpaid'
    ? count($unpaidRows)
    : (int) $db->querySingle(
        "SELECT COUNT(*) FROM outbound_invoices WHERE LOWER(COALESCE(payment_status, '')) NOT IN ('paid', 'canceled')"
    );

$invoicesBase = dsc_invoicing_href('admin/invoices.php');
$publishTabUrl = $invoicesBase . (str_contains($invoicesBase, '?') ? '&' : '?') . 'tab=publish';
$listTabUrl = $invoicesBase . (str_contains($invoicesBase, '?') ? '&' : '?') . 'tab=list';
$unpaidTabUrl = $invoicesBase . (str_contains($invoicesBase, '?') ? '&' : '?') . 'tab=unpaid';
$listPageUrl = static function (int $page) use ($invoicesBase): string {
    $sep = str_contains($invoicesBase, '?') ? '&' : '?';
    return $invoicesBase . $sep . 'tab=list&page=' . max(1, $page);
};

$adminPageTitle = 'Invoices';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Invoices',
    'subtitle' => 'Hourly needs a Tasks accounting doc; flat/tier is Net 30 with optional doc.',
]);
?>

<?php if ($flash !== ''): ?>
    <div class="message <?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<nav class="tabbar" aria-label="Invoices sections">
    <a href="<?= htmlspecialchars($publishTabUrl, ENT_QUOTES, 'UTF-8') ?>" class="<?= $tab === 'publish' ? 'active' : '' ?>">Publish</a>
    <a href="<?= htmlspecialchars($listPageUrl(1), ENT_QUOTES, 'UTF-8') ?>" class="<?= $tab === 'list' ? 'active' : '' ?>">
        List<?= $listTotal > 0 ? ' (' . $listTotal . ')' : '' ?>
    </a>
    <a href="<?= htmlspecialchars($unpaidTabUrl, ENT_QUOTES, 'UTF-8') ?>" class="<?= $tab === 'unpaid' ? 'active' : '' ?>">
        Unpaid / AR<?= $unpaidCount > 0 ? ' (' . $unpaidCount . ')' : '' ?>
    </a>
</nav>

<?php if ($tab === 'publish'): ?>
<div class="info-box">
    <h2 style="margin-top:0;">Publish invoice</h2>
    <form method="GET" id="invoice-preview-form" style="margin-bottom:1rem;">
        <label for="engagement_id">Engagement</label>
        <select id="engagement_id" name="engagement_id" required style="display:block;margin-bottom:.75rem;max-width:40rem;width:100%;"
                data-reload-on-change="1">
            <option value="">Select…</option>
            <?php foreach ($engList as $eg): ?>
                <option value="<?= (int) $eg['id'] ?>"
                        data-billing-mode="<?= htmlspecialchars((string) ($eg['billing_mode'] ?? 'hourly'), ENT_QUOTES, 'UTF-8') ?>"
                        <?= $selE === (int) $eg['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $eg['cn'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string) $eg['en'], ENT_QUOTES, 'UTF-8') ?>
                    <?= (($eg['billing_mode'] ?? '') === 'flat_tier') ? ' [flat/tier]' : ' [hourly]' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="anchor_month">Billing month</label>
        <input id="anchor_month" name="anchor_month" type="month" required value="<?= htmlspecialchars($selM, ENT_QUOTES, 'UTF-8') ?>" style="display:block;margin-bottom:.75rem;">
        <?php if ($isFlatPreview): ?>
            <label for="tier_key">Program tier (default Tier 1)</label>
            <select id="tier_key" name="tier_key" style="display:block;margin-bottom:.75rem;max-width:20rem;">
                <option value="tier1" <?= $selTier === 'tier1' ? 'selected' : '' ?>>
                    Tier 1 — $<?= number_format(((int) ($selEngRow['tier1_amount_cents'] ?? 0)) / 100, 2) ?>
                </option>
                <option value="tier2" <?= $selTier === 'tier2' ? 'selected' : '' ?>>
                    Tier 2 — $<?= number_format(((int) ($selEngRow['tier2_amount_cents'] ?? 0)) / 100, 2) ?>
                </option>
            </select>
            <p style="color:#8b949e;font-size:.875rem;margin:0 0 .75rem;">
                Flat/tier invoices do <strong>not</strong> use a time log. Publish with the selected tier amount (Net 30).
            </p>
        <?php else: ?>
            <label for="tasks_document_id">Accounting document (Tasks) — required for hourly</label>
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
                    From Tasks project
                    <a href="<?= htmlspecialchars($tasksBase . '/admin/project.php?id=' . (int) $tasksSource['project_id'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">#<?= (int) $tasksSource['project_id'] ?></a>
                    → <code><?= htmlspecialchars((string) $tasksSource['directory_path'], ENT_QUOTES, 'UTF-8') ?></code>
                    (override per engagement). Markdown body becomes the <strong>client invoice page</strong>.
                </p>
            <?php else: ?>
                <input id="tasks_document_id" name="tasks_document_id" type="number" min="1" required
                       value="<?= $selDoc > 0 ? (int) $selDoc : '' ?>"
                       placeholder="Tasks document id"
                       style="display:block;margin-bottom:.75rem;max-width:12rem;">
                <p style="color:#8b949e;font-size:.875rem;margin:-.35rem 0 .75rem;">Configure Tasks API under Square settings, or enter a document id from the PSF board.</p>
            <?php endif; ?>
        <?php endif; ?>
        <button type="submit" class="btn btn-outline">Preview totals</button>
    </form>
    <script>
    (function () {
      var sel = document.getElementById('engagement_id');
      var form = document.getElementById('invoice-preview-form');
      if (!sel || !form) return;
      sel.addEventListener('change', function () {
        if (sel.value) form.submit();
      });
    })();
    </script>

    <?php if ($preview !== null): ?>
        <?php if (isset($preview['error'])): ?>
            <div class="message err"><?= htmlspecialchars($preview['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
            <?php $previewFlat = (($preview['billing_mode'] ?? '') === 'flat_tier'); ?>
            <table style="width:100%;max-width:36rem;font-size:.875rem;border-collapse:collapse;">
                <tbody>
                    <tr><td style="padding:.25rem 0;">Billing mode</td><td style="text-align:right;"><code><?= htmlspecialchars((string) ($preview['billing_mode'] ?? 'hourly'), ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                    <tr><td style="padding:.25rem 0;">Anchor month</td><td style="text-align:right;"><code><?= htmlspecialchars($preview['retainer_month'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                    <?php if ($previewFlat): ?>
                        <tr><td style="padding:.25rem 0;">Tier</td><td style="text-align:right;"><?= htmlspecialchars(dsc_billing_tier_label((string) ($preview['tier_key'] ?? 'tier1')), ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <tr><td style="padding:.25rem 0;">Program fee</td><td style="text-align:right;">$<?= number_format($preview['retainer_amount_cents'] / 100, 2) ?></td></tr>
                        <tr><td style="padding:.25rem 0;">Due</td><td style="text-align:right;">Net 30 (<?= htmlspecialchars((string) ($preview['fee_due_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</td></tr>
                    <?php else: ?>
                        <tr><td style="padding:.25rem 0;">Prior month (overage basis)</td><td style="text-align:right;"><code><?= htmlspecialchars((string) ($preview['overage_month'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                        <?php if (isset($preview['prior_month_hours'])): ?>
                            <tr><td style="padding:.25rem 0;">Prior month logged</td><td style="text-align:right;"><?= htmlspecialchars(number_format((float) $preview['prior_month_hours'], 2), ENT_QUOTES, 'UTF-8') ?> h</td></tr>
                            <tr><td style="padding:.25rem 0;">Included / overage hours</td><td style="text-align:right;"><?= (int) ($preview['included_hours_per_month'] ?? 0) ?> h / <?= htmlspecialchars(number_format((float) ($preview['overage_hours'] ?? 0), 2), ENT_QUOTES, 'UTF-8') ?> h</td></tr>
                        <?php endif; ?>
                        <tr><td style="padding:.25rem 0;">Retainer (anchor month)</td><td style="text-align:right;">$<?= number_format($preview['retainer_amount_cents'] / 100, 2) ?></td></tr>
                        <tr><td style="padding:.25rem 0;">Overage</td><td style="text-align:right;">$<?= number_format($preview['overage_amount_cents'] / 100, 2) ?></td></tr>
                    <?php endif; ?>
                    <tr style="font-weight:600;"><td style="padding:.25rem 0;">Total</td><td style="text-align:right;">$<?= number_format($preview['total_cents'] / 100, 2) ?></td></tr>
                </tbody>
            </table>
            <?php
            $draftQs = http_build_query(array_filter([
                'engagement_id' => $selE > 0 ? $selE : null,
                'anchor_month' => $selM !== '' ? $selM : null,
                'tasks_document_id' => $selDoc > 0 ? $selDoc : null,
                'tier_key' => $previewFlat ? $selTier : null,
            ], static fn ($v) => $v !== null && $v !== ''));
            $draftHref = 'invoice-draft.php?' . $draftQs;
            ?>
            <p style="margin:1rem 0 0;">
                <a class="btn btn-outline" href="<?= htmlspecialchars($draftHref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                    View draft details
                </a>
                <span style="color:#8b949e;font-size:.875rem;margin-left:.5rem;">Same layout as the client page — no Square links, nothing published.</span>
            </p>
            <?php if ($selE > 0 && $preview['total_cents'] > 0): ?>
                <?php $canPublish = $previewFlat || $selDoc > 0; ?>
                <form method="POST" style="margin-top:1rem;">
                    <?= csrfField() ?>
                    <input type="hidden" name="form" value="publish_invoice">
                    <input type="hidden" name="engagement_id" value="<?= (int) $selE ?>">
                    <input type="hidden" name="anchor_month" value="<?= htmlspecialchars($selM, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="tasks_document_id" value="<?= (int) $selDoc ?>">
                    <?php if ($previewFlat): ?>
                        <input type="hidden" name="tier_key" value="<?= htmlspecialchars($selTier, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn" <?= !$canPublish ? 'disabled title="Enter Tasks document id first"' : '' ?>>Publish to Square + client page</button>
                </form>
                <?php if (!$canPublish): ?>
                    <p class="message err" style="margin-top:.75rem;margin-bottom:0;">Select a Tasks time-log document before publishing this hourly invoice.</p>
                <?php endif; ?>
            <?php elseif ($preview['total_cents'] <= 0): ?>
                <p class="message err" style="margin-top:.75rem;margin-bottom:0;">Nothing to bill for this pairing.</p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php elseif ($tab === 'unpaid'): ?>
<div class="info-box">
    <h2 style="margin-top:0;">Unpaid / accounts receivable</h2>
    <p style="color:#8b949e;font-size:.875rem;margin:0 0 1rem;">
        Open invoices that are not paid or canceled, with aging buckets from due date (UTC).
        Click the period name (or <strong>View</strong>) to open the client invoice page.
    </p>
    <?php if ($unpaidRows === []): ?>
        <p style="margin:0;color:#8b949e;">Nothing unpaid.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid #30363d;">
                        <th style="padding:0.4rem;">Aging</th>
                        <th style="padding:0.4rem;">Due</th>
                        <th style="padding:0.4rem;">Covers</th>
                        <th style="padding:0.4rem;">Company / engagement</th>
                        <th style="padding:0.4rem;text-align:right;">Total</th>
                        <th style="padding:0.4rem;">Status</th>
                        <th style="padding:0.4rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unpaidRows as $x): ?>
                        <?php $unpaidClientUrl = dsc_billing_client_page_url($x); ?>
                        <tr style="border-bottom:1px solid #21262d;">
                            <td style="padding:0.35rem 0;"><code><?= htmlspecialchars((string) $x['aging_bucket'], ENT_QUOTES, 'UTF-8') ?></code>
                                <?php if ($x['days_past_due'] !== null): ?>
                                    <span style="color:#8b949e;"> · <?= (int) $x['days_past_due'] ?>d</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.35rem 0;"><?= htmlspecialchars((string) ($x['due_date'] !== '' ? $x['due_date'] : '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;"><?php inv_render_outbound_period_cell($x, $unpaidClientUrl !== '' ? $unpaidClientUrl : null); ?></td>
                            <td style="padding:0.35rem 0;">
                                <?= htmlspecialchars((string) $x['company_name'], ENT_QUOTES, 'UTF-8') ?>
                                <span style="color:#8b949e;"> · <?= htmlspecialchars((string) $x['engagement_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td style="padding:0.35rem 0;text-align:right;">$<?= number_format(((int) $x['total_amount_cents']) / 100, 2) ?></td>
                            <td style="padding:0.35rem 0;"><span class="<?= htmlspecialchars(inv_status_pill_class((string) $x['payment_status']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $x['payment_status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td style="padding:0.35rem 0;">
                                <?php if ($unpaidClientUrl !== ''): ?>
                                    <a class="btn btn-outline" style="padding:0.25rem 0.5rem;display:inline-block;text-decoration:none;"
                                       href="<?= htmlspecialchars($unpaidClientUrl, ENT_QUOTES, 'UTF-8') ?>"
                                       target="_blank" rel="noopener">View</a>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;<?= $unpaidClientUrl !== '' ? 'margin-left:.25rem;' : '' ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="form" value="refresh_invoice_status">
                                    <input type="hidden" name="return_tab" value="unpaid">
                                    <input type="hidden" name="outbound_id" value="<?= (int) $x['id'] ?>">
                                    <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;">Refresh</button>
                                </form>
                                <?php if (strtolower((string) $x['payment_status']) !== 'paid'): ?>
                                    <form method="POST" style="display:inline;margin-left:.25rem;"
                                          onsubmit="return confirm('Cancel this invoice in Square?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="form" value="cancel_invoice">
                                        <input type="hidden" name="return_tab" value="unpaid">
                                        <input type="hidden" name="outbound_id" value="<?= (int) $x['id'] ?>">
                                        <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php else: /* list tab */ ?>
<div class="info-box">
    <h2 style="margin-top:0;">Invoice list</h2>
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
        <p style="color:#8b949e;font-size:.875rem;margin:0 0 .75rem;">
            Showing <?= (int) ($listOffset + 1) ?>–<?= (int) min($listOffset + count($rows), $listTotal) ?>
            of <?= (int) $listTotal ?>
            · page <?= (int) $listPage ?> of <?= (int) $listPages ?>
        </p>
        <p style="color:#8b949e;font-size:.8125rem;margin:0 0 .75rem;">
            <strong>Covers</strong> is what the invoice is for (e.g. “June 2026 overage”).
            Codes like <code>2026-07-O</code> are internal: <code>-R</code> = retainer for that month,
            <code>-O</code> = overage for the <em>prior</em> month (not “July overage”).
        </p>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid #30363d;">
                        <th style="padding:0.4rem;">Covers</th>
                        <th style="padding:0.4rem;">Company / engagement</th>
                        <th style="padding:0.4rem;">Mode</th>
                        <th style="padding:0.4rem;text-align:right;">Total</th>
                        <th style="padding:0.4rem;">Paid</th>
                        <th style="padding:0.4rem;">Client page</th>
                        <th style="padding:0.4rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $x): ?>
                        <?php
                        $rowMode = (($x['billing_mode'] ?? '') === 'flat_tier') ? 'flat_tier' : 'hourly';
                        $isFlatRow = $rowMode === 'flat_tier';
                        $clientUrl = dsc_billing_client_page_url($x);
                        $docTitle = trim((string) ($x['tasks_document_title'] ?? ''));
                        $docId = (int) ($x['tasks_document_id'] ?? 0);
                        $hasMd = trim((string) ($x['accounting_markdown'] ?? '')) !== '';
                        ?>
                        <tr style="border-bottom:1px solid #21262d;">
                            <td style="padding:0.35rem 0;"><?php inv_render_outbound_period_cell($x, $clientUrl !== '' ? $clientUrl : null); ?></td>
                            <td style="padding:0.35rem 0;">
                                <?= htmlspecialchars((string) $x['company_name'], ENT_QUOTES, 'UTF-8') ?>
                                <span style="color:#8b949e;"> · <?= htmlspecialchars((string) $x['engagement_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td style="padding:0.35rem 0;">
                                <?= $isFlatRow
                                    ? 'Flat / tier' . (!empty($x['tier_key']) ? ' (' . htmlspecialchars(dsc_billing_tier_label((string) $x['tier_key']), ENT_QUOTES, 'UTF-8') . ')' : '')
                                    : 'Hourly' ?>
                            </td>
                            <td style="padding:0.35rem 0;text-align:right;">$<?= number_format(((int) $x['total_amount_cents']) / 100, 2) ?></td>
                            <td style="padding:0.35rem 0;"><span class="<?= htmlspecialchars(inv_status_pill_class((string) $x['payment_status']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $x['payment_status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td style="padding:0.35rem 0;">
                                <?php if ($isFlatRow): ?>
                                    <?php if ($clientUrl !== ''): ?>
                                        <a href="<?= htmlspecialchars($clientUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Client page</a>
                                    <?php else: ?>
                                        <span style="color:#8b949e;">—</span>
                                    <?php endif; ?>
                                <?php elseif ($clientUrl !== '' && $hasMd): ?>
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
                                <?php
                                $ps = strtolower((string) ($x['payment_status'] ?? ''));
                                if (!in_array($ps, ['paid', 'canceled'], true)):
                                ?>
                                    <form method="POST" style="display:inline;margin-left:.25rem;"
                                          onsubmit="return confirm('Cancel this invoice in Square?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="form" value="cancel_invoice">
                                        <input type="hidden" name="return_tab" value="list">
                                        <input type="hidden" name="outbound_id" value="<?= (int) $x['id'] ?>">
                                        <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($listPages > 1): ?>
            <nav aria-label="Invoice list pages" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-top:1rem;">
                <?php if ($listPage > 1): ?>
                    <a class="btn btn-outline" href="<?= htmlspecialchars($listPageUrl($listPage - 1), ENT_QUOTES, 'UTF-8') ?>">← Prev</a>
                <?php endif; ?>
                <?php
                $windowStart = max(1, $listPage - 2);
                $windowEnd = min($listPages, $listPage + 2);
                for ($p = $windowStart; $p <= $windowEnd; $p++):
                ?>
                    <a href="<?= htmlspecialchars($listPageUrl($p), ENT_QUOTES, 'UTF-8') ?>"
                       style="padding:.25rem .55rem;text-decoration:none;border-radius:4px;<?= $p === $listPage ? 'background:#1f6feb;color:#fff;' : 'color:#58a6ff;' ?>">
                        <?= (int) $p ?>
                    </a>
                <?php endfor; ?>
                <?php if ($listPage < $listPages): ?>
                    <a class="btn btn-outline" href="<?= htmlspecialchars($listPageUrl($listPage + 1), ENT_QUOTES, 'UTF-8') ?>">Next →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
