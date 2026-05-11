<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}

redirect('login.php');

require_once __DIR__ . '/config/db.php';

try {
    $db = db();
    echo "DB CONNECTED SUCCESSFULLY";
} catch (Exception $e) {
    echo "DB ERROR: " . $e->getMessage();
}
exit;