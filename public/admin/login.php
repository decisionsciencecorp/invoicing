<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (isLoggedIn()) {
    header('Location: ' . dsc_invoicing_href('admin/index.php'));
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
            header('Location: ' . dsc_invoicing_href('admin/index.php'));
            exit;
        }
        $error = $result['error'];
    }
}

$adminPageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>

<div class="nav-row">
    <h1>Login</h1>
</div>
<?php if ($error !== ''): ?>
    <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<div class="info-box" style="max-width: 24rem;">
    <form method="POST">
        <?= csrfField() ?>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autocomplete="username">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
        <button type="submit" class="btn" style="margin-top: 1rem;">Login</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
