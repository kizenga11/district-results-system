<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return rtrim(BASE_PATH, '/') . '/' . $path;
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        exit;
    }
}

function flash_set(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }
    $msg = (string)$_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $msg;
}

function flash_success(string $message): void { flash_set('success', $message); }
function flash_error(string $message): void   { flash_set('error',   $message); }
function flash_warning(string $message): void { flash_set('warning', $message); }
function flash_info(string $message): void    { flash_set('info',    $message); }

// ── Global safe error/exception handler ───────────────────────
function _safe_error_message(Throwable $e): string
{
    $map = [
        'SQLSTATE[23000]' => 'This record conflicts with existing data (duplicate entry).',
        'SQLSTATE[42S02]' => 'A required database table is missing. Please run the installer.',
        'SQLSTATE[42000]' => 'A database query error occurred.',
        'SQLSTATE[08]'    => 'Could not connect to the database. Check your configuration.',
        'SQLSTATE'        => 'A database error occurred. Please try again.',
    ];
    $msg = $e->getMessage();
    foreach ($map as $needle => $friendly) {
        if (str_contains($msg, $needle)) return $friendly;
    }
    return 'An unexpected error occurred. Please try again or contact the administrator.';
}

function handle_exception(Throwable $e): void
{
    error_log('[' . date('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (session_status() === PHP_SESSION_ACTIVE) {
        flash_set('error', _safe_error_message($e));
    }
    if (!headers_sent()) {
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? (rtrim(BASE_PATH, '/') . '/dashboard.php')));
        exit;
    }
}

set_exception_handler('handle_exception');
