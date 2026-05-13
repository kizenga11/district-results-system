<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

echo '<pre>';
try {
    $tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "TABLES (" . count($tables) . "):\n" . implode("\n", $tables);
    
    echo "\n\nUSERS:\n";
    $users = db()->query("SELECT id, email, role, status, LEFT(password_hash,10) as hash_start FROM users")->fetchAll();
    print_r($users);
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
echo '</pre>';