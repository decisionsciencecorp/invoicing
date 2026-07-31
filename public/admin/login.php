<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$returnRaw = (string) ($_GET['return'] ?? $_POST['return'] ?? '');
$returnTo = dsc_invoicing_safe_admin_return($returnRaw);

if (isLoggedIn()) {
    header('Location: ' . $returnTo);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken((string) $token)) {
        http_response_code(403);
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $result = login($username, $password);
        if ($result['success']) {
            header('Location: ' . dsc_invoicing_safe_admin_return((string) ($_POST['return'] ?? '')));
            exit;
        }
        $error = $result['error'];
    }
}

$adminPageTitle = 'Login';
$invHideNav = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="inv-login-wrap">
    <div class="inv-login-card">
        <h1><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="subtitle mb-0">Sign in to continue</p>
        <?php if ($error !== ''): ?>
            <p class="error mt-3 mb-0"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="POST" class="mt-3">
            <?= csrfField() ?>
            <?php if ($returnRaw !== ''): ?>
                <input type="hidden" name="return" value="<?= htmlspecialchars($returnRaw, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <label for="username">Username</label>
            <input class="form-control" type="text" id="username" name="username" required autocomplete="username">
            <label for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
            <button type="submit" class="btn btn-primary w-100 mt-3">Login</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
