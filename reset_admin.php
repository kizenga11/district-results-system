<?php

require_once __DIR__ . '/config/db.php';

try {

    $newPassword = password_hash('admin123', PASSWORD_BCRYPT);

    $stmt = db()->prepare("
        UPDATE users
        SET password_hash = ?
        WHERE email = ?
    ");

    $stmt->execute([
        $newPassword,
        'eduprox.official@gmail.com'
    ]);

    echo "PASSWORD RESET SUCCESS";
    echo "<br><br>";
    echo "Email: eduprox.official@gmail.com";
    echo "<br>";
    echo "Password: admin123";

} catch (Exception $e) {
    echo $e->getMessage();
}