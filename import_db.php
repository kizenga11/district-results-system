<?php
echo "Inaconnect database...\n";

$pdo = new PDO(
    'mysql:host=viaduct.proxy.rlwy.net;port=35121;dbname=railway;charset=utf8mb4',
    'root',
    'BQuvHkfEqQPPdMfTADAGZzRlafWGsSU',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Imefanikiwa kuconnect!\n";
echo "Inasoma SQL file...\n";

$sql = file_get_contents('C:/Users/kitukut/Downloads/iramba_rms.sql');

echo "Inaingiza data...\n";

// Split into individual statements
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => $s !== ''
);

$count = 0;
foreach ($statements as $statement) {
    try {
        $pdo->exec($statement);
        $count++;
    } catch (PDOException $e) {
        // Skip errors (duplicate tables etc)
        echo "Skip: " . substr($e->getMessage(), 0, 80) . "\n";
    }
}

echo "\nImekamilika! Statements zilizofanikiwa: $count\n";