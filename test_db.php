<?php
require_once __DIR__ . '/config/db.php';

try {
    $pdo = db();

    // check kama admin tayari yupo
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'district_admin'");
    $stmt->execute();
    $exists = $stmt->fetchColumn();

    if ($exists > 0) {
        die("Admin tayari yupo.");
    }

    // create admin
    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, role)
        VALUES (:name, :email, :password, :role)
    ");

    $stmt->execute([
        ':name' => 'District Admin',
        ':email' => 'iramba@district.com',
        ':password' => password_hash('admin123', PASSWORD_BCRYPT),
        ':role' => 'district_admin'
    ]);

    echo "District Admin ameundwa successfully!";
    echo "<br>Email: admin@district.com";
    echo "<br>Password: admin123";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}