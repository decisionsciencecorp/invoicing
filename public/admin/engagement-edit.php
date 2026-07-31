<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$companyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : 0;

$db = getDbConnection();
$row = [
    'name' => '',
    'hourly_rate_cents' => 10000,
    'included_hours_per_month' => 0,
    'status' => 'active',
    'square_subscription_id' => '',
    'billing_mode' => 'hourly',
    'tier1_amount_cents' => 0,
    'tier2_amount_cents' => 0,
    'tasks_project_id' => null,
    'tasks_directory_path' => '',
];

if ($id > 0) {
    $st = $db->prepare('SELECT * FROM engagements WHERE id = :id');
    $st->bindValue(':id', $id, SQLITE3_INTEGER);
    $ex = $st->execute();
    $got = $ex ? $ex->fetchArray(SQLITE3_ASSOC) : false;
    if (!$got) {
        http_response_code(404);
        die('Engagement not found.');
    }
    $row = array_merge($row, $got);
    $companyId = (int) $got['company_id'];
} elseif ($companyId <= 0) {
    http_response_code(400);
    die('company_id or id required');
}

$cst = $db->prepare('SELECT id, name FROM companies WHERE id = :id');
$cst->bindValue(':id', $companyId, SQLITE3_INTEGER);
$cr = $cst->execute();
$company = $cr ? $cr->fetchArray(SQLITE3_ASSOC) : false;
if (!$company) {
    http_response_code(404);
    die('Company not found.');
}

$err = '';
$hourlyDollars = ((int) $row['hourly_rate_cents']) / 100;
$tier1Dollars = ((int) ($row['tier1_amount_cents'] ?? 0)) / 100;
$tier2Dollars = ((int) ($row['tier2_amount_cents'] ?? 0)) / 100;
$billingMode = (string) ($row['billing_mode'] ?? 'hourly');
if ($billingMode !== 'flat_tier') {
    $billingMode = 'hourly';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $companyId = (int) ($_POST['company_id'] ?? $companyId);
    $name = trim((string) ($_POST['name'] ?? ''));
    $billingMode = ($_POST['billing_mode'] ?? '') === 'flat_tier' ? 'flat_tier' : 'hourly';
    $hourlyDollars = (float) ($_POST['hourly_rate_dollars'] ?? 100);
    $included = (int) ($_POST['included_hours_per_month'] ?? 0);
    $tier1Dollars = (float) ($_POST['tier1_amount_dollars'] ?? 0);
    $tier2Dollars = (float) ($_POST['tier2_amount_dollars'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['active', 'paused', 'closed'], true)
        ? $_POST['status']
        : 'active';
    $sub = trim((string) ($_POST['square_subscription_id'] ?? ''));
    $tasksProjectRaw = trim((string) ($_POST['tasks_project_id'] ?? ''));
    $tasksProjectId = ($tasksProjectRaw !== '' && ctype_digit($tasksProjectRaw)) ? (int) $tasksProjectRaw : null;
    $tasksDirectory = trim((string) ($_POST['tasks_directory_path'] ?? ''));

    if ($name === '') {
        $err = 'Engagement name is required.';
    } elseif ($billingMode === 'hourly' && ($hourlyDollars < 0 || $hourlyDollars > 50000)) {
        $err = 'Hourly rate out of range.';
    } elseif ($billingMode === 'hourly' && ($included < 0 || $included > 1000)) {
        $err = 'Included hours must be between 0 and 1000.';
    } elseif ($billingMode === 'flat_tier' && ($tier1Dollars < 0 || $tier1Dollars > 1000000 || $tier2Dollars < 0 || $tier2Dollars > 1000000)) {
        $err = 'Tier amounts out of range.';
    } elseif ($billingMode === 'flat_tier' && $tier1Dollars <= 0 && $tier2Dollars <= 0) {
        $err = 'Set at least one tier amount greater than zero.';
    } else {
        $rateCents = (int) round($hourlyDollars * 100);
        $t1Cents = (int) round($tier1Dollars * 100);
        $t2Cents = (int) round($tier2Dollars * 100);
        if ($id > 0) {
            $up = $db->prepare(
                'UPDATE engagements SET company_id = :cid, name = :n, hourly_rate_cents = :r, '
                . 'included_hours_per_month = :ih, status = :st, square_subscription_id = :sq, '
                . 'billing_mode = :bm, tier1_amount_cents = :t1, tier2_amount_cents = :t2, '
                . 'tasks_project_id = :tp, tasks_directory_path = :td, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $up->bindValue(':id', $id, SQLITE3_INTEGER);
        } else {
            $up = $db->prepare(
                'INSERT INTO engagements (company_id, name, hourly_rate_cents, included_hours_per_month, status, '
                . 'square_subscription_id, billing_mode, tier1_amount_cents, tier2_amount_cents, '
                . 'tasks_project_id, tasks_directory_path) '
                . 'VALUES (:cid, :n, :r, :ih, :st, :sq, :bm, :t1, :t2, :tp, :td)'
            );
        }
        $up->bindValue(':cid', $companyId, SQLITE3_INTEGER);
        $up->bindValue(':n', $name, SQLITE3_TEXT);
        $up->bindValue(':r', $rateCents, SQLITE3_INTEGER);
        $up->bindValue(':ih', $included, SQLITE3_INTEGER);
        $up->bindValue(':st', $status, SQLITE3_TEXT);
        $up->bindValue(':sq', $sub, SQLITE3_TEXT);
        $up->bindValue(':bm', $billingMode, SQLITE3_TEXT);
        $up->bindValue(':t1', $t1Cents, SQLITE3_INTEGER);
        $up->bindValue(':t2', $t2Cents, SQLITE3_INTEGER);
        if ($tasksProjectId === null) {
            $up->bindValue(':tp', null, SQLITE3_NULL);
        } else {
            $up->bindValue(':tp', $tasksProjectId, SQLITE3_INTEGER);
        }
        $up->bindValue(':td', $tasksDirectory, SQLITE3_TEXT);
        $up->execute();

        header('Location: ' . dsc_invoicing_href('admin/engagements.php?company_id=' . $companyId));
        exit;
    }

    $row['name'] = $name;
    $row['included_hours_per_month'] = $included;
    $row['status'] = $status;
    $row['square_subscription_id'] = $sub;
    $row['billing_mode'] = $billingMode;
    $hourlyDollars = (float) ($_POST['hourly_rate_dollars'] ?? $hourlyDollars);
}

$adminPageTitle = $id > 0 ? 'Edit engagement' : 'New engagement';
require_once __DIR__ . '/includes/header.php';
?>

<div class="nav-row">
    <h1><?= $id > 0 ? 'Edit engagement' : 'New engagement' ?></h1>
    <div class="stack">
        <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/engagements.php?company_id=' . $companyId), ENT_QUOTES, 'UTF-8') ?>">Back</a>
        <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
            <?= csrfField() ?>
            <button type="submit" class="btn">Logout</button>
        </form>
    </div>
</div>

<p style="color:#8b949e;">Company: <strong><?= htmlspecialchars((string) $company['name'], ENT_QUOTES, 'UTF-8') ?></strong></p>

<?php if ($err !== ''): ?><p class="error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

<div class="info-box">
    <form method="POST" id="engagement-form">
        <?= csrfField() ?>
        <input type="hidden" name="company_id" value="<?= (int) $companyId ?>">
        <label for="name">Engagement name</label>
        <input type="text" id="name" name="name" required value="<?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?>">

        <label for="billing_mode" style="margin-top:0.75rem;">Billing mode</label>
        <select id="billing_mode" name="billing_mode">
            <option value="hourly" <?= $billingMode === 'hourly' ? 'selected' : '' ?>>Hourly retainer + overage</option>
            <option value="flat_tier" <?= $billingMode === 'flat_tier' ? 'selected' : '' ?>>Flat / tier monthly fee</option>
        </select>

        <div id="hourly-fields" style="<?= $billingMode === 'flat_tier' ? 'display:none;' : '' ?>">
            <label for="hourly_rate_dollars" style="margin-top:0.75rem;">Hourly rate (USD)</label>
            <input type="number" step="0.01" min="0" id="hourly_rate_dollars" name="hourly_rate_dollars" value="<?= htmlspecialchars((string) $hourlyDollars, ENT_QUOTES, 'UTF-8') ?>">
            <label for="included_hours_per_month" style="margin-top:0.75rem;">Included hours per month (retainer bucket)</label>
            <input type="number" min="0" max="1000" id="included_hours_per_month" name="included_hours_per_month" value="<?= (int) $row['included_hours_per_month'] ?>">
        </div>

        <div id="flat-fields" style="<?= $billingMode === 'flat_tier' ? '' : 'display:none;' ?>">
            <label for="tier1_amount_dollars" style="margin-top:0.75rem;">Tier 1 invoice amount (USD)</label>
            <input type="number" step="0.01" min="0" id="tier1_amount_dollars" name="tier1_amount_dollars" value="<?= htmlspecialchars((string) $tier1Dollars, ENT_QUOTES, 'UTF-8') ?>">
            <label for="tier2_amount_dollars" style="margin-top:0.75rem;">Tier 2 invoice amount (USD)</label>
            <input type="number" step="0.01" min="0" id="tier2_amount_dollars" name="tier2_amount_dollars" value="<?= htmlspecialchars((string) $tier2Dollars, ENT_QUOTES, 'UTF-8') ?>">
            <p style="font-size:0.875rem;color:#8b949e;margin:0.35rem 0 0;">Pick which tier to bill each month on the Invoices page (default Tier 1). Flat invoices are Net 30.</p>
        </div>

        <label for="status" style="margin-top:0.75rem;">Status</label>
        <select id="status" name="status">
            <?php foreach (['active', 'paused', 'closed'] as $s): ?>
                <option value="<?= $s ?>" <?= ($row['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <label for="square_subscription_id" style="margin-top:0.75rem;">Square subscription ID (optional)</label>
        <input type="text" id="square_subscription_id" name="square_subscription_id" value="<?= htmlspecialchars((string) ($row['square_subscription_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <h3 style="margin:1.25rem 0 0.5rem;font-size:1rem;">Tasks accounting docs (hourly publish picker)</h3>
        <p style="color:#8b949e;font-size:.875rem;margin:0 0 .5rem;">
            Leave blank to use the global default (ProSpikeFlow Work / <code>client-facing</code>).
            Set a Tasks directory project id + folder to pull time logs for this engagement instead.
        </p>
        <label for="tasks_project_id">Tasks project id (optional)</label>
        <input type="number" min="1" id="tasks_project_id" name="tasks_project_id"
               value="<?= htmlspecialchars((string) (($row['tasks_project_id'] ?? '') !== '' && (int) ($row['tasks_project_id'] ?? 0) > 0 ? (int) $row['tasks_project_id'] : ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="tasks_directory_path" style="margin-top:0.75rem;">Tasks directory path (optional)</label>
        <input type="text" id="tasks_directory_path" name="tasks_directory_path" placeholder="client-facing"
               value="<?= htmlspecialchars((string) ($row['tasks_directory_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <button type="submit" class="btn" style="margin-top:1rem;">Save</button>
    </form>
</div>
<script>
(function () {
  var mode = document.getElementById('billing_mode');
  var hourly = document.getElementById('hourly-fields');
  var flat = document.getElementById('flat-fields');
  function sync() {
    var isFlat = mode.value === 'flat_tier';
    hourly.style.display = isFlat ? 'none' : '';
    flat.style.display = isFlat ? '' : 'none';
  }
  mode.addEventListener('change', sync);
  sync();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
