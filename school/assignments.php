<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['headmaster']);

$user      = current_user();
$school_id = (int)($user['school_id'] ?? 0);

// ── POST actions ───────────────────────────────────────────────
if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_assignment') {
        $teacher_id = (int)($_POST['teacher_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $level_id   = (int)($_POST['level_id']   ?? 0);

        $err = null;
        if (!$teacher_id || !$subject_id || !$level_id) {
            $err = 'Please fill in all fields.';
        }

        if (!$err) {
            // Teacher must belong to this school
            $chk = db()->prepare(
                'SELECT 1 FROM users WHERE id=:t AND school_id=:s AND role="teacher" AND status="active" LIMIT 1'
            );
            $chk->execute([':t' => $teacher_id, ':s' => $school_id]);
            if (!$chk->fetch()) $err = 'This teacher does not belong to this school.';
        }

        if (!$err) {
            // Subject must be activated for this school
            $chk = db()->prepare(
                'SELECT 1 FROM school_subjects WHERE school_id=:s AND subject_id=:sub LIMIT 1'
            );
            $chk->execute([':s' => $school_id, ':sub' => $subject_id]);
            if (!$chk->fetch()) $err = 'This subject is not activated for this school.';
        }

        if (!$err) {
            // Level category must match subject category
            $chk = db()->prepare(
                'SELECT 1 FROM subjects s JOIN levels l ON l.category = s.category
                 WHERE s.id=:sub AND l.id=:lvl LIMIT 1'
            );
            $chk->execute([':sub' => $subject_id, ':lvl' => $level_id]);
            if (!$chk->fetch()) $err = 'The selected class level does not match the subject type (O-Level / A-Level).';
        }

        if (!$err) {
            try {
                db()->prepare(
                    'INSERT INTO teacher_assignments (teacher_id, school_id, subject_id, level_id)
                     VALUES (:t, :s, :sub, :l)'
                )->execute([':t' => $teacher_id, ':s' => $school_id, ':sub' => $subject_id, ':l' => $level_id]);
                flash_set('success', 'Assignment added.');
            } catch (\PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    flash_set('error', 'This teacher is already assigned to that subject and class.');
                } else {
                    throw $ex;
                }
            }
        } else {
            flash_set('error', $err);
        }

        redirect('school/assignments.php');
    }

    if ($action === 'delete_assignment') {
        $id = (int)($_POST['assignment_id'] ?? 0);
        if ($id > 0) {
            // Only delete if it belongs to this school
            db()->prepare('DELETE FROM teacher_assignments WHERE id=:id AND school_id=:s')
               ->execute([':id' => $id, ':s' => $school_id]);
            flash_set('success', 'Assignment removed.');
        }
        redirect('school/assignments.php');
    }
}

// ── Reference data ─────────────────────────────────────────────
$teachers_stmt = db()->prepare(
    'SELECT id, full_name FROM users
     WHERE role="teacher" AND school_id=:s AND status="active"
     ORDER BY full_name'
);
$teachers_stmt->execute([':s' => $school_id]);
$teachers = $teachers_stmt->fetchAll();

// Subjects activated for this school
$subjects_stmt = db()->prepare(
    'SELECT s.id, s.name, s.code, s.category
     FROM subjects s
     JOIN school_subjects ss ON ss.subject_id = s.id
     WHERE ss.school_id = :sch AND s.status = "active"
     ORDER BY s.category, s.name'
);
$subjects_stmt->execute([':sch' => $school_id]);
$subjects = $subjects_stmt->fetchAll();

$all_levels = db()->query('SELECT * FROM levels ORDER BY id')->fetchAll();

// ── Current permanent assignments for this school ──────────────
$stmt = db()->prepare(
    'SELECT ta.id, ta.teacher_id, ta.subject_id, ta.level_id, ta.created_at,
            u.full_name AS teacher_name,
            sub.name AS subject_name, sub.code AS subject_code, sub.category,
            lv.name  AS level_name
     FROM teacher_assignments ta
     JOIN users    u   ON u.id   = ta.teacher_id
     JOIN subjects sub ON sub.id = ta.subject_id
     JOIN levels   lv  ON lv.id  = ta.level_id
     WHERE ta.school_id = :s
     ORDER BY u.full_name, lv.id, sub.name'
);
$stmt->execute([':s' => $school_id]);
$assignments = $stmt->fetchAll();

// ── JS data for cascading dropdowns ───────────────────────────
$js_data = json_encode([
    'subjects' => $subjects,
    'levels'   => $all_levels,
], JSON_HEX_TAG);

render_header('Assignments');
?>

<div class="page-heading">
  <h4>Teacher Assignments
    <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?= count($assignments) ?></span>
  </h4>
  <?php if (!empty($teachers) && !empty($subjects)): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAdd">
    + Add Assignment
  </button>
  <?php elseif (empty($teachers)): ?>
  <a href="<?= e(url('school/teachers.php')) ?>" class="btn btn-outline-primary btn-sm">
    Register Teachers First
  </a>
  <?php elseif (empty($subjects)): ?>
  <a href="<?= e(url('school/subjects.php')) ?>" class="btn btn-outline-primary btn-sm">
    Activate Subjects First
  </a>
  <?php endif; ?>
</div>

<div class="alert alert-info py-2 small">
  Assignments here are <strong>permanent</strong> — a teacher owns their subject and class for every exam.
  Only you (the headmaster) can add or remove assignments.
</div>

<?php if (empty($assignments)): ?>
  <div class="text-center text-muted py-5">No assignments yet. Add a teacher's subject and class above.</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle small">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Teacher</th>
          <th>Subject</th>
          <th>Class</th>
          <th>Level</th>
          <th>Assigned On</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($assignments as $i => $a): ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= e($a['teacher_name']) ?></td>
          <td>
            <?= e($a['subject_name']) ?>
            <span class="badge bg-light text-dark border ms-1"><?= e($a['subject_code']) ?></span>
          </td>
          <td><?= e($a['level_name']) ?></td>
          <td>
            <span class="badge <?= $a['category'] === 'o_level' ? 'bg-primary' : 'bg-warning text-dark' ?>">
              <?= $a['category'] === 'o_level' ? 'O-Level' : 'A-Level' ?>
            </span>
          </td>
          <td class="text-muted"><?= e(date('d/m/Y', strtotime($a['created_at']))) ?></td>
          <td>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action"        value="delete_assignment">
              <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-outline-danger btn-sm" type="submit"
                      onclick="return confirm('Remove this assignment from <?= e(addslashes($a['teacher_name'])) ?>?')">
                Remove
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal: Add Assignment ──────────────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_assignment">

        <div class="modal-header">
          <h5 class="modal-title">Assign Subject to Teacher</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">

            <div class="col-12">
              <label class="form-label">Teacher <span class="text-danger">*</span></label>
              <select class="form-select" name="teacher_id" required>
                <option value="">— Select Teacher —</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= (int)$t['id'] ?>"><?= e($t['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Subject <span class="text-danger">*</span></label>
              <select class="form-select" name="subject_id" id="selSubject" required>
                <option value="">— Select Subject —</option>
                <?php foreach ($subjects as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"
                    data-cat="<?= e($s['category']) ?>">
                    <?= e($s['name']) ?> (<?= e($s['code']) ?>)
                    — <?= $s['category'] === 'o_level' ? 'O-Level' : 'A-Level' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Class <span class="text-danger">*</span></label>
              <select class="form-select" name="level_id" id="selLevel" required disabled>
                <option value="">— Select a subject first —</option>
              </select>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  'use strict';

  const DATA      = <?= $js_data ?>;
  const selSubj   = document.getElementById('selSubject');
  const selLevel  = document.getElementById('selLevel');

  if (!selSubj) return;

  function setOptions(sel, opts, placeholder) {
    sel.innerHTML = `<option value="">${placeholder}</option>`;
    opts.forEach(o => {
      const opt = document.createElement('option');
      opt.value       = o.value;
      opt.textContent = o.label;
      sel.appendChild(opt);
    });
    sel.disabled = opts.length === 0;
  }

  selSubj.addEventListener('change', () => {
    const opt = selSubj.options[selSubj.selectedIndex];
    const cat = opt ? opt.dataset.cat : '';

    if (!cat) {
      setOptions(selLevel, [], '— Select a subject first —');
      return;
    }

    // Show only levels matching the subject's category
    const lvls = DATA.levels
      .filter(l => l.category === cat)
      .map(l => ({ value: l.id, label: l.name }));

    setOptions(selLevel, lvls, lvls.length ? '— Select Class —' : '— No classes —');
  });
})();
</script>

<?php
render_footer();
