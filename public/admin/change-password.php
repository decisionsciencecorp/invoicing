<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$error = '';
$success = '';
$me = getCurrentUser();
$username = (string) ($me['username'] ?? ($_SESSION['username'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $result = dsc_invoicing_change_password($uid, $current, $new);
        if ($result['success']) {
            $success = 'Password updated.';
        } else {
            $error = $result['error'] ?? 'Could not update password.';
            if ($error === 'Current password is incorrect.') {
                $error .= ' If a password manager filled the wrong value, clear the current-password field and type it by hand — or use Users → Reset password (does not need the current one).';
            }
        }
    }
}

$adminPageTitle = 'Change password';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="nav-row">
    <h1>Change password</h1>
    <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrfField() ?>
        <button type="submit" class="btn">Logout</button>
    </form>
</div>

<?php if ($error !== ''): ?>
    <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="message ok"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<p style="font-size:0.875rem;color:#8b949e;max-width:36rem;">
    Prefer not to type the current password?
    <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/users.php'), ENT_QUOTES, 'UTF-8') ?>">Users → Reset password</a>
    sets a new one while you are logged in.
</p>

<div class="info-box" style="max-width:24rem;">
    <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <?php /* Helps password managers bind the account; keeps current vs new fields from swapping. */ ?>
        <label for="username_display">Username</label>
        <input type="text" id="username_display" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" readonly style="width:100%;box-sizing:border-box;opacity:0.85;">
        <label for="current_password" style="margin-top:0.75rem;">Current password</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password" style="width:100%;box-sizing:border-box;">
        <label for="new_password" style="margin-top:0.75rem;">New password</label>
        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password" style="width:100%;box-sizing:border-box;">
        <p style="font-size:0.875rem;color:#8b949e;margin:0.25rem 0 0;">At least 8 characters.</p>
        <label for="confirm_password" style="margin-top:0.75rem;">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" style="width:100%;box-sizing:border-box;">
        <button type="submit" class="btn" style="margin-top:1rem;">Update password</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
