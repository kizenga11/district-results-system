<?php

declare(strict_types=1);

// ── Load .env if available ─────────────────────────────────
$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$key, $val] = explode('=', $line, 2) + [1 => ''];
        $key = trim($key); $val = trim($val);
        if ($key !== '') {
            $_ENV[$key] = $val;
            putenv("$key=$val");
        }
    }
}

// ── Environment ────────────────────────────────────────────
define('APP_NAME',     'Iramba SSRMS');
define('APP_FULLNAME', 'Iramba Secondary Schools Results Management System');
define('APP_ENV',  $_ENV['APP_ENV'] ?? 'production');
define('APP_DEBUG', APP_ENV === 'development');

// ── Database ───────────────────────────────────────────────
// Set DB_PASS in .env or environment variables for security
// Default below is for local dev only — do NOT commit production passwords
define('DB_HOST',    $_ENV['DB_HOST']    ?? '127.0.0.1');
define('DB_PORT',    $_ENV['DB_PORT']    ?? '3306');
define('DB_NAME',    $_ENV['DB_NAME']    ?? 'iramba_rms');
define('DB_USER',    $_ENV['DB_USER']    ?? 'iramba_user');
define('DB_PASS',    $_ENV['DB_PASS']    ?? 'SILI100via@');
define('DB_CHARSET', 'utf8mb4');

// ── Routing ──────────────────────────────────────────────────
define('BASE_PATH', $_ENV['BASE_PATH'] ?? '/');

// ── Session ────────────────────────────────────────────────
define('SESSION_NAME',     'iramba_rms_session');
define('SESSION_LIFETIME', 3600);

// ── Muda ───────────────────────────────────────────────────
date_default_timezone_set('Africa/Dar_es_Salaam');

// ── Error handling ─────────────────────────────────────────
ini_set('display_errors', '1');
error_reporting(E_ALL);

