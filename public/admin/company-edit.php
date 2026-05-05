<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$db = getDbConnection();
$row = ['name' => '', 'billing_email' => '', 'square_customer_id' => '', 'notes' => ''];

if ($id > 0) {
    $st = $db->prepare('SELECT id, name, billing_email, square_customer_id, notes FROM companies WHERE id = :id');
    $st->bindValue(':id', $id, SQLITE3_INTEGER);
    $exe = $st->execute();
    $got = $exe ? $exe->fetchArray(SQLITE3_ASSOC) : false;
    if (!$got) {
        http_response_code(404);
        die('Company not found.');
    }
    $row = array_merge($row, $got);
}

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['billing_email'] ?? ''));
    $sq = trim((string) ($_POST['square_customer_id'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if ($name === '') {
        $err = 'Company name is required.';
    } else {
        $check = $db->prepare(
            $id > 0
                ? 'SELECT id FROM companies WHERE name = :n AND id != :id'
                : 'SELECT id FROM companies WHERE name = :n'
        );
        $check->bindValue(':n', $name, SQLITE3_TEXT);
        if ($id > 0) {
            $check->bindValue(':id', $id, SQLITE3_INTEGER);
        }
        $chkRes = $check->execute();
        if ($chkRes && $chkRes->fetchArray()) {
            $err = 'A company with this name already exists.';
        } else {
            if ($id > 0) {
                $up = $db->prepare(
                    'UPDATE companies SET name = :n, billing_email = :e, square_customer_id = :s, notes = :t, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $up->bindValue(':id', $id, SQLITE3_INTEGER);
            } else {
                $up = $db->prepare(
                    'INSERT INTO companies (name, billing_email, square_customer_id, notes) VALUES (:n, :e, :s, :t)'
                );
            }
            $up->bindValue(':n', $name, SQLITE3_TEXT);
            $up->bindValue(':e', $email, SQLITE3_TEXT);
            $up->bindValue(':s', $sq, SQLITE3_TEXT);
            $up->bindValue(':t', $notes, SQLITE3_TEXT);
            $up->execute();
            if ($id <= 0) {
                $id = (int) $db->lastInsertRowID();
            }
            header('Location: ' . dsc_invoicing_href('admin/engagements.php?company_id=' . $id));
            exit;
        }
    }
    if ($err !== '') {
        $row['name'] = $name;
        $row['billing_email'] = $email;
        $row['square_customer_id'] = $sq;
        $row['notes'] = $notes;
    }
}

$adminPageTitle = $id > 0 ? 'Edit company' : 'New company';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1><?= $id > 0 ? 'Edit company' : 'New company' ?></h1>
    <div class="stack">
        <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/companies.php'), ENT_QUOTES, 'UTF-8') ?>">Back</a>
        <?php if ($id > 0): ?>
            <a class="btn btn-outline" href="<?= htmlspecialchars(dsc_invoicing_href('admin/engagements.php?company_id=' . $id), ENT_QUOTES, 'UTF-8') ?>">Engagements</a>
        <?php endif; ?>
        <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
            <?= csrfField() ?>
            <button type="submit" class="btn">Logout</button>
        </form>
    </div>
</div>

<?php if ($err !== ''): ?><p class="error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($ok !== ''): ?><p class="success"><?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

<div class="info-box">
    <form method="POST">
        <?= csrfField() ?>
        <label for="name">Legal / display name</label>
        <input type="text" id="name" name="name" required value="<?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="billing_email">Billing email</label>
        <input type="email" id="billing_email" name="billing_email" value="<?= htmlspecialchars((string) ($row['billing_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="square_customer_id">Square customer ID</label>
        <input type="text" id="square_customer_id" name="square_customer_id" placeholder="optional" value="<?= htmlspecialchars((string) ($row['square_customer_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="notes">Notes</label>
        <textarea id="notes" name="notes"><?= htmlspecialchars((string) ($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        <button type="submit" class="btn" style="margin-top:1rem;">Save</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
