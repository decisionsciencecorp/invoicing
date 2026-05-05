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
}

function login(string $username, string $password): array {
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = :u');
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $r = $stmt->execute();
    $user = $r->fetchArray(SQLITE3_ASSOC);
    if (!$user || !password_verify($password, $user['password_hash'])) {
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
    $stmt = $db->prepare('SELECT id, username, created_at FROM admin_users WHERE id = :id');
    $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $r = $stmt->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}
