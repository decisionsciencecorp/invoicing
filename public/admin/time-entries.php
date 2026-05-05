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
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1>Time entries</h1>
    <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrfField() ?>
        <button type="submit" class="btn">Logout</button>
    </form>
</div>

<?php if (isset($_GET['saved'])): ?>
    <p class="success">Entry saved.</p>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <p class="error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<p style="color:#8b949e;">
    Filter:
    <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>">All recent</a>
    <?php if ($filterEngagementId > 0): ?>
        · engagement #<?= (int) $filterEngagementId ?>
        · <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/time-entries.php'), ENT_QUOTES, 'UTF-8') ?>">clear</a>
    <?php endif; ?>
</p>

<div class="info-box">
    <h2 style="margin-top:0;">Log time</h2>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="add">
        <label for="engagement_id">Engagement</label>
        <select id="engagement_id" name="engagement_id" required>
            <option value="">— Select —</option>
            <?php foreach ($engChoices as $ec): ?>
                <option value="<?= (int) $ec['id'] ?>" <?= $selectEngId === (int) $ec['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $ec['company_name'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string) $ec['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="worked_date">Worked date</label>
        <input type="date" id="worked_date" name="worked_date" required value="<?= htmlspecialchars((string) ($_POST['worked_date'] ?? gmdate('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>">
        <label for="hours">Hours</label>
        <input type="number" step="0.25" min="0.25" max="744" id="hours" name="hours" required value="<?= htmlspecialchars((string) ($_POST['hours'] ?? '1'), ENT_QUOTES, 'UTF-8') ?>">
        <label for="memo">Memo</label>
        <textarea id="memo" name="memo"><?= htmlspecialchars((string) ($_POST['memo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        <p style="color:#8b949e;font-size:0.875rem;">Billing month is derived from worked date (UTC month).</p>
        <button type="submit" class="btn">Save entry</button>
    </form>
</div>

<div class="info-box" style="margin-top:1.5rem;">
    <h2 style="margin-top:0;">Recent entries</h2>
    <?php if ($list === []): ?>
        <p>No entries yet.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid #30363d;">
                    <th style="padding:0.35rem 0;">Date</th>
                    <th style="padding:0.35rem 0;">Hours</th>
                    <th style="padding:0.35rem 0;">Period</th>
                    <th style="padding:0.35rem 0;">Company / engagement</th>
                    <th style="padding:0.35rem 0;">Memo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $t): ?>
                    <tr style="border-bottom:1px solid #21262d;">
                        <td style="padding:0.3rem 0;"><?= htmlspecialchars((string) $t['worked_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:0.3rem 0;"><?= htmlspecialchars((string) $t['hours'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:0.3rem 0;"><?= htmlspecialchars((string) $t['billing_period_month'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:0.3rem 0;"><?= htmlspecialchars((string) $t['company_name'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string) $t['engagement_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:0.3rem 0;"><?= htmlspecialchars((string) $t['memo'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
