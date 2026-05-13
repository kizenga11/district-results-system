<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$pdo      = db();
$email    = 'district@iramba.go.tz';
$username = 'district_admin';
$password = 'District@2026';
$hash     = password_hash($password, PASSWORD_BCRYPT);

// Futa district admin wa zamani kama yupo
$pdo->prepare("DELETE FROM users WHERE role = 'district_admin'")->execute();

$pdo->prepare("
    INSERT INTO users (school_id, full_name, email, username, password_hash, role, status)
    VALUES (NULL, 'District Admin Iramba', :email, :username, :hash, 'district_admin', 'active')
")->execute([':email' => $email, ':username' => $username, ':hash' => $hash]);

echo "=== District Admin Created ===\n";
echo "Email    : $email\n";
echo "Password : $password\n";
echo "Login    : http://localhost/district-results-system/login.php\n";
