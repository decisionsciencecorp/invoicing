<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

initializeDatabase();

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] !== '';
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        header('Location: ' . dsc_invoicing_href('admin/login.php'));
        exit;
    }
    $me = getCurrentUser();
    if (!$me || empty($me['is_active'])) {
        logout();
        header('Location: ' . dsc_invoicing_href('admin/login.php'));
        exit;
    }
}

function login(string $username, string $password): array {
    $db = getDbConnection();
    $stmt = $db->prepare(
        'SELECT id, username, password_hash, is_active FROM admin_users WHERE username = :u'
    );
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $r = $stmt->execute();
    $user = $r->fetchArray(SQLITE3_ASSOC);
    if (!$user || empty($user['is_active']) || !password_verify($password, $user['password_hash'])) {
        app_log('warning', 'Auth failure for username: ' . substr($username, 0, 32));
        return ['success' => false, 'error' => 'Invalid username or password'];
    }
    if (php_sapi_name() !== 'cli') {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    return ['success' => true];
}

function logout(): bool {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    return true;
}

function getCurrentUser(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $db = getDbConnection();
    $stmt = $db->prepare(
        'SELECT id, username, created_at, is_active FROM admin_users WHERE id = :id'
    );
    $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $r = $stmt->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/**
 * @return array{success:bool, error?:string}
 */
function dsc_invoicing_change_password(int $userId, string $currentPassword, string $newPassword): array {
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT password_hash FROM admin_users WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $r = $stmt->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        return ['success' => false, 'error' => 'Current password is incorrect.'];
    }
    $minLength = 8;
    if (strlen($newPassword) < $minLength) {
        return ['success' => false, 'error' => 'New password must be at least ' . $minLength . ' characters.'];
    }
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
    $up = $db->prepare('UPDATE admin_users SET password_hash = :h WHERE id = :id');
    $up->bindValue(':h', $hash, SQLITE3_TEXT);
    $up->bindValue(':id', $userId, SQLITE3_INTEGER);
    $up->execute();

    return ['success' => true];
}

/**
 * Admin-only: set another user's password (min 8 chars).
 *
 * @return array{success:bool, error?:string}
 */
function dsc_invoicing_admin_set_user_password(int $targetUserId, string $newPassword): array {
    if (strlen($newPassword) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    $db = getDbConnection();
    $chk = $db->prepare('SELECT id FROM admin_users WHERE id = :id');
    $chk->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
    $row = $chk->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return ['success' => false, 'error' => 'User not found.'];
    }
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
    $up = $db->prepare('UPDATE admin_users SET password_hash = :h WHERE id = :id');
    $up->bindValue(':h', $hash, SQLITE3_TEXT);
    $up->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
    $up->execute();

    return ['success' => true];
}

/**
 * @return array{success:bool, error?:string, id?:int}
 */
function dsc_invoicing_admin_create_user(string $username, string $password): array {
    $username = trim($username);
    if ($username === '') {
        return ['success' => false, 'error' => 'Username required.'];
    }
    if (strlen($password) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    $db = getDbConnection();
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
    try {
        $stmt = $db->prepare(
            'INSERT INTO admin_users (username, password_hash, is_active) VALUES (:u, :h, 1)'
        );
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $stmt->bindValue(':h', $hash, SQLITE3_TEXT);
        $stmt->execute();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Could not create user (duplicate username?).'];
    }

    return ['success' => true, 'id' => (int) $db->lastInsertRowID()];
}
