<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['headmaster']);

$user = current_user();
$school_id = (int)($user['school_id'] ?? 0);
if ($school_id <= 0) {
    http_response_code(400);
    echo 'Invalid school account.';
    exit;
}

// ── Quick DB health check ───────────────────────────────────────
$db_ok = true;
$missing_tables = [];
try {
    $r = db()->query("SHOW TABLES LIKE 'school_alevel_combinations'");
    if (!$r->fetch()) {
        $missing_tables[] = 'school_alevel_combinations';
        $db_ok = false;
    }
} catch (\Throwable $e) {
    $db_ok = false;
}

$level_id = (int)($_GET['level_id'] ?? 0);

// A-Level levels only
$levels = db()->query('SELECT id, name FROM levels WHERE category="a_level" ORDER BY id')->fetchAll();
$level_ids = array_map('intval', array_column($levels, 'id'));
if ($level_id === 0 && !empty($level_ids)) $level_id = (int)$level_ids[0];
if ($level_id && !in_array($level_id, $level_ids, true)) $level_id = 0;

// Active combos for this school
$combos = [];
$combo_ids = [];
if ($db_ok) {
    $combos_stmt = db()->prepare(
        'SELECT c.id, c.code, c.name
         FROM school_alevel_combinations sac
         JOIN alevel_combinations c ON c.id = sac.combination_id
         WHERE sac.school_id = :s AND sac.status = "active" AND c.status = "active"
         ORDER BY c.code'
    );
    $combos_stmt->execute([':s' => $school_id]);
    $combos = $combos_stmt->fetchAll();
    $combo_ids = array_map('intval', array_column($combos, 'id'));
}

// Students for selected level
$students = [];
$assigned = [];

if ($level_id > 0) {
    $st = db()->prepare(
        'SELECT id, full_name, admission_no
         FROM students
         WHERE school_id=:s AND level_id=:l AND status="active"
         ORDER BY full_name'
    );
    $st->execute([':s' => $school_id, ':l' => $level_id]);
    $students = $st->fetchAll();

    if ($students && $db_ok) {
        $ids = implode(',', array_map('intval', array_column($students, 'id')));
        $q = db()->query(
            "SELECT student_id, combination_id FROM student_combinations WHERE student_id IN ({$ids})"
        )->fetchAll();
        foreach ($q as $r) {
            $assigned[(int)$r['student_id']] = (int)$r['combination_id'];
        }
    }
}

if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $post_level = (int)($_POST['level_id'] ?? 0);
    if ($action === 'save' && $post_level > 0 && in_array($post_level, $level_ids, true)) {
        $rows = (array)($_POST['combo'] ?? []); // combo[student_id] = combination_id

        $allowed = array_fill_keys($combo_ids, true);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Delete existing for students in this level
            $pdo->prepare(
                'DELETE sc
                 FROM student_combinations sc
                 JOIN students st ON st.id = sc.student_id
                 WHERE st.school_id=:s AND st.level_id=:l'
            )->execute([':s' => $school_id, ':l' => $post_level]);

            $ins = $pdo->prepare('INSERT INTO student_combinations (student_id, combination_id) VALUES (:st,:c)');
            foreach ($rows as $studentId => $comboId) {
                $studentId = (int)$studentId;
                $comboId = (int)$comboId;
                if ($studentId > 0 && $comboId > 0 && isset($allowed[$comboId])) {
                    $ins->execute([':st' => $studentId, ':c' => $comboId]);
                }
            }

            $pdo->commit();
            flash_set('success', 'Student combinations saved.');
            redirect('school/student_combinations.php?level_id=' . $post_level);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

render_header('Assign A-Level Combinations');
?>

<div class="page-heading">
  <h4>Assign A-Level Combinations</h4>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('school/students.php')) ?>">Back</a>
</div>

<form method="get" class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <label class="form-label small mb-1">Class (A-Level)</label>
    <select class="form-select form-select-sm" name="level_id" onchange="this.form.submit()">
      <?php foreach ($levels as $lv): ?>
        <option value="<?= (int)$lv['id'] ?>" <?= $level_id === (int)$lv['id'] ? 'selected' : '' ?>>
          <?= e($lv['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if (!$db_ok): ?>
  <div class="alert alert-danger">
    Database upgrade required. The required tables are missing.
    Contact the system administrator to run the upgrade.
  </div>
<?php elseif (empty($combos)): ?>
  <div class="alert alert-warning">
    No A-Level combinations activated for this school. Activate combinations first on the Combinations page.
  </div>
<?php endif; ?>

<?php if ($db_ok): ?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="level_id" value="<?= (int)$level_id ?>">

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Student</th>
            <th style="min-width:200px;max-width:320px">Combination</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $i => $st): $sid = (int)$st['id']; ?>
            <tr>
              <td class="text-muted small"><?= $i + 1 ?></td>
              <td>
                <div class="fw-semibold"><?= e($st['full_name']) ?></div>
                <div class="text-muted small"><?= e($st['admission_no']) ?></div>
              </td>
              <td>
                <select class="form-select form-select-sm" name="combo[<?= $sid ?>]" <?= empty($combos) ? 'disabled' : '' ?>>
                  <option value="">— Select —</option>
                  <?php foreach ($combos as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (($assigned[$sid] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                      <?= e($c['code']) ?><?= $c['name'] ? ' — ' . e($c['name']) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($students)): ?>
            <tr><td colspan="3" class="text-center text-muted py-4">No students found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body">
      <button class="btn btn-primary" type="submit" <?= empty($combos) ? 'disabled' : '' ?>>Save</button>
    </div>
  </div>
</form>
<?php endif; ?>

<?php
render_footer();
