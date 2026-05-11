<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

require_auth();

$user = current_user();
if (!in_array($user['role'], ['super_admin', 'district_admin'], true)) {
    http_response_code(403);
    echo 'Access denied. Super admin or district admin only.';
    exit;
}

$output = [];
$error  = null;

if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'run_upgrade') {
        $pdo = db();
        try {
            // ── 1. Ensure alevel_combinations is global ─────────────
            $has_school_id = false;
            try {
                $r = $pdo->query("SHOW COLUMNS FROM alevel_combinations LIKE 'school_id'");
                $has_school_id = (bool)$r->fetch();
            } catch (\Throwable $e) {
                // Table may not exist yet
            }

            if ($has_school_id) {
                $output[] = 'Migrating legacy per-school combos to global...';

                // Create temp global tables
                $pdo->exec("CREATE TABLE IF NOT EXISTS alevel_combinations_global (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  code VARCHAR(20) NOT NULL,
                  name VARCHAR(120) NULL,
                  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uniq_combo_code (code)
                ) ENGINE=InnoDB");

                $pdo->exec("CREATE TABLE IF NOT EXISTS alevel_combination_subjects_global (
                  combination_id INT NOT NULL,
                  subject_id INT NOT NULL,
                  PRIMARY KEY (combination_id, subject_id),
                  CONSTRAINT fk_combo_sub_combo_g FOREIGN KEY (combination_id) REFERENCES alevel_combinations_global(id) ON DELETE CASCADE,
                  CONSTRAINT fk_combo_sub_subject_g FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB");

                $pdo->exec("CREATE TABLE IF NOT EXISTS school_alevel_combinations_global (
                  school_id INT NOT NULL,
                  combination_id INT NOT NULL,
                  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (school_id, combination_id),
                  CONSTRAINT fk_sac_school_g FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
                  CONSTRAINT fk_sac_combo_g  FOREIGN KEY (combination_id) REFERENCES alevel_combinations_global(id) ON DELETE CASCADE
                ) ENGINE=InnoDB");

                // Merge by code
                $pdo->exec("INSERT IGNORE INTO alevel_combinations_global (code, name, status, created_at)
                    SELECT UPPER(TRIM(code)),
                           NULLIF(MAX(NULLIF(TRIM(name), '')), ''),
                           'active',
                           MIN(created_at)
                    FROM alevel_combinations
                    GROUP BY UPPER(TRIM(code))");
                $output[] = 'Global combos created.';

                // Copy subjects mapping
                $pdo->exec("INSERT IGNORE INTO alevel_combination_subjects_global (combination_id, subject_id)
                    SELECT cg.id, cs.subject_id
                    FROM alevel_combinations c
                    JOIN alevel_combinations_global cg ON cg.code = UPPER(TRIM(c.code))
                    JOIN alevel_combination_subjects cs ON cs.combination_id = c.id");
                $output[] = 'Subject mapping migrated.';

                // Copy school activation
                $pdo->exec("INSERT IGNORE INTO school_alevel_combinations_global (school_id, combination_id, status, created_at)
                    SELECT c.school_id, cg.id, c.status, c.created_at
                    FROM alevel_combinations c
                    JOIN alevel_combinations_global cg ON cg.code = UPPER(TRIM(c.code))");
                $output[] = 'School activation migrated.';

                // Update student_combinations if exists
                try {
                    $pdo->exec("UPDATE student_combinations sc
                        JOIN alevel_combinations c_old ON c_old.id = sc.combination_id
                        JOIN alevel_combinations_global c_new ON c_new.code = UPPER(TRIM(c_old.code))
                        SET sc.combination_id = c_new.id");
                    $output[] = 'Student combinations updated.';
                } catch (\Throwable $e) {
                    // student_combinations might not exist yet
                }

                // Drop FKs on legacy tables first
                $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

                // Rename legacy tables
                $pdo->exec("RENAME TABLE alevel_combination_subjects TO alevel_combination_subjects_legacy");
                $pdo->exec("RENAME TABLE alevel_combinations TO alevel_combinations_legacy");

                // Rename global to live
                $pdo->exec("RENAME TABLE alevel_combinations_global TO alevel_combinations");
                $pdo->exec("RENAME TABLE alevel_combination_subjects_global TO alevel_combination_subjects");

                // school_alevel_combinations may or may not exist yet
                try {
                    $pdo->exec("RENAME TABLE school_alevel_combinations TO school_alevel_combinations_legacy");
                } catch (\Throwable $e) {
                    // didn't exist
                }
                try {
                    $pdo->exec("RENAME TABLE school_alevel_combinations_global TO school_alevel_combinations");
                } catch (\Throwable $e) {
                    // already renamed or didn't exist
                }

                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                $output[] = 'Tables swapped. Migration complete.';
            } else {
                $output[] = 'alevel_combinations is already global (no school_id). No migration needed.';
            }

            // ── 2. Create school_alevel_combinations if not exists ──
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS school_alevel_combinations (
                  school_id INT NOT NULL,
                  combination_id INT NOT NULL,
                  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (school_id, combination_id),
                  CONSTRAINT fk_sac_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
                  CONSTRAINT fk_sac_combo  FOREIGN KEY (combination_id) REFERENCES alevel_combinations(id) ON DELETE CASCADE
                ) ENGINE=InnoDB");
                $output[] = 'school_alevel_combinations table ready.';
            } catch (\Throwable $e) {
                $output[] = 'WARN (school_alevel_combinations): ' . $e->getMessage();
            }

            // ── 3. Create/ensure alevel_combinations (global) ──────
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS alevel_combinations (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  code VARCHAR(20) NOT NULL,
                  name VARCHAR(120) NULL,
                  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uniq_combo_code (code)
                ) ENGINE=InnoDB");
                $output[] = 'alevel_combinations (global) ready.';
            } catch (\Throwable $e) {
                $output[] = 'WARN (alevel_combinations): ' . $e->getMessage();
            }

            // ── 4. Create/ensure alevel_combination_subjects ───────
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS alevel_combination_subjects (
                  combination_id INT NOT NULL,
                  subject_id INT NOT NULL,
                  PRIMARY KEY (combination_id, subject_id),
                  CONSTRAINT fk_combo_sub_combo FOREIGN KEY (combination_id) REFERENCES alevel_combinations(id) ON DELETE CASCADE,
                  CONSTRAINT fk_combo_sub_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB");
                $output[] = 'alevel_combination_subjects ready.';
            } catch (\Throwable $e) {
                $output[] = 'WARN (alevel_combination_subjects): ' . $e->getMessage();
            }

            // ── 5. Create/ensure student_combinations ──────────────
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS student_combinations (
                  student_id INT NOT NULL,
                  combination_id INT NOT NULL,
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (student_id),
                  CONSTRAINT fk_student_combo_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                  CONSTRAINT fk_student_combo_combo FOREIGN KEY (combination_id) REFERENCES alevel_combinations(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB");
                $output[] = 'student_combinations ready.';
            } catch (\Throwable $e) {
                $output[] = 'WARN (student_combinations): ' . $e->getMessage();
            }

            // ── 6. Create/ensure teacher_assignments ───────────────
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_assignments (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  teacher_id INT NOT NULL,
                  school_id INT NOT NULL,
                  subject_id INT NOT NULL,
                  level_id TINYINT NOT NULL,
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uniq_teacher_assignment (teacher_id, school_id, subject_id, level_id),
                  CONSTRAINT fk_ta_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
                  CONSTRAINT fk_ta_school  FOREIGN KEY (school_id)  REFERENCES schools(id) ON DELETE CASCADE,
                  CONSTRAINT fk_ta_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
                  CONSTRAINT fk_ta_level   FOREIGN KEY (level_id)   REFERENCES levels(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB");
                $output[] = 'teacher_assignments ready.';
            } catch (\Throwable $e) {
                $output[] = 'WARN (teacher_assignments): ' . $e->getMessage();
            }

            // ── 7. Create/ensure teaching progress tables ──────────
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_topics (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  teacher_assignment_id INT NOT NULL,
                  title VARCHAR(255) NOT NULL,
                  competence TEXT NULL,
                  sort_order INT NOT NULL DEFAULT 0,
                  status ENUM('planned','in_progress','completed') NOT NULL DEFAULT 'planned',
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                  CONSTRAINT fk_tt_assign FOREIGN KEY (teacher_assignment_id) REFERENCES teacher_assignments(id) ON DELETE CASCADE
                ) ENGINE=InnoDB");
                $output[] = 'teacher_topics ready.';
            } catch (\Throwable $e) {
                $output[] = 'WARN (teacher_topics): ' . $e->getMessage();
            }

            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS teaching_progress_log (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  teacher_topic_id INT NOT NULL,
                  log_date DATE NOT NULL,
                  notes TEXT NULL,
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  CONSTRAINT fk_tpl_topic FOREIGN KEY (teacher_topic_id) REFERENCES teacher_topics(id) ON DELETE CASCADE
                ) ENGINE=InnoDB");
                $output[] = 'teaching_progress_log ready.';
            } catch (\Throwable $e) {
                $output[] = 'WARN (teaching_progress_log): ' . $e->getMessage();
            }

            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS topic_tests (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  teacher_topic_id INT NOT NULL,
                  attempt_no INT NOT NULL DEFAULT 1,
                  test_date DATE NULL,
                  status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
                  pass_rate DECIMAL(6,2) NULL,
                  submitted_by INT NULL,
                  submitted_at TIMESTAMP NULL,
                  approved_by INT NULL,
                  approved_at TIMESTAMP NULL,
                  reject_reason TEXT NULL,
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  CONSTRAINT fk_ttest_topic FOREIGN KEY (teacher_topic_id) REFERENCES teacher_topics(id) ON DELETE CASCADE,
                  CONSTRAINT fk_ttest_submit FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
                  CONSTRAINT fk_ttest_approve FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB");
                $output[] = 'topic_tests ready.';
            } catch (\Throwable $e) {
                $output[] = 'WARN (topic_tests): ' . $e->getMessage();
            }

            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS topic_test_students (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  topic_test_id INT NOT NULL,
                  student_id INT NOT NULL,
                  score DECIMAL(6,2) NULL,
                  absent TINYINT(1) NOT NULL DEFAULT 0,
                  CONSTRAINT fk_tts_test FOREIGN KEY (topic_test_id) REFERENCES topic_tests(id) ON DELETE CASCADE,
                  CONSTRAINT fk_tts_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                  UNIQUE KEY uniq_tts (topic_test_id, student_id)
                ) ENGINE=InnoDB");
                $output[] = 'topic_test_students ready.';
            } catch (\Throwable $e) {
                $output[] = 'WARN (topic_test_students): ' . $e->getMessage();
            }

            // ── 8. Grading scale corrections (schema4) ────────────
            try {
                // O-Level F: points → 5
                $pdo->exec("UPDATE grading_scales SET points = 5
                             WHERE category = 'o_level' AND grade = 'F'
                               AND (points IS NULL OR points <> 5)");

                // A-Level F range: max_mark → 34.999
                $pdo->exec("UPDATE grading_scales SET max_mark = 34.999
                             WHERE category = 'a_level' AND grade = 'F'
                               AND max_mark <> 34.999");

                // A-Level S grade (35–39.999, pts 6)
                $pdo->exec("INSERT IGNORE INTO grading_scales
                              (category, grade, min_mark, max_mark, points)
                             VALUES ('a_level', 'S', 35, 39.999, 6)");

                // A-Level F: points → 7
                $pdo->exec("UPDATE grading_scales SET points = 7
                             WHERE category = 'a_level' AND grade = 'F'
                               AND (points IS NULL OR points <> 7)");

                // A-Level grades A–F (insert if missing)
                $pdo->exec("INSERT IGNORE INTO grading_scales
                              (category, grade, min_mark, max_mark, points)
                             VALUES
                               ('a_level','A',80,100,1),
                               ('a_level','B',70,79.999,2),
                               ('a_level','C',60,69.999,3),
                               ('a_level','D',50,59.999,4),
                               ('a_level','E',40,49.999,5)");

                // Fix existing O-Level F marks: NULL pts → 5
                $pdo->exec("UPDATE marks m
                             JOIN exams e ON e.id = m.exam_id
                             SET m.points = 5
                             WHERE m.grade = 'F'
                               AND e.category = 'o_level'
                               AND m.points IS NULL");

                // Reclassify A-Level marks 35–39.999% from F → S
                $pdo->exec("UPDATE marks m
                             JOIN exams e ON e.id = m.exam_id
                             SET m.grade = 'S', m.points = 6
                             WHERE e.category = 'a_level'
                               AND m.total_percent >= 35
                               AND m.total_percent <= 39.999
                               AND m.grade = 'F'");

                // Fix remaining A-Level F marks: NULL pts → 7
                $pdo->exec("UPDATE marks m
                             JOIN exams e ON e.id = m.exam_id
                             SET m.points = 7
                             WHERE m.grade = 'F'
                               AND e.category = 'a_level'
                               AND m.points IS NULL");

                $output[] = 'Grading scales corrected (O-Level F=5, A-Level S added, A-Level F=7).';
            } catch (\Throwable $e) {
                $output[] = 'WARN (grading scales): ' . $e->getMessage();
            }

            // ── 9. Add absent column to marks table ─────────────────
            try {
                $r = $pdo->query("SHOW COLUMNS FROM marks LIKE 'absent'");
                if (!$r->fetch()) {
                    $pdo->exec("ALTER TABLE marks ADD COLUMN absent TINYINT(1) NOT NULL DEFAULT 0 AFTER practical_mark");
                    $output[] = 'Added absent column to marks table.';
                } else {
                    $output[] = 'absent column already exists in marks table.';
                }
            } catch (\Throwable $e) {
                $output[] = 'WARN (absent column): ' . $e->getMessage();
            }

            $output[] = 'All upgrades completed.';

        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

function table_exists_pdo(PDO $pdo, string $table): bool {
    try {
        $r = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $table) . "'");
        return (bool)$r->fetch();
    } catch (\Throwable $e) {
        return false;
    }
}

$tables_expected = [
    'alevel_combinations',
    'alevel_combination_subjects',
    'school_alevel_combinations',
    'student_combinations',
    'teacher_assignments',
    'teacher_topics',
    'teaching_progress_log',
    'topic_tests',
    'topic_test_students',
];

// ── Fetch grading scales for display ──────────────────────────
$grading_scales = ['o_level' => [], 'a_level' => []];
try {
    $rows = db()->query(
        'SELECT category, grade, min_mark, max_mark, points
         FROM grading_scales ORDER BY category, min_mark DESC'
    )->fetchAll();
    foreach ($rows as $row) {
        $grading_scales[$row['category']][] = $row;
    }
} catch (\Throwable $e) {
    // table may not exist yet
}

// Expected correct values for validation
$expected_scales = [
    'o_level' => [
        'A' => ['min' => 75,  'max' => 100,    'pts' => 1],
        'B' => ['min' => 65,  'max' => 74.999, 'pts' => 2],
        'C' => ['min' => 50,  'max' => 64.999, 'pts' => 3],
        'D' => ['min' => 30,  'max' => 49.999, 'pts' => 4],
        'F' => ['min' => 0,   'max' => 29.999, 'pts' => 5],
    ],
    'a_level' => [
        'A' => ['min' => 80,  'max' => 100,    'pts' => 1],
        'B' => ['min' => 70,  'max' => 79.999, 'pts' => 2],
        'C' => ['min' => 60,  'max' => 69.999, 'pts' => 3],
        'D' => ['min' => 50,  'max' => 59.999, 'pts' => 4],
        'E' => ['min' => 40,  'max' => 49.999, 'pts' => 5],
        'S' => ['min' => 35,  'max' => 39.999, 'pts' => 6],
        'F' => ['min' => 0,   'max' => 34.999, 'pts' => 7],
    ],
];

render_header('Database Setup');
?>

<div class="container py-4">
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h4 class="mb-0">Database Schema Upgrade</h4>
      <span class="badge bg-secondary">Run Once</span>
    </div>
    <div class="card-body">

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if ($output): ?>
        <div class="alert alert-info">
          <strong>Upgrade results:</strong>
          <ul class="mb-0 small mt-1">
            <?php foreach ($output as $line): ?>
              <li><?= e($line) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- ── Table status ──────────────────── -->
      <div class="mb-4">
        <strong>Current table status:</strong>
        <div class="table-responsive">
        <table class="table table-sm small mt-2" style="max-width:500px">
          <thead class="table-light">
            <tr><th>Table</th><th>Exists?</th></tr>
          </thead>
          <tbody>
            <?php $pdo2 = db(); ?>
            <?php foreach ($tables_expected as $tbl): ?>
              <tr>
                <td><code><?= e($tbl) ?></code></td>
                <td>
                  <?php if (table_exists_pdo($pdo2, $tbl)): ?><span class="badge bg-success">Yes</span>
                  <?php else: ?>
                    <span class="badge bg-danger">No</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>

      <!-- ── Grading scales ──────────────────── -->
      <div class="mb-4">
        <strong>Grading Scales:</strong>
        <div class="row g-3 mt-1">

          <?php foreach (['o_level' => 'O-Level', 'a_level' => 'A-Level'] as $cat => $label): ?>
          <div class="col-12 col-md-6">
            <div class="card border-0 bg-light">
              <div class="card-header bg-light fw-semibold py-2">
                <?= $label ?>
                <?php if (empty($grading_scales[$cat])): ?>
                  <span class="badge bg-danger ms-2">Missing</span>
                <?php else: ?>
                  <?php
                    $all_ok = true;
                    $found_grades = array_column($grading_scales[$cat], 'grade');
                    foreach ($expected_scales[$cat] as $g => $exp) {
                        if (!in_array($g, $found_grades, true)) { $all_ok = false; break; }
                    }
                  ?>
                  <span class="badge <?= $all_ok ? 'bg-success' : 'bg-warning text-dark' ?> ms-2">
                    <?= $all_ok ? 'OK' : 'Needs upgrade' ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="card-body p-0">
                <?php if (empty($grading_scales[$cat])): ?>
                  <p class="text-muted small p-3 mb-0">No scales found — run upgrade.</p>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                  <thead class="table-light">
                    <tr>
                      <th>Grade</th>
                      <th>Range</th>
                      <th>Points</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($grading_scales[$cat] as $sc):
                      $exp  = $expected_scales[$cat][$sc['grade']] ?? null;
                      $ok   = $exp !== null
                           && (int)$sc['points']   === $exp['pts']
                           && (float)$sc['min_mark'] >= $exp['min'] - 0.01
                           && (float)$sc['max_mark'] <= $exp['max'] + 0.01;
                    ?>
                    <tr>
                      <td>
                        <?php
                          $badge_colors = [
                            'A' => 'bg-success', 'B' => 'bg-primary',
                            'C' => 'bg-info text-dark', 'D' => 'bg-warning text-dark',
                            'E' => 'bg-secondary', 'S' => 'bg-secondary', 'F' => 'bg-danger',
                          ];
                          $bcls = $badge_colors[$sc['grade']] ?? 'bg-secondary';
                        ?>
                        <span class="badge <?= $bcls ?>"><?= e($sc['grade']) ?></span>
                      </td>
                      <td><?= number_format((float)$sc['min_mark'], 0) ?>–<?= number_format((float)$sc['max_mark'], 0) ?></td>
                      <td><strong><?= $sc['points'] ?? '—' ?></strong></td>
                      <td>
                        <?php if (!$exp): ?>
                          <span class="text-warning" title="Unexpected grade">?</span>
                        <?php elseif ($ok): ?>
                          <span class="text-success">✓</span>
                        <?php else: ?>
                          <span class="text-danger" title="Incorrect — run upgrade">✗</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php
                      // Show any expected grades that are missing
                      foreach ($expected_scales[$cat] as $g => $exp):
                        if (!in_array($g, array_column($grading_scales[$cat], 'grade'), true)):
                    ?>
                    <tr class="table-danger">
                      <td><span class="badge bg-secondary"><?= e($g) ?></span></td>
                      <td><?= $exp['min'] ?>–<?= $exp['max'] ?></td>
                      <td><?= $exp['pts'] ?></td>
                      <td><span class="text-danger" title="Missing — run upgrade">✗ missing</span></td>
                    </tr>
                    <?php endif; endforeach; ?>
                  </tbody>
                </table>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>

        </div>
      </div>

      <!-- ── Division summary ────────────────── -->
      <div class="mb-4">
        <strong>Division Boundaries:</strong>
        <div class="row g-3 mt-1">

          <?php
          $divisions = [
            'o_level' => [
              'label' => 'O-Level (best 7 subjects)',
              'note'  => 'F = 5 pts',
              'rows'  => [
                ['div' => 'I',   'agg' => '7 – 17',  'color' => 'success'],
                ['div' => 'II',  'agg' => '18 – 21', 'color' => 'primary'],
                ['div' => 'III', 'agg' => '22 – 25', 'color' => 'info'],
                ['div' => 'IV',  'agg' => '26 – 33', 'color' => 'warning'],
                ['div' => '0',   'agg' => '34+',      'color' => 'danger'],
              ],
            ],
            'a_level' => [
              'label' => 'A-Level (best 3 principal subjects)',
              'note'  => 'F = 7 pts · S = 6 pts',
              'rows'  => [
                ['div' => 'I',   'agg' => '3 – 9',   'color' => 'success'],
                ['div' => 'II',  'agg' => '10 – 12', 'color' => 'primary'],
                ['div' => 'III', 'agg' => '13 – 15', 'color' => 'info'],
                ['div' => 'IV',  'agg' => '16 – 19', 'color' => 'warning'],
                ['div' => '0',   'agg' => '20+',      'color' => 'danger'],
              ],
            ],
          ];
          ?>

          <?php foreach ($divisions as $div_info): ?>
          <div class="col-12 col-md-6">
            <div class="card border-0 bg-light">
              <div class="card-header bg-light fw-semibold py-2 d-flex justify-content-between align-items-center">
                <span><?= $div_info['label'] ?></span>
                <span class="text-muted small fw-normal"><?= $div_info['note'] ?></span>
              </div>
              <div class="card-body p-0">
                <table class="table table-sm mb-0 small">
                  <thead class="table-light">
                    <tr>
                      <th>Division</th>
                      <th>Aggregate (points)</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($div_info['rows'] as $row): ?>
                    <tr>
                      <td>
                        <span class="badge bg-<?= $row['color'] ?><?= in_array($row['color'], ['info','warning']) ? ' text-dark' : '' ?> fw-bold">
                          Div <?= $row['div'] ?>
                        </span>
                      </td>
                      <td class="fw-semibold"><?= $row['agg'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <?php endforeach; ?>

        </div>
      </div>

      <p class="text-muted small">
        Creates/upgrades tables for global A-Level combinations system.
        If legacy per-school combos exist, they will be migrated to the global format.
        Also corrects grading scales (O-Level F=5 pts, A-Level S grade, A-Level F=7 pts)
        and fixes any affected marks already in the database.
      </p>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="run_upgrade">
        <button class="btn btn-primary" type="submit">Run Upgrade</button>
        <a href="<?= e(url('dashboard.php')) ?>" class="btn btn-outline-secondary">Back</a>
      </form>
    </div>
  </div>
</div>

<?php
render_footer();
