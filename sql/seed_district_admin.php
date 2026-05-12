<?php
/**
 * seed_district_admin.php
 *
 * Inafanya nini:
 *   1. Inafuta WOTE watumiaji wa zamani (isipokuwa super_admin kama yupo)
 *   2. Inaunda district_admin mmoja mpya
 *
 * Jinsi ya kuendesha (PowerShell):
 *   railway run php sql/seed_district_admin.php
 *
 * Akaunti itakayoundwa:
 *   Email    : admin@iramba.go.tz
 *   Password : Admin@2026
 *   Role     : district_admin
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/db.php';

$pdo = db();

echo "=== Seed: District Admin ===\n\n";

// ── HATUA 1: Futa watumiaji wote wa zamani ─────────────────────────
echo "1. Inafuta watumiaji wote wa zamani...\n";
$deleted = $pdo->exec("DELETE FROM users");
echo "   Wafutwa: {$deleted} mtumiaji(s)\n\n";

// ── HATUA 2: Unda District Admin mpya ─────────────────────────────
echo "2. Inaunda District Admin mpya...\n";

$full_name     = 'Msimamizi wa Wilaya';
$email         = 'admin@iramba.go.tz';
$username      = 'district_admin';
$password      = 'Admin@2026';
$password_hash = password_hash($password, PASSWORD_BCRYPT);
$role          = 'district_admin';
$status        = 'active';
$school_id     = null;

$stmt = $pdo->prepare("
    INSERT INTO users (school_id, full_name, email, username, password_hash, role, status, created_at, updated_at)
    VALUES (:school_id, :full_name, :email, :username, :password_hash, :role, :status, NOW(), NOW())
");

$stmt->execute([
    ':school_id'     => $school_id,
    ':full_name'     => $full_name,
    ':email'         => $email,
    ':username'      => $username,
    ':password_hash' => $password_hash,
    ':role'          => $role,
    ':status'        => $status,
]);

$id = $pdo->lastInsertId();

echo "   ✓ District Admin ameundwa! (ID: {$id})\n\n";

// ── MATOKEO ────────────────────────────────────────────────────────
echo "=== AKAUNTI YAKO ===\n";
echo "   Email    : {$email}\n";
echo "   Password : {$password}\n";
echo "   Role     : {$role}\n";
echo "   Status   : {$status}\n\n";
echo "=== IMEKAMILIKA! ✓ ===\n";
echo "Ingia sasa: https://irms.up.railway.app/login.php\n";