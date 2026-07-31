<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$db = getDbConnection();
$message = '';
$messageType = 'ok';
$sessionId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        $res = dsc_invoicing_admin_create_user($u, $p);
        if ($res['success']) {
            $message = 'User created.';
            $messageType = 'ok';
        } else {
            $message = $res['error'] ?? 'Create failed.';
            $messageType = 'err';
        }
    } elseif ($action === 'set_password') {
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
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1>Admin users</h1>
    <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrfField() ?>
        <button type="submit" class="btn">Logout</button>
    </form>
</div>

<?php if ($message !== ''): ?>
    <div class="message <?= $messageType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="info-box">
    <h2 style="margin-top:0;">Create user</h2>
    <form method="POST" style="max-width:22rem;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autocomplete="off" style="width:100%;box-sizing:border-box;">
        <label for="password" style="margin-top:0.75rem;">Initial password</label>
        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" style="width:100%;box-sizing:border-box;">
        <button type="submit" class="btn" style="margin-top:1rem;">Create</button>
    </form>
</div>

<div class="info-box">
    <h2 style="margin-top:0;">Users</h2>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid #30363d;">
                    <th style="padding:0.4rem;">User</th>
                    <th style="padding:0.4rem;">Created</th>
                    <th style="padding:0.4rem;">Active</th>
                    <th style="padding:0.4rem;">Reset password</th>
                    <th style="padding:0.4rem;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php
                    $uid = (int) $u['id'];
                    $active = !empty($u['is_active']);
                    ?>
                    <tr style="border-bottom:1px solid #21262d;vertical-align:top;">
                        <td style="padding:0.35rem 0;">
                            <strong><?= htmlspecialchars((string) $u['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if ($uid === $sessionId): ?>
                                <span style="color:#8b949e;"> (you)</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:0.35rem 0;"><?= htmlspecialchars(dsc_invoicing_format_date((string) ($u['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding:0.35rem 0;"><?= $active ? 'yes' : 'no' ?></td>
                        <td style="padding:0.35rem 0;">
                            <form method="POST" style="display:flex;gap:0.35rem;flex-wrap:wrap;align-items:center;" autocomplete="off">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="set_password">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <input type="password" name="new_password" placeholder="New password" required minlength="8" autocomplete="new-password" style="max-width:10rem;">
                                <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;">Reset</button>
                            </form>
                        </td>
                        <td style="padding:0.35rem 0;text-align:right;">
                            <?php if ($uid !== $sessionId): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Toggle active status for this user?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;"><?= $active ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form method="POST" style="display:inline;margin-left:0.35rem;" onsubmit="return confirm('Permanently delete this user?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
