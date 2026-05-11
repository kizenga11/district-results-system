<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = db();
$school_id = 1;

// Check existing students
$count = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE school_id=$school_id")->fetchColumn();
if ($count > 0) {
    echo "Students already exist ($count). Delete them first or run truncate.\n";
    exit;
}

$levels = [1, 2, 3, 4, 5, 6];
$level_names = [1 => 'Form 1', 2 => 'Form 2', 3 => 'Form 3', 4 => 'Form 4', 5 => 'Form 5', 6 => 'Form 6'];

$m_first = ['Juma', 'Hamisi', 'Salum', 'Abdallah', 'Rajabu', 'Said', 'Ramadhani', 'Kassim', 'Omari', 'Mbaraka', 'Yusuph', 'Idd', 'Saidi', 'Hamza', 'Shabani', 'Musa', 'Hussein', 'Bakari', 'Jafari', 'Masoud'];
$f_first = ['Mwajuma', 'Amina', 'Zainabu', 'Hawa', 'Asha', 'Saada', 'Rukia', 'Mariamu', 'Khadija', 'Maimuna', 'Fatuma', 'Halima', 'Rehema', 'Tatu', 'Mwanaisha', 'Salama', 'Aisha', 'Zena', 'Mwanaidi', 'Jannat'];
$last    = ['Mussa', 'Said', 'Abdallah', 'Hassan', 'Omary', 'Salim', 'Juma', 'Mfaume', 'Mbwana', 'Mkude', 'Simba', 'Mushi', 'Macha', 'Muro', 'Mkinga', 'Chami', 'Mngale', 'Kisasi', 'Mnyasa', 'Ntandu'];

// Form 5/6 combo assignments: [student_index_within_level => combo_id]
$form5_combos = [0 => 1, 1 => 2, 2 => 8, 3 => 12, 4 => 1, 5 => 2, 6 => 8, 7 => 12, 8 => 1, 9 => 2];
$form6_combos = [0 => 3, 1 => 4, 2 => 9, 3 => 13, 4 => 3, 5 => 4, 6 => 9, 7 => 13, 8 => 3, 9 => 4];

$pdo->beginTransaction();
try {
    $id_counter = 0;
    $student_ids_by_level = [];

    $stmt = $pdo->prepare(
        'INSERT INTO students (school_id, level_id, admission_no, full_name, sex, status, created_at) VALUES (:sid, :lid, :adm, :name, :sex, "active", NOW())'
    );

    foreach ($levels as $lid) {
        $student_ids_by_level[$lid] = [];
        for ($i = 0; $i < 10; $i++) {
            $id_counter++;
            $sex = $i < 5 ? 'M' : 'F';
            $pool = $sex === 'M' ? $m_first : $f_first;
            $fn = $pool[array_rand($pool)];
            $ln = $last[array_rand($last)];
            $adm = sprintf('S1130/%s/%03d', str_replace(' ', '', $level_names[$lid]), $id_counter);

            $stmt->execute([
                ':sid' => $school_id,
                ':lid' => $lid,
                ':adm' => $adm,
                ':name' => "$fn $ln",
                ':sex' => $sex,
            ]);
            $student_ids_by_level[$lid][] = (int)$pdo->lastInsertId();
        }
    }

    // Assign A-Level combinations
    $cstmt = $pdo->prepare('INSERT IGNORE INTO student_combinations (student_id, combination_id) VALUES (:sid, :cid)');
    foreach ([5, 6] as $lid) {
        $combos = $lid === 5 ? $form5_combos : $form6_combos;
        foreach ($student_ids_by_level[$lid] as $i => $sid) {
            $cstmt->execute([':sid' => $sid, ':cid' => $combos[$i]]);
        }
    }

    $pdo->commit();

    $total = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE school_id=$school_id")->fetchColumn();
    $a_total = (int)$pdo->query("SELECT COUNT(*) FROM student_combinations sc JOIN students s ON s.id=sc.student_id WHERE s.school_id=$school_id")->fetchColumn();

    echo "Done. $total students seeded ($a_level with combinations).\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
