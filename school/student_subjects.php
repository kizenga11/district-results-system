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

$level_id = (int)($_GET['level_id'] ?? 0);

// O-Level levels only
$levels = db()->query('SELECT id, name FROM levels WHERE category="o_level" ORDER BY id')->fetchAll();
$level_ids = array_map('intval', array_column($levels, 'id'));
if ($level_id === 0 && !empty($level_ids)) $level_id = (int)$level_ids[0];
if ($level_id && !in_array($level_id, $level_ids, true)) $level_id = 0;

// Active O-Level subjects for this school
$subjects = db()->prepare(
    'SELECT sub.id, sub.name, sub.code
     FROM school_subjects ss
     JOIN subjects sub ON sub.id = ss.subject_id
     WHERE ss.school_id = :s AND sub.category = "o_level" AND sub.status = "active"
     ORDER BY sub.name'
);
$subjects->execute([':s' => $school_id]);
$subjects = $subjects->fetchAll();

// Students for selected level
$students = [];
$assigned_map = []; // student_id => [subject_id => true]

if ($level_id > 0) {
    $st = db()->prepare(
        'SELECT id, full_name, admission_no
         FROM students
         WHERE school_id=:s AND level_id=:l AND status="active"
         ORDER BY full_name'
    );
    $st->execute([':s' => $school_id, ':l' => $level_id]);
    $students = $st->fetchAll();

    if ($students) {
        $sids = array_map('intval', array_column($students, 'id'));
        $placeholders = implode(',', array_fill(0, count($sids), '?'));
        $q = db()->prepare(
            "SELECT student_id, subject_id FROM student_subjects WHERE student_id IN ({$placeholders})"
        );
        $q->execute($sids);
        foreach ($q->fetchAll() as $r) {
            $sid = (int)$r['student_id'];
            $sub = (int)$r['subject_id'];
            $assigned_map[$sid][$sub] = true;
        }
    }
}

if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $post_level = (int)($_POST['level_id'] ?? 0);
    if ($action === 'save' && $post_level > 0 && in_array($post_level, $level_ids, true)) {
        // Map allowed subjects for safety
        $allowed = array_fill_keys(array_map('intval', array_column($subjects, 'id')), true);
        $rows = (array)($_POST['subs'] ?? []); // subs[student_id][] = subject_id

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Delete existing for students in this level (school scope)
            $pdo->prepare(
                'DELETE ss
                 FROM student_subjects ss
                 JOIN students st ON st.id = ss.student_id
                 WHERE st.school_id=:s AND st.level_id=:l'
            )->execute([':s' => $school_id, ':l' => $post_level]);

            $ins = $pdo->prepare('INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (:st,:sub)');
            foreach ($rows as $studentId => $subIds) {
                $studentId = (int)$studentId;
                foreach ((array)$subIds as $subId) {
                    $subId = (int)$subId;
                    if ($studentId > 0 && $subId > 0 && isset($allowed[$subId])) {
                        $ins->execute([':st' => $studentId, ':sub' => $subId]);
                    }
                }
            }

            $pdo->commit();
            flash_set('success', 'Student subjects saved.');
            redirect('school/student_subjects.php?level_id=' . $post_level);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Student subjects save failed: ' . $e->getMessage());
            flash_set('error', 'Failed to save student subjects. Please try again.');
            redirect('school/student_subjects.php?level_id=' . $post_level);
        }
    }
}

render_header('Assign O-Level Subjects');
?>

<div class="page-heading">
  <h4>Assign O-Level Subjects</h4>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('school/students.php')) ?>">Back</a>
</div>

<form method="get" class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <label class="form-label small mb-1">Class (O-Level)</label>
    <select class="form-select form-select-sm" name="level_id" onchange="this.form.submit()">
      <?php foreach ($levels as $lv): ?>
        <option value="<?= (int)$lv['id'] ?>" <?= $level_id === (int)$lv['id'] ? 'selected' : '' ?>>
          <?= e($lv['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if (empty($subjects)): ?>
  <div class="alert alert-warning">No O-Level subjects activated for this school. Activate them first on the Subjects page.</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="level_id" value="<?= (int)$level_id ?>">

  <div class="card shadow-sm">
    <div class="d-sm-none text-muted small px-3 pt-2">Swipe table left/right to see all subjects</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle small">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Student</th>
            <?php foreach ($subjects as $sub): ?>
              <th class="text-center" title="<?= e($sub['name']) ?>">
                <span class="badge bg-light text-dark border"><?= e($sub['code']) ?></span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $i => $st): $sid = (int)$st['id']; ?>
            <tr>
              <td class="text-muted"><?= $i + 1 ?></td>
              <td>
                <div class="fw-semibold"><?= e($st['full_name']) ?></div>
                <div class="text-muted"><?= e($st['admission_no']) ?></div>
              </td>
              <?php foreach ($subjects as $sub): $subId = (int)$sub['id']; ?>
                <td class="text-center">
                  <input class="form-check-input" type="checkbox" name="subs[<?= $sid ?>][]" value="<?= $subId ?>"
                    <?= isset($assigned_map[$sid][$subId]) ? 'checked' : '' ?>>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($students)): ?>
            <tr><td colspan="<?= 2 + count($subjects) ?>" class="text-center text-muted py-4">No students found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body">
      <button class="btn btn-primary" type="submit" <?= empty($subjects) ? 'disabled' : '' ?>>Save</button>
    </div>
  </div>
</form>

<?php
render_footer();
