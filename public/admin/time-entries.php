<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$db = getDbConnection();

$filterEngagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;

/** @var list<array{id:int,name:string,company_name:string}> $engChoices */
$engChoices = [];
$eq = $db->query(
    'SELECT e.id, e.name, c.name AS company_name FROM engagements e ' .
    'JOIN companies c ON c.id = e.company_id ORDER BY c.name COLLATE NOCASE, e.name COLLATE NOCASE'
);
while ($rw = $eq->fetchArray(SQLITE3_ASSOC)) {
    $engChoices[] = $rw;
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    requireCsrfToken();
    $eid = (int) ($_POST['engagement_id'] ?? 0);
    $worked = trim((string) ($_POST['worked_date'] ?? ''));
    $hours = (float) ($_POST['hours'] ?? 0);
    $memo = trim((string) ($_POST['memo'] ?? ''));

    if ($eid <= 0) {
        $err = 'Select an engagement.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $worked)) {
        $err = 'Worked date must be YYYY-MM-DD.';
    } elseif ($hours <= 0 || $hours > 744) {
        $err = 'Hours must be greater than zero and at most 744.';
    } else {
        $period = substr($worked, 0, 7);
        $ins = $db->prepare(
            'INSERT INTO time_entries (engagement_id, worked_date, hours, memo, billing_period_month) ' .
            'VALUES (:e, :w, :h, :m, :p)'
        );
        $ins->bindValue(':e', $eid, SQLITE3_INTEGER);
        $ins->bindValue(':w', $worked, SQLITE3_TEXT);
        $ins->bindValue(':h', $hours, SQLITE3_FLOAT);
        $ins->bindValue(':m', $memo, SQLITE3_TEXT);
        $ins->bindValue(':p', $period, SQLITE3_TEXT);
        $ins->execute();
        $redir = 'admin/time-entries.php?saved=1';
        if ($eid > 0) {
            $redir .= '&engagement_id=' . $eid;
        }
        header('Location: ' . dsc_invoicing_href($redir));
        exit;
    }
}

$list = [];
$sql =
    'SELECT t.id, t.worked_date, t.hours, t.memo, t.billing_period_month, t.created_at, ' .
    'e.name AS engagement_name, c.name AS company_name ' .
    'FROM time_entries t JOIN engagements e ON e.id = t.engagement_id ' .
    'JOIN companies c ON c.id = e.company_id ';
if ($filterEngagementId > 0) {
    $stmt = $db->prepare($sql . 'WHERE t.engagement_id = :eid ORDER BY t.worked_date DESC, t.id DESC LIMIT 200');
    $stmt->bindValue(':eid', $filterEngagementId, SQLITE3_INTEGER);
    $lr = $stmt->execute();
} else {
    $lr = $db->query($sql . 'ORDER BY t.worked_date DESC, t.id DESC LIMIT 100');
}

while ($row = $lr->fetchArray(SQLITE3_ASSOC)) {
    $list[] = $row;
}

$selectEngId = $filterEngagementId;
if ($err !== '' && ($_POST['form'] ?? '') === 'add' && isset($_POST['engagement_id'])) {
    $selectEngId = (int) $_POST['engagement_id'];
}

$adminPageTitle = 'Time entries';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Time entries',
    'subtitle' => 'Local time capture',
]);
?>

<?php if (isset($_GET['saved'])): ?>
    <p class="success">Entry saved.</p>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <p class="error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<p class="text-secondary mb-3">
    Filter:
    <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>">All recent</a>
    <?php if ($filterEngagementId > 0): ?>
        · engagement #<?= (int) $filterEngagementId ?>
        · <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>">clear</a>
    <?php endif; ?>
</p>

<div class="info-box">
    <h2 class="h5 mt-0">Log time</h2>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="add">
        <label for="engagement_id">Engagement</label>
        <select id="engagement_id" name="engagement_id" class="form-select" required>
            <option value="">— Select —</option>
            <?php foreach ($engChoices as $ec): ?>
                <option value="<?= (int) $ec['id'] ?>" <?= $selectEngId === (int) $ec['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $ec['company_name'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string) $ec['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="worked_date">Worked date</label>
        <input class="form-control" type="date" id="worked_date" name="worked_date" required value="<?= htmlspecialchars((string) ($_POST['worked_date'] ?? gmdate('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>">
        <label for="hours">Hours</label>
        <input class="form-control" type="number" step="0.25" min="0.25" max="744" id="hours" name="hours" required value="<?= htmlspecialchars((string) ($_POST['hours'] ?? '1'), ENT_QUOTES, 'UTF-8') ?>">
        <label for="memo">Memo</label>
        <textarea class="form-control" id="memo" name="memo"><?= htmlspecialchars((string) ($_POST['memo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        <p class="text-secondary small">Billing month is derived from worked date (UTC month).</p>
        <button type="submit" class="btn btn-primary mt-2">Save entry</button>
    </form>
</div>

<div class="info-box mt-4">
    <h2 class="h5 mt-0">Recent entries</h2>
    <?php if ($list === []): ?>
        <p>No entries yet.</p>
    <?php else: ?>
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Hours</th>
                    <th>Period</th>
                    <th>Company / engagement</th>
                    <th>Memo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $t['worked_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $t['hours'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $t['billing_period_month'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $t['company_name'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string) $t['engagement_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $t['memo'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
