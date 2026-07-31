<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$db = getDbConnection();
$message = '';
$messageType = 'ok';
$sessionId = (int) ($_SESSION['user_id'] ?? 0);
$section = (string) ($_GET['section'] ?? 'list');
if (!in_array($section, ['list', 'create'], true)) {
    $section = 'list';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $section = 'create';
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        $res = dsc_invoicing_admin_create_user($u, $p);
        if ($res['success']) {
            $message = 'User created.';
            $messageType = 'ok';
            $section = 'list';
        } else {
            $message = $res['error'] ?? 'Create failed.';
            $messageType = 'err';
        }
    } elseif ($action === 'set_password') {
        $section = 'list';
        $tid = (int) ($_POST['user_id'] ?? 0);
        $np = (string) ($_POST['new_password'] ?? '');
        $res = dsc_invoicing_admin_set_user_password($tid, $np);
        if ($res['success']) {
            $message = 'Password updated for user #' . $tid . '.';
            $messageType = 'ok';
        } else {
            $message = $res['error'] ?? 'Failed.';
            $messageType = 'err';
        }
    } elseif ($action === 'toggle_active') {
        $section = 'list';
        $tid = (int) ($_POST['user_id'] ?? 0);
        if ($tid === $sessionId) {
            $message = 'You cannot deactivate your own account.';
            $messageType = 'err';
        } else {
            $st = $db->prepare('SELECT is_active FROM admin_users WHERE id = :id');
            $st->bindValue(':id', $tid, SQLITE3_INTEGER);
            $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
            if (!$row) {
                $message = 'User not found.';
                $messageType = 'err';
            } else {
                $next = ((int) ($row['is_active'] ?? 1)) === 1 ? 0 : 1;
                if ($next === 0) {
                    $cnt = $db->querySingle('SELECT COUNT(*) FROM admin_users WHERE is_active = 1');
                    if ((int) $cnt <= 1 && (int) ($row['is_active'] ?? 1) === 1) {
                        $message = 'Cannot deactivate the last active admin.';
                        $messageType = 'err';
                    } else {
                        $up = $db->prepare('UPDATE admin_users SET is_active = :a WHERE id = :id');
                        $up->bindValue(':a', $next, SQLITE3_INTEGER);
                        $up->bindValue(':id', $tid, SQLITE3_INTEGER);
                        $up->execute();
                        $message = 'User updated.';
                        $messageType = 'ok';
                    }
                } else {
                    $up = $db->prepare('UPDATE admin_users SET is_active = :a WHERE id = :id');
                    $up->bindValue(':a', $next, SQLITE3_INTEGER);
                    $up->bindValue(':id', $tid, SQLITE3_INTEGER);
                    $up->execute();
                    $message = 'User updated.';
                    $messageType = 'ok';
                }
            }
        }
    } elseif ($action === 'delete') {
        $section = 'list';
        $tid = (int) ($_POST['user_id'] ?? 0);
        if ($tid === $sessionId) {
            $message = 'You cannot delete your own account.';
            $messageType = 'err';
        } elseif ($tid <= 0) {
            $message = 'Invalid user.';
            $messageType = 'err';
        } else {
            $cnt = $db->querySingle('SELECT COUNT(*) FROM admin_users');
            if ((int) $cnt <= 1) {
                $message = 'Cannot delete the last user.';
                $messageType = 'err';
            } else {
                $del = $db->prepare('DELETE FROM admin_users WHERE id = :id');
                $del->bindValue(':id', $tid, SQLITE3_INTEGER);
                $del->execute();
                if ($db->changes() === 0) {
                    $message = 'User not found.';
                    $messageType = 'err';
                } else {
                    $message = 'User deleted.';
                    $messageType = 'ok';
                }
            }
        }
    }
}

$r = $db->query('SELECT id, username, created_at, is_active FROM admin_users ORDER BY username COLLATE NOCASE');
$users = [];
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $users[] = $row;
}

$adminPageTitle = 'Admin users';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Users',
    'subtitle' => 'Admin accounts',
]);

inv_render_subtabbar([
    'list' => ['Directory', dsc_invoicing_href('admin/users.php?section=list')],
    'create' => ['Create', dsc_invoicing_href('admin/users.php?section=create')],
], $section, 'User sections');
?>

<?php if ($message !== ''): ?>
    <div class="message <?= $messageType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($section === 'create'): ?>
<div class="info-box">
    <h2 class="h5 mt-0">Create user</h2>
    <form method="POST" style="max-width:22rem;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <label for="username">Username</label>
        <input class="form-control" type="text" id="username" name="username" required autocomplete="off">
        <label for="password">Initial password</label>
        <input class="form-control" type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        <button type="submit" class="btn btn-primary mt-3">Create</button>
    </form>
</div>
<?php else: ?>
<div class="info-box">
    <h2 class="h5 mt-0">Directory</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Created</th>
                    <th>Active</th>
                    <th>Reset password</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php
                    $uid = (int) $u['id'];
                    $active = !empty($u['is_active']);
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars((string) $u['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if ($uid === $sessionId): ?>
                                <span class="text-secondary"> (you)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(dsc_invoicing_format_date((string) ($u['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $active ? 'yes' : 'no' ?></td>
                        <td>
                            <form method="POST" class="d-flex flex-wrap gap-2 align-items-center" autocomplete="off">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="set_password">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <input class="form-control form-control-sm" style="max-width:10rem;" type="password" name="new_password" placeholder="New password" required minlength="8" autocomplete="new-password">
                                <button type="submit" class="btn btn-outline btn-sm">Reset</button>
                            </form>
                        </td>
                        <td class="text-end">
                            <?php if ($uid !== $sessionId): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Toggle active status for this user?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <button type="submit" class="btn btn-outline btn-sm"><?= $active ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Permanently delete this user?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
