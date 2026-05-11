<?php
require_once __DIR__ . '/config/db.php';

try {
    $stmt = db()->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "<h3>DATABASE TABLES</h3>";
    echo "<pre>";
    print_r($tables);
    echo "</pre>";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}