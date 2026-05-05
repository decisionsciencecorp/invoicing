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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $companyId = (int) ($_POST['company_id'] ?? $companyId);
    $name = trim((string) ($_POST['name'] ?? ''));
    $hourlyDollars = (float) ($_POST['hourly_rate_dollars'] ?? 100);
    $included = (int) ($_POST['included_hours_per_month'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['active', 'paused', 'closed'], true)
        ? $_POST['status']
        : 'active';
    $sub = trim((string) ($_POST['square_subscription_id'] ?? ''));

    if ($name === '') {
        $err = 'Engagement name is required.';
    } elseif ($hourlyDollars < 0 || $hourlyDollars > 50000) {
        $err = 'Hourly rate out of range.';
    } elseif ($included < 0 || $included > 1000) {
        $err = 'Included hours must be between 0 and 1000.';
    } else {
        $rateCents = (int) round($hourlyDollars * 100);
        if ($id > 0) {
            $up = $db->prepare(
                'UPDATE engagements SET company_id = :cid, name = :n, hourly_rate_cents = :r, ' .
                'included_hours_per_month = :ih, status = :st, square_subscription_id = :sq, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $up->bindValue(':id', $id, SQLITE3_INTEGER);
        } else {
            $up = $db->prepare(
                'INSERT INTO engagements (company_id, name, hourly_rate_cents, included_hours_per_month, status, square_subscription_id) ' .
                'VALUES (:cid, :n, :r, :ih, :st, :sq)'
            );
        }
        $up->bindValue(':cid', $companyId, SQLITE3_INTEGER);
        $up->bindValue(':n', $name, SQLITE3_TEXT);
        $up->bindValue(':r', $rateCents, SQLITE3_INTEGER);
        $up->bindValue(':ih', $included, SQLITE3_INTEGER);
        $up->bindValue(':st', $status, SQLITE3_TEXT);
        $up->bindValue(':sq', $sub, SQLITE3_TEXT);
        $up->execute();

        header('Location: ' . dsc_invoicing_href('admin/engagements.php?company_id=' . $companyId));
        exit;
    }

    $row['name'] = $name;
    $row['included_hours_per_month'] = $included;
    $row['status'] = $status;
    $row['square_subscription_id'] = $sub;
    $hourlyDollars = (float) ($_POST['hourly_rate_dollars'] ?? $hourlyDollars);
}

$adminPageTitle = $id > 0 ? 'Edit engagement' : 'New engagement';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
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
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="company_id" value="<?= (int) $companyId ?>">
        <label for="name">Engagement name</label>
        <input type="text" id="name" name="name" required value="<?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?>">
        <label for="hourly_rate_dollars">Hourly rate (USD)</label>
        <input type="number" step="0.01" min="0" id="hourly_rate_dollars" name="hourly_rate_dollars" required value="<?= htmlspecialchars((string) $hourlyDollars, ENT_QUOTES, 'UTF-8') ?>">
        <label for="included_hours_per_month">Included hours per month (retainer bucket)</label>
        <input type="number" min="0" max="1000" id="included_hours_per_month" name="included_hours_per_month" required value="<?= (int) $row['included_hours_per_month'] ?>">
        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach (['active', 'paused', 'closed'] as $s): ?>
                <option value="<?= $s ?>" <?= ($row['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <label for="square_subscription_id">Square subscription ID (optional)</label>
        <input type="text" id="square_subscription_id" name="square_subscription_id" value="<?= htmlspecialchars((string) ($row['square_subscription_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn" style="margin-top:1rem;">Save</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
