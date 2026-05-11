<?php
/**
 * seed_full.php – Seed kamili ya majaribio
 *
 * Inafanya nini:
 *   1. Shule 15 (Iramba-style)
 *   2. Mkuu wa shule 1 na Walimu 3 kwa kila shule
 *   3. Masomo 9 ya msingi (O-Level) kwa kila shule
 *   4. Wanafunzi 8 kwa kila darasa (Form 1–4) kwa shule = 32/shule
 *   5. Mtihani mmoja "open" – Term I 2026
 *   6. Alama za masomo yote, madarasa yote, shule zote
 *
 * Jinsi ya kuendesha:
 *   php sql/seed_full.php
 *   AU fungua: http://localhost/iramba-rms/sql/seed_full.php
 *
 * Akaunti za majaribio:
 *   Super Admin  : username=super          pass=Admin@123
 *   District     : username=district       pass=Test@1234
 *   Mkuu (s1)    : username=mkuu.iramba    pass=Test@1234
 *   Mwalimu (s1) : username=mwl.kilima.s1  pass=Test@1234
 */

declare(strict_types=1);

// Allow running from browser or CLI
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/db.php';

$pdo = db();

// ── Guard: skip if already seeded ─────────────────────────────────────
$existing = (int)$pdo->query("SELECT COUNT(*) FROM schools WHERE code REGEXP '^SCH0[0-9]'")->fetchColumn();
if ($existing > 0) {
    echo "Tayari kuna shule za seed ($existing). Futa kwanza:\n";
    echo "  DELETE FROM schools WHERE code REGEXP '^SCH0[0-9]';\n";
    exit(1);
}

// ── Helpers ────────────────────────────────────────────────────────────
$PASSWORD = password_hash('Test@1234', PASSWORD_BCRYPT);

$M_FIRST  = ['Juma','Hamisi','Salum','Abdallah','Rajabu','Said','Ramadhani','Kassim','Omari','Mbaraka','Yusuph','Idd','Saidi','Hamza','Shabani','Musa','Hussein','Bakari','Jafari','Masoud'];
$F_FIRST  = ['Mwajuma','Amina','Zainabu','Hawa','Asha','Saada','Rukia','Mariamu','Khadija','Maimuna','Fatuma','Halima','Rehema','Tatu','Mwanaisha','Salama','Aisha','Zena','Mwanaidi','Jannat'];
$LAST     = ['Mussa','Said','Abdallah','Hassan','Omary','Salim','Juma','Mfaume','Mbwana','Mkude','Simba','Mushi','Macha','Muro','Mkinga','Chami','Mngale','Kisasi','Mnyasa','Ntandu'];

srand(42); // reproducible names

function rname(array $firsts, array $lasts): string {
    return $firsts[array_rand($firsts)] . ' ' . $lasts[array_rand($lasts)];
}

// ── Data ───────────────────────────────────────────────────────────────
$SCHOOLS = [
    ['SCH001', 'Iramba Secondary School',      'Kiomboi'],
    ['SCH002', 'Kiomboi Secondary School',     'Kiomboi'],
    ['SCH003', 'Mkalama Secondary School',     'Mkalama'],
    ['SCH004', 'Ndago Secondary School',       'Ndago'],
    ['SCH005', 'Kinampanda Secondary School',  'Kinampanda'],
    ['SCH006', 'Urughu Secondary School',      'Urughu'],
    ['SCH007', 'Mkiwa Secondary School',       'Mkiwa'],
    ['SCH008', 'Itigi Secondary School',       'Itigi'],
    ['SCH009', 'Mwanga Secondary School',      'Mwanga'],
    ['SCH010', 'Sepuka Secondary School',      'Sepuka'],
    ['SCH011', 'Ulemo Secondary School',       'Ulemo'],
    ['SCH012', 'Kinyangiri Secondary School',  'Kinyangiri'],
    ['SCH013', 'Kidarifa Secondary School',    'Kidarifa'],
    ['SCH014', 'Mwankoko Secondary School',    'Mwankoko'],
    ['SCH015', 'Mwanhumba Secondary School',   'Mwanhumba'],
];

$HEADMASTERS = [
    ['Alphonce Mwanga',    'mkuu.iramba'],
    ['Juma Ramadhani',     'mkuu.kiomboi'],
    ['Asha Mkalama',       'mkuu.mkalama'],
    ['Peter Ndago',        'mkuu.ndago'],
    ['Grace Kinampanda',   'mkuu.kinampanda'],
    ['Mohamed Urughu',     'mkuu.urughu'],
    ['Rose Mkiwa',         'mkuu.mkiwa'],
    ['Emmanuel Itigi',     'mkuu.itigi'],
    ['Fatuma Mwanga',      'mkuu.mwanga'],
    ['Jonas Sepuka',       'mkuu.sepuka'],
    ['Siwema Ulemo',       'mkuu.ulemo'],
    ['Hamisi Kinyangiri',  'mkuu.kinyangiri'],
    ['Lilian Kidarifa',    'mkuu.kidarifa'],
    ['Rashid Mwankoko',    'mkuu.mwankoko'],
    ['Beata Mwanhumba',    'mkuu.mwanhumba'],
];

// 3 teachers per school × 15 = 45 unique entries
$TEACHER_NAMES = [
    ['Juma Kilima',       'mwl.kilima'],
    ['Amina Hassan',      'mwl.ahassan'],
    ['Petro Mushi',       'mwl.mushi'],
    ['Saidi Mbwana',      'mwl.mbwana'],
    ['Happiness Macha',   'mwl.hmacha'],
    ['Omar Said',         'mwl.osaid'],
    ['Salama Chami',      'mwl.schami'],
    ['Fredrick Muro',     'mwl.muro'],
    ['Khadija Simba',     'mwl.ksimba'],
    ['Yusuph Mkinga',     'mwl.mkinga'],
    ['Consolata Ntandu',  'mwl.ntandu'],
    ['Hassan Mnyasa',     'mwl.mnyasa'],
    ['Neema Kisasi',      'mwl.kisasi'],
    ['Bakari Mngale',     'mwl.mngale'],
    ['Joyce Mkude',       'mwl.jmkude'],
    ['Rajabu Mussa',      'mwl.rmussa'],
    ['Veronica Omary',    'mwl.vomary'],
    ['Kassim Juma',       'mwl.kjuma'],
    ['Theresa Salim',     'mwl.tsalim'],
    ['Ibrahim Mfaume',    'mwl.imfaume'],
    ['Agnes Abdallah',    'mwl.aabdallah'],
    ['Shabani Hamisi',    'mwl.shamisi'],
    ['Gladys Said',       'mwl.gsaid'],
    ['Masoud Omari',      'mwl.momari'],
    ['Prisca Mbaraka',    'mwl.pmbaraka'],
    ['Issa Ramadhani',    'mwl.iramadhani'],
    ['Loyce Yusuph',      'mwl.lyusuph'],
    ['Abdallah Idd',      'mwl.aidd'],
    ['Miriam Saidi',      'mwl.msaidi'],
    ['Godlove Hamza',     'mwl.ghamza'],
    ['Winfrida Shabani',  'mwl.wshabani'],
    ['Rehema Musa',       'mwl.rmusa'],
    ['Erick Hussein',     'mwl.ehussein'],
    ['Zuena Bakari',      'mwl.zbakari'],
    ['Bernard Jafari',    'mwl.bjafari'],
    ['Halima Masoud',     'mwl.hmasoud'],
    ['Gabriel Mwajuma',   'mwl.gmwajuma'],
    ['Zainabu Rajabu',    'mwl.zrajabu'],
    ['Charles Omari',     'mwl.comari'],
    ['Fatuma Omari2',     'mwl.fomari'],
    ['Dominic Hassan',    'mwl.dhassan'],
    ['Salum Abdalla',     'mwl.sabdalla'],
    ['Mary Juma',         'mwl.mjuma'],
    ['Kassim Haji',       'mwl.khaji'],
    ['Susan Kilimo',      'mwl.skilimo'],
];

// Core O-Level subject codes (must already exist from seed_o_level.sql)
$CORE_CODES = ['011','012','013','021','022','031','032','033','041'];
// Groups: teacher 1 → Humanities, teacher 2 → Languages+Science, teacher 3 → Science+Math
$CODE_GROUPS = [
    ['011','012','013'],   // Civics, History, Geography
    ['021','022','033'],   // Kiswahili, English, Biology
    ['031','032','041'],   // Physics, Chemistry, Basic Math
];

// O-Level grading
function o_grade(float $pct): array {
    if ($pct >= 75) return ['A', 1];
    if ($pct >= 65) return ['B', 2];
    if ($pct >= 50) return ['C', 3];
    if ($pct >= 30) return ['D', 4];
    return ['F', 5];
}

// Weighted random mark: realistic distribution
function rand_mark(): float {
    $r = rand(1, 100);
    if ($r <= 12) return (float)rand(75, 98);   // A  12%
    if ($r <= 32) return (float)rand(65, 74);   // B  20%
    if ($r <= 60) return (float)rand(50, 64);   // C  28%
    if ($r <= 82) return (float)rand(30, 49);   // D  22%
    return (float)rand(5, 29);                  // F  18%
}

// ── Begin transaction ──────────────────────────────────────────────────
try {
    // ── 1. Get core subject IDs ────────────────────────────────────────
    $ph  = implode(',', array_fill(0, count($CORE_CODES), '?'));
    $stm = $pdo->prepare("SELECT id, code FROM subjects WHERE category='o_level' AND code IN ($ph)");
    $stm->execute($CORE_CODES);
    $sub_map = [];
    foreach ($stm->fetchAll() as $row) {
        $sub_map[$row['code']] = (int)$row['id'];
    }
    if (count($sub_map) < count($CORE_CODES)) {
        $missing = array_diff($CORE_CODES, array_keys($sub_map));
        echo "KOSA: Masomo hayapo DB (codes: " . implode(',', $missing) . ").\n";
        echo "Kwanza tekeleza: sql/seed_o_level.sql\n";
        exit(1);
    }
    $all_sub_ids = array_values($sub_map);
    // Groups of subject IDs
    $sub_groups = [];
    foreach ($CODE_GROUPS as $codes) {
        $sub_groups[] = array_map(fn($c) => $sub_map[$c], $codes);
    }

    // ── 2. District admin ──────────────────────────────────────────────
    $da_id = (int)$pdo->query("SELECT id FROM users WHERE username='district' LIMIT 1")->fetchColumn();
    if (!$da_id) {
        $pdo->prepare("INSERT INTO users (school_id,full_name,email,username,password_hash,role,status) VALUES (NULL,'District Admin Iramba','district@iramba.go.tz','district',?,'district_admin','active') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")
            ->execute([$PASSWORD]);
        $da_id = (int)$pdo->lastInsertId();
        if (!$da_id) {
            $da_id = (int)$pdo->query("SELECT id FROM users WHERE username='district' LIMIT 1")->fetchColumn();
        }
        echo "Imetengenezwa: district admin (username=district, pass=Test@1234)\n";
    }

    // ── 3. Exam ────────────────────────────────────────────────────────
    $exam_check = (int)$pdo->query("SELECT id FROM exams WHERE name='Mtihani wa Muhula wa Kwanza' AND year=2026 LIMIT 1")->fetchColumn();
    if (!$exam_check) {
        $created_by = $da_id ?: null;
        $pdo->prepare(
            "INSERT INTO exams (category,name,year,term,status,marks_open_from,marks_open_to,created_by)
             VALUES ('o_level','Mtihani wa Muhula wa Kwanza',2026,'I','open','2026-01-01','2026-12-31',?)"
        )->execute([$created_by]);
        $exam_id = (int)$pdo->lastInsertId();
    } else {
        $exam_id = $exam_check;
    }

    // Exam covers Form 1–4
    $el = $pdo->prepare("INSERT IGNORE INTO exam_levels (exam_id,level_id) VALUES (?,?)");
    foreach ([1,2,3,4] as $lid) {
        $el->execute([$exam_id, $lid]);
    }
    echo "Mtihani ID: $exam_id\n\n";

    // ── Prepared statements ────────────────────────────────────────────
    $ins_school  = $pdo->prepare("INSERT IGNORE INTO schools (name,code,level,ward,status) VALUES (?,?,'o_level',?,'active')");
    $ins_user    = $pdo->prepare("INSERT IGNORE INTO users (school_id,full_name,email,username,password_hash,role,status) VALUES (?,?,?,?,?,'?','active')");
    // role is a string, use positional properly
    $ins_user = $pdo->prepare(
        "INSERT IGNORE INTO users (school_id,full_name,email,username,password_hash,role,status) VALUES (?,?,?,?,?,?,'active')"
    );
    $ins_ss      = $pdo->prepare("INSERT IGNORE INTO school_subjects (school_id,subject_id) VALUES (?,?)");
    $ins_ta      = $pdo->prepare("INSERT IGNORE INTO teacher_assignments (teacher_id,school_id,subject_id,level_id) VALUES (?,?,?,?)");
    $ins_student = $pdo->prepare("INSERT IGNORE INTO students (school_id,level_id,admission_no,full_name,sex,status) VALUES (?,?,?,?,?,'active')");
    $ins_ss2     = $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id,subject_id,school_id,level_id) VALUES (?,?,?,?)");
    $ins_mark    = $pdo->prepare(
        "INSERT IGNORE INTO marks (exam_id,student_id,subject_id,theory_mark,practical_mark,total_percent,grade,points,created_by) VALUES (?,?,?,?,NULL,?,?,?,?)"
    );

    $t_idx = 0; // teacher pool index

    foreach ($SCHOOLS as $s_idx => [$code, $name, $ward]) {
        $pdo->beginTransaction();

        // School
        $ins_school->execute([$name, $code, $ward]);
        $school_id = (int)($pdo->lastInsertId() ?: $pdo->query("SELECT id FROM schools WHERE code='$code'")->fetchColumn());

        // Activate all core subjects for this school
        foreach ($all_sub_ids as $sid) {
            $ins_ss->execute([$school_id, $sid]);
        }

        // Headmaster
        [$hm_name, $hm_user] = $HEADMASTERS[$s_idx];
        $hm_email = $hm_user . '@iramba.go.tz';
        $ins_user->execute([$school_id, $hm_name, $hm_email, $hm_user, $PASSWORD, 'headmaster']);
        $hm_id = (int)($pdo->lastInsertId() ?: $pdo->query("SELECT id FROM users WHERE username='$hm_user'")->fetchColumn());

        // 3 Teachers
        $teacher_ids = [];
        for ($t = 0; $t < 3; $t++) {
            [$t_name, $t_base] = $TEACHER_NAMES[$t_idx % count($TEACHER_NAMES)];
            $t_user  = $t_base . '.s' . ($s_idx + 1);
            $t_email = $t_user . '@iramba.go.tz';
            $t_idx++;

            $ins_user->execute([$school_id, $t_name, $t_email, $t_user, $PASSWORD, 'teacher']);
            $t_id = (int)($pdo->lastInsertId() ?: $pdo->query("SELECT id FROM users WHERE username='$t_user'")->fetchColumn());
            $teacher_ids[] = $t_id;

            // Assign teacher to 3 subjects × Form 1–4
            foreach ($sub_groups[$t] as $sub_id) {
                foreach ([1,2,3,4] as $lid) {
                    $ins_ta->execute([$t_id, $school_id, $sub_id, $lid]);
                }
            }
        }

        // Students: 8 per form (4M + 4F), Form 1–4
        $students_by_level = [];
        $s_counter = ($s_idx * 200) + 1;

        foreach ([1,2,3,4] as $lid) {
            $students_by_level[$lid] = [];
            for ($i = 0; $i < 8; $i++) {
                $sex  = $i < 4 ? 'M' : 'F';
                $pool = $sex === 'M' ? $M_FIRST : $F_FIRST;
                $full = rname($pool, $LAST);
                $adm  = sprintf('%s/F%d/%04d', $code, $lid, $s_counter++);

                $ins_student->execute([$school_id, $lid, $adm, $full, $sex]);
                $stud_id = (int)$pdo->lastInsertId();
                if (!$stud_id) continue; // already exists, skip

                $students_by_level[$lid][] = $stud_id;

                // Assign all 9 core subjects to this student
                foreach ($all_sub_ids as $sub_id) {
                    $ins_ss2->execute([$stud_id, $sub_id, $school_id, $lid]);
                }
            }
        }

        // Marks for Form 1–4 students
        $creator = $teacher_ids[0] ?? $hm_id;
        foreach ([1,2,3,4] as $lid) {
            foreach ($students_by_level[$lid] as $stud_id) {
                foreach ($all_sub_ids as $sub_id) {
                    $mark = rand_mark();
                    [$grade, $pts] = o_grade($mark);
                    $ins_mark->execute([$exam_id, $stud_id, $sub_id, $mark, $mark, $grade, $pts, $creator]);
                }
            }
        }

        $pdo->commit();

        $total_stud = array_sum(array_map('count', $students_by_level));
        echo sprintf("%-6s %-40s walimu=3  wanafunzi=%-3d\n", $code, $name, $total_stud);
    }

    // ── Summary ────────────────────────────────────────────────────────
    $n_schools  = (int)$pdo->query("SELECT COUNT(*) FROM schools WHERE code REGEXP '^SCH0[0-9]'")->fetchColumn();
    $n_hm       = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='headmaster' AND username LIKE 'mkuu.%'")->fetchColumn();
    $n_teachers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='teacher' AND username LIKE 'mwl.%.s%'")->fetchColumn();
    $n_students = (int)$pdo->query("SELECT COUNT(*) FROM students s JOIN schools sc ON sc.id=s.school_id WHERE sc.code REGEXP '^SCH0[0-9]'")->fetchColumn();
    $n_marks    = (int)$pdo->query("SELECT COUNT(*) FROM marks WHERE exam_id=$exam_id")->fetchColumn();

    echo "\n=== SEED IMEKAMILIKA ===\n";
    echo "Shule      : $n_schools\n";
    echo "Wakuu      : $n_hm\n";
    echo "Walimu     : $n_teachers\n";
    echo "Wanafunzi  : $n_students\n";
    echo "Alama      : $n_marks\n";
    echo "Mtihani ID : $exam_id (Muhula I, 2026, open)\n";
    echo "\nAkaunti za majaribio:\n";
    echo "  Super Admin  : username=super            pass=Admin@123\n";
    echo "  District     : username=district         pass=Test@1234\n";
    echo "  Mkuu (SCH001): username=mkuu.iramba      pass=Test@1234\n";
    echo "  Mwalimu      : username=mwl.kilima.s1    pass=Test@1234\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\nKOSA: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
