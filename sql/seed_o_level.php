<?php
/**
 * seed_o_level.php – Seed O-Level subjects as PHP
 *
 * Run from CLI:
 *   php sql/seed_o_level.php
 *
 * Or open in browser if your deployment allows PHP execution.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

$pdo = db();

$subjects = [
    ['Civics','011',0,0,0,'active'],
    ['History','012',0,0,0,'active'],
    ['Geography','013',0,0,0,'active'],
    ['Bible Knowledge','014',0,0,0,'active'],
    ['Elimu ya Dini ya Kiislamu (EDK)','015',0,0,0,'active'],
    ['Fine Art','016',0,0,0,'active'],
    ['Music','017',0,0,0,'active'],
    ['Physical Education','018',0,0,0,'active'],
    ['Theatre Arts','019',0,0,0,'active'],
    ['Kiswahili','021',0,0,0,'active'],
    ['English Language','022',0,0,0,'active'],
    ['French Language','023',0,0,0,'active'],
    ['Literature in English','024',0,0,0,'active'],
    ['Arabic Language','025',0,0,0,'active'],
    ['Chinese Language','026',0,0,0,'active'],
    ['Physics','031',0,0,0,'active'],
    ['Chemistry','032',0,0,0,'active'],
    ['Biology','033',0,0,0,'active'],
    ['Agriculture','034',0,0,0,'active'],
    ['Engineering Science','035',0,0,0,'active'],
    ['Information and Computer Studies (ICS)','036',0,0,0,'active'],
    ['Basic Mathematics','041',0,0,0,'active'],
    ['Additional Mathematics','042',0,0,0,'active'],
    ['Food and Human Nutrition','051',0,0,0,'active'],
    ['Textiles and Garment Construction','052',0,0,0,'active'],
    ['Commerce','061',0,0,0,'active'],
    ['Book-Keeping','062',0,0,0,'active'],
    ['Building Construction','071',0,0,0,'active'],
    ['Architectural Draughting','072',0,0,0,'active'],
    ['Civil Engineering Surveying','073',0,0,0,'active'],
    ['Woodwork and Painting Engineering','074',0,0,0,'active'],
    ['Electrical Engineering','080',0,0,0,'active'],
    ['Electronics and Communication Engineering','081',0,0,0,'active'],
    ['Electrical Draughting','082',0,0,0,'active'],
    ['Electronics Draughting','083',0,0,0,'active'],
    ['Automotive Engineering','087',0,0,0,'active'],
    ['Manufacturing Engineering','088',0,0,0,'active'],
    ['Engineering Drawing','091',0,0,0,'active'],
];

$stmt = $pdo->prepare(
    'INSERT INTO subjects (category, name, code, is_principal, has_practical, practical_max, status)
     VALUES ("o_level", :name, :code, :is_principal, :has_practical, :practical_max, :status)
     ON DUPLICATE KEY UPDATE
       name = VALUES(name),
       is_principal = VALUES(is_principal),
       has_practical = VALUES(has_practical),
       practical_max = VALUES(practical_max),
       status = VALUES(status)'
);

$inserted = 0;
foreach ($subjects as $subject) {
    [$name, $code, $is_principal, $has_practical, $practical_max, $status] = $subject;
    $stmt->execute([
        ':name' => $name,
        ':code' => $code,
        ':is_principal' => $is_principal,
        ':has_practical' => $has_practical,
        ':practical_max' => $practical_max,
        ':status' => $status,
    ]);
    $inserted++;
}

echo "Done. Seeded or updated $inserted O-Level subjects.\n";
