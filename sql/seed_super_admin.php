<?php
/**
 * seed_super_admin.php – Weka au rekebisha Super Admin
 *
 * LOGIN itakayofanya kazi:
 *   Email    : admin@iramba.go.tz
 *   Password : Admin@123
 *
 * Jinsi ya kuendesha:
 *   php sql/seed_super_admin.php
 *   AU fungua: http://localhost/district-results-system/sql/seed_super_admin.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/db.php';

$pdo = db();

$email    = 'admin@iramba.go.tz';
$username = 'super_admin';
$password = 'Admin@123';
$hash     = password_hash($password, PASSWORD_BCRYPT);

echo "=== Seed: Super Admin ===\n\n";

// Futa super admin wa zamani (wa username yoyote) kabla ya kuunda mpya
$deleted = $pdo->exec("DELETE FROM users WHERE role = 'super_admin'");
if ($deleted > 0) {
    echo "Imefutwa: akaunti $deleted ya zamani ya super_admin\n";
}

$stmt = $pdo->prepare("
    INSERT INTO users (school_id, full_name, email, username, password_hash, role, status)
    VALUES (NULL, 'Super Admin', :email, :username, :hash, 'super_admin', 'active')
");
$stmt->execute([
    ':email'    => $email,
    ':username' => $username,
    ':hash'     => $hash,
]);

$id = (int)$pdo->lastInsertId();

echo "Imeundwa: Super Admin (ID: $id)\n\n";
echo "=== INGIA KAMA HIVI ===\n";
echo "  Email    : $email\n";
echo "  Password : $password\n";
echo "  URL      : http://localhost/district-results-system/login.php\n\n";
echo "=== IMEKAMILIKA ===\n";
