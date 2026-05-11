<?php
/**
 * diagnose_school.php?school=Kizaga
 * Inaonyesha hali ya shule: assignments, masomo, mitihani inayoonekana na isiyoonekana
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$search = trim((string)($_GET['school'] ?? $_SERVER['argv'][1] ?? ''));
if (!$search) {
    echo "Tumia: ?school=NombelaYaShule au php diagnose_school.php 'Kizaga'\n";
    exit;
}

$pdo = db();

// Find school
$stmt = $pdo->prepare("SELECT * FROM schools WHERE name LIKE :q OR code LIKE :q LIMIT 5");
$stmt->execute([':q' => "%$search%"]);
$schools = $stmt->fetchAll();

if (!$schools) {
    echo "Shule '$search' haikupatikana.\n";
    exit;
}

foreach ($schools as $sc) {
    $sid = (int)$sc['id'];
    echo "=== SHULE: {$sc['name']} (ID=$sid, code={$sc['code']}, level={$sc['level']}) ===\n\n";

    // Headmaster
    $hm = $pdo->prepare("SELECT id, full_name, email, username FROM users WHERE school_id=? AND role='headmaster' LIMIT 3");
    $hm->execute([$sid]);
    echo "-- WAKUU WA SHULE:\n";
    foreach ($hm->fetchAll() as $u) {
        echo "   ID={$u['id']}  {$u['full_name']}  email={$u['email']}\n";
    }

    // Teachers
    $te = $pdo->prepare("SELECT COUNT(*) FROM users WHERE school_id=? AND role='teacher' AND status='active'");
    $te->execute([$sid]);
    echo "-- WALIMU (active): " . $te->fetchColumn() . "\n";

    // Activated subjects
    $ss = $pdo->prepare(
        "SELECT sub.category, COUNT(*) AS n FROM school_subjects ss
         JOIN subjects sub ON sub.id=ss.subject_id
         WHERE ss.school_id=? GROUP BY sub.category"
    );
    $ss->execute([$sid]);
    echo "-- MASOMO YALIYOWASHWA:\n";
    foreach ($ss->fetchAll() as $r) {
        echo "   {$r['category']}: {$r['n']} masomo\n";
    }
    if (!$ss->rowCount()) echo "   (hakuna masomo yaliyowashwa)\n";

    // Teacher assignments
    $ta = $pdo->prepare(
        "SELECT lv.name AS level, sub.category, COUNT(*) AS n
         FROM teacher_assignments ta
         JOIN subjects sub ON sub.id=ta.subject_id
         JOIN levels lv ON lv.id=ta.level_id
         WHERE ta.school_id=?
         GROUP BY ta.level_id, sub.category, lv.name
         ORDER BY ta.level_id"
    );
    $ta->execute([$sid]);
    $ta_rows = $ta->fetchAll();
    echo "-- TEACHER ASSIGNMENTS (darasa × somo):\n";
    if ($ta_rows) {
        foreach ($ta_rows as $r) {
            echo "   {$r['level']} ({$r['category']}): {$r['n']} assignments\n";
        }
    } else {
        echo "   (HAKUNA ASSIGNMENTS — hii ndiyo sababu mitihani haionekani!)\n";
    }

    // Exams visible (through assignments)
    $vis = $pdo->prepare(
        "SELECT DISTINCT e.id, e.name, e.year, e.status, e.category
         FROM teacher_assignments ta
         JOIN subjects sub ON sub.id=ta.subject_id
         JOIN exams e ON e.category=sub.category
         JOIN exam_levels el ON el.exam_id=e.id AND el.level_id=ta.level_id
         WHERE ta.school_id=?
         ORDER BY e.year DESC, e.name"
    );
    $vis->execute([$sid]);
    $vis_rows = $vis->fetchAll();
    echo "-- MITIHANI INAYOONEKANA (kupitia assignments):\n";
    if ($vis_rows) {
        foreach ($vis_rows as $r) {
            echo "   [{$r['status']}] {$r['name']} ({$r['year']}, {$r['category']})\n";
        }
    } else {
        echo "   (hakuna — kwa sababu hakuna assignments)\n";
    }

    // Exams available but NOT visible
    $all = $pdo->prepare(
        "SELECT DISTINCT e.id, e.name, e.year, e.status, e.category,
                GROUP_CONCAT(DISTINCT lv.name ORDER BY lv.id SEPARATOR ', ') AS levels
         FROM exams e
         JOIN exam_levels el ON el.exam_id=e.id
         JOIN levels lv ON lv.id=el.level_id
         WHERE e.category IN (
             SELECT DISTINCT sub.category FROM school_subjects ss
             JOIN subjects sub ON sub.id=ss.subject_id WHERE ss.school_id=?
         )
         GROUP BY e.id, e.name, e.year, e.status, e.category
         ORDER BY e.year DESC, e.name"
    );
    $all->execute([$sid]);
    $all_rows = $all->fetchAll();
    $vis_ids  = array_column($vis_rows, 'id');

    $missing = array_filter($all_rows, fn($r) => !in_array($r['id'], $vis_ids));
    echo "-- MITIHANI INAYOPATIKANA LAKINI HAIONEKANI (sababu: assignments hazifanani na exam_levels):\n";
    if ($missing) {
        foreach ($missing as $r) {
            echo "   [{$r['status']}] ID={$r['id']} {$r['name']} ({$r['year']}, {$r['category']}, madarasa: {$r['levels']})\n";
        }
        echo "\n   SULUHISHO: Pangia mwalimu masomo yanayofanana na madarasa yaliyoorodheshwa hapo juu.\n";
        echo "   Nenda: school/assignments.php\n";
    } else {
        echo "   (hakuna — mitihani yote inayopatikana inaonekana)\n";
    }

    echo "\n";
}
