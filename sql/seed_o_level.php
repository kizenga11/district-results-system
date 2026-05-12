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
    ['Civics','011','CIV',0,0,0,'active'],
    ['History','012','HIST',0,0,0,'active'],
    ['Geography','013','GEO',0,0,0,'active'],
    ['Bible Knowledge','014','B/K',0,0,0,'active'],
    ['Elimu ya Dini ya Kiislamu (EDK)','015','EDK',0,0,0,'active'],
    ['Fine Art','016','F/A',0,0,0,'active'],
    ['Music','017','MUS',0,0,0,'active'],
    ['Physical Education','018','PE',0,0,0,'active'],
    ['Theatre Arts','019','T/A',0,0,0,'active'],
    ['Kiswahili','021','KISW',0,0,0,'active'],
    ['English Language','022','ENG',0,0,0,'active'],
    ['French Language','023','FRE',0,0,0,'active'],
    ['Literature in English','024','LIT',0,0,0,'active'],
    ['Arabic Language','025','ARAB',0,0,0,'active'],
    ['Chinese Language','026','CHN',0,0,0,'active'],
    ['Physics','031','PHY',0,0,0,'active'],
    ['Chemistry','032','CHEM',0,0,0,'active'],
    ['Biology','033','BIO',0,0,0,'active'],
    ['Agriculture','034','AGRI',0,0,0,'active'],
    ['Engineering Science','035','E/SC',0,0,0,'active'],
    ['Information and Computer Studies (ICS)','036','ICS',0,0,0,'active'],
    ['Basic Mathematics','041','B/MATH',0,0,0,'active'],
    ['Additional Mathematics','042','ADD/M',0,0,0,'active'],
    ['Food and Human Nutrition','051','FHN',0,0,0,'active'],
    ['Textiles and Garment Construction','052','TGC',0,0,0,'active'],
    ['Commerce','061','COMM',0,0,0,'active'],
    ['Book-Keeping','062','B/KP',0,0,0,'active'],
    ['Building Construction','071','B/CON',0,0,0,'active'],
    ['Architectural Draughting','072','ARCH',0,0,0,'active'],
    ['Civil Engineering Surveying','073','CES',0,0,0,'active'],
    ['Woodwork and Painting Engineering','074','WPE',0,0,0,'active'],
    ['Electrical Engineering','080','E/ENG',0,0,0,'active'],
    ['Electronics and Communication Engineering','081','ECE',0,0,0,'active'],
    ['Electrical Draughting','082','E/DRG',0,0,0,'active'],
    ['Electronics Draughting','083','EL/DR',0,0,0,'active'],
    ['Automotive Engineering','087','AUTO',0,0,0,'active'],
    ['Manufacturing Engineering','088','MFG',0,0,0,'active'],
    ['Engineering Drawing','091','E/DRW',0,0,0,'active'],
];

$stmt = $pdo->prepare(
    'INSERT INTO subjects (category, name, code, abbr, is_principal, has_practical, practical_max, status)
     VALUES ("o_level", :name, :code, :abbr, :is_principal, :has_practical, :practical_max, :status)
     ON DUPLICATE KEY UPDATE
       name = VALUES(name),
       abbr = VALUES(abbr),
       is_principal = VALUES(is_principal),
       has_practical = VALUES(has_practical),
       practical_max = VALUES(practical_max),
       status = VALUES(status)'
);

$inserted = 0;
foreach ($subjects as $subject) {
    [$name, $code, $abbr, $is_principal, $has_practical, $practical_max, $status] = $subject;
    $stmt->execute([
        ':name' => $name,
        ':code' => $code,
        ':abbr' => $abbr,
        ':is_principal' => $is_principal,
        ':has_practical' => $has_practical,
        ':practical_max' => $practical_max,
        ':status' => $status,
    ]);
    $inserted++;
}

echo "Done. Seeded or updated $inserted O-Level subjects.\n";
