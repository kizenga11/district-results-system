<?php
require_once __DIR__ . '/config/db.php';

$stmt = db()->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "<pre>";
print_r($tables);
echo "</pre>";
