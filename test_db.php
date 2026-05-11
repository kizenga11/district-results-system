<?php

try {
    $pdo = new PDO(
        "mysql:host=mysql.railway.internal;dbname=railway;charset=utf8mb4",
        "root",
        "BQuvHkfEqQPPdMfTADAZGZzRlafWGsSU"
    );

    echo "DB CONNECTED OK";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
