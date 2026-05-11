<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function current_user(): ?array
{
    start_app_session();
    return $_SESSION['user'] ?? null;
}

function require_auth(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
}

function require_role(array $roles): void
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function auth_login(string $email, string $password): bool
{
    start_app_session();

    $stmt = db()->prepare("SELECT id, school_id, full_name, email, username, password_hash, role, status FROM users WHERE email = :e AND status = 'active' LIMIT 1");
    $stmt->execute([':e' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    if (!password_verify($password, (string)$user['password_hash'])) {
        return false;
    }

    unset($user['password_hash']);
    $_SESSION['user'] = $user;

    session_regenerate_id(true);
    return true;
}

function auth_logout(): void
{
    start_app_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}
