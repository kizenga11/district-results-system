<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['headmaster', 'teacher']);

$user    = current_user();
$role    = $user['role'];
$user_id = (int)$user['id'];

// ── Grading scales ─────────────────────────────────────────────
$scales_raw = db()->query('SELECT * FROM grading_scales ORDER BY category, min_mark DESC')->fetchAll();
$scales = ['o_level' => [], 'a_level' => []];
foreach ($scales_raw as $sc) {
    $scales[$sc['category']][] = $sc;
}

// ── Grade calculator ───────────────────────────────────────────
function calc_grade(float $pct, string $category, array $scales): array
{
    foreach ($scales[$category] as $sc) {
        if ($pct >= (float)$sc['min_mark'] && $pct <= (float)$sc['max_mark']) {
            return ['grade' => $sc['grade'], 'points' => $sc['points']];
        }
    }
    return ['grade' => 'F', 'points' => null];
}

// Marks entry is based on permanent teacher assignments (teacher_assignments)
$use_permanent = true;

// ── Assignment list query ──────────────────────────────────────
function get_assignments(int $user_id, string $role): array
{
    // headmaster / teacher — permanent assignments (teacher_assignments × exams)
    $ta_query =
        'SELECT ta.id AS ta_id,
                e.id  AS exam_id, ta.school_id, ta.teacher_id, ta.subject_id, ta.level_id,
                e.name AS exam_name, e.year, e.status AS exam_status, e.category,
                e.marks_open_from, e.marks_open_to,
                sub.name AS subject_name, sub.has_practical, sub.practical_max, sub.code AS subject_code,
                lv.name AS level_name,
                sc.name AS school_name,
                u.full_name AS teacher_name
         FROM teacher_assignments ta
         JOIN subjects    sub ON sub.id       = ta.subject_id
         JOIN levels      lv  ON lv.id        = ta.level_id
         JOIN schools     sc  ON sc.id        = ta.school_id
         JOIN users       u   ON u.id         = ta.teacher_id
         JOIN exams       e   ON e.category   = sub.category
         JOIN exam_levels el  ON el.exam_id   = e.id AND el.level_id = ta.level_id';

    if ($role === 'headmaster') {
        $school_id = (int)(current_user()['school_id'] ?? 0);
        $stmt = db()->prepare($ta_query . ' WHERE ta.school_id = :sid ORDER BY e.year DESC, e.name, u.full_name, lv.id, sub.name');
        $stmt->execute([':sid' => $school_id]);
        return $stmt->fetchAll();
    }

    // teacher
    $stmt = db()->prepare($ta_query . ' WHERE ta.teacher_id = :tid ORDER BY e.year DESC, e.name, lv.id, sub.name');
    $stmt->execute([':tid' => $user_id]);
    return $stmt->fetchAll();
}

$assignments = get_assignments($user_id, $role);

// ── Exams visible to this school but missing assignments (headmaster only) ──
// An exam is "reachable" when its category matches any subject activated for this school
// AND its exam_levels intersect with the levels of those subjects.
// We flag ones that don't appear in $assignments so the headmaster knows why.
$missing_exams = [];
if ($role === 'headmaster') {
    $visible_exam_ids = array_unique(array_column($assignments, 'exam_id'));
    $school_id_hm = (int)($user['school_id'] ?? 0);

    $stmt = db()->prepare(
        'SELECT DISTINCT e.id, e.name, e.year, e.status, e.category,
                e.marks_open_from, e.marks_open_to,
                GROUP_CONCAT(DISTINCT lv.name ORDER BY lv.id SEPARATOR ", ") AS level_names
         FROM exams e
         JOIN exam_levels el ON el.exam_id = e.id
         JOIN levels      lv ON lv.id      = el.level_id
         WHERE e.category IN (
             SELECT DISTINCT sub.category
             FROM school_subjects ss
             JOIN subjects sub ON sub.id = ss.subject_id
             WHERE ss.school_id = :sid
         )
         GROUP BY e.id, e.name, e.year, e.status, e.category, e.marks_open_from, e.marks_open_to
         ORDER BY e.year DESC, e.name'
    );
    $stmt->execute([':sid' => $school_id_hm]);
    foreach ($stmt->fetchAll() as $ex) {
        if (!in_array((int)$ex['id'], $visible_exam_ids, true)) {
            $missing_exams[] = $ex;
        }
    }
}

// ── Resolve selected slot ──────────────────────────────────────
$selected = null;

// URL = ?ta_id=X&exam_id=Y
$ta_id   = (int)($_GET['ta_id']   ?? 0);
$exam_id = (int)($_GET['exam_id'] ?? 0);
if ($ta_id && $exam_id) {
    foreach ($assignments as $a) {
        if ((int)$a['ta_id'] === $ta_id && (int)$a['exam_id'] === $exam_id) {
            $selected = $a;
            break;
        }
    }
}
$back_url = url('teacher/marks_entry.php');
$save_url = $selected ? url('teacher/marks_entry.php?ta_id=' . $ta_id . '&exam_id=' . $exam_id) : $back_url;

// ── Save marks ─────────────────────────────────────────────────
if (is_post() && $selected) {
    csrf_verify();

    $category   = $selected['category'];
    $has_p      = (bool)$selected['has_practical'];
    $p_max      = (float)($selected['practical_max'] ?? 0);
    $theory_max = $has_p ? (100 - $p_max) : 100;

    $saved = 0; $skipped = 0; $errs = [];

    $pdo = db();
    $pdo->beginTransaction();

    $student_ids = array_keys(array_filter(
        $_POST,
        fn($k) => str_starts_with($k, 'theory_'),
        ARRAY_FILTER_USE_KEY
    ));

    foreach ($student_ids as $key) {
        $sid    = (int)str_replace('theory_', '', $key);
        $theory = trim((string)($_POST['theory_' . $sid]     ?? ''));
        $prac   = trim((string)($_POST['practical_' . $sid]  ?? ''));

        if ($theory === '') { $skipped++; continue; }

        $theory_val = (float)$theory;
        if ($theory_val < 0 || $theory_val > $theory_max) {
            $errs[] = "Student #{$sid}: theory mark must be 0–{$theory_max}.";
            continue;
        }

        $prac_val = null;
        if ($has_p) {
            if ($prac === '') { $skipped++; continue; }
            $prac_val = (float)$prac;
            if ($prac_val < 0 || $prac_val > $p_max) {
                $errs[] = "Student #{$sid}: practical mark must be 0–{$p_max}.";
                continue;
            }
        }

        $total = $has_p ? ($theory_val + $prac_val) : $theory_val;
        $g     = calc_grade($total, $category, $scales);

        db()->prepare(
            'INSERT INTO marks (exam_id, student_id, subject_id, theory_mark, practical_mark,
             total_percent, grade, points, created_by)
             VALUES (:eid, :sid, :subid, :theory, :prac, :total, :grade, :pts, :by)
             ON DUPLICATE KEY UPDATE
               theory_mark    = VALUES(theory_mark),
               practical_mark = VALUES(practical_mark),
               total_percent  = VALUES(total_percent),
               grade          = VALUES(grade),
               points         = VALUES(points)'
        )->execute([
            ':eid'    => $selected['exam_id'],
            ':sid'    => $sid,
            ':subid'  => $selected['subject_id'],
            ':theory' => $theory_val,
            ':prac'   => $prac_val,
            ':total'  => $total,
            ':grade'  => $g['grade'],
            ':pts'    => $g['points'],
            ':by'     => $user_id,
        ]);
        $saved++;
    }

    if (empty($errs)) {
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }

    if (!empty($errs)) {
        flash_set('error', implode(' | ', $errs));
    } elseif ($saved > 0) {
        flash_set('success', "{$saved} mark(s) saved.");
    } else {
        flash_set('error', 'No marks were entered.');
    }

    header('Location: ' . $save_url);
    exit;
}

// ── Load students + existing marks ─────────────────────────────
$students  = [];
$marks_map = [];
if ($selected) {
    // Only load students who are assigned this subject (O-Level) or whose combination contains it (A-Level)
    if ($selected['category'] === 'o_level') {
        $st_stmt = db()->prepare(
            'SELECT st.id, st.full_name, st.admission_no, st.sex
             FROM students st
             JOIN student_subjects ss ON ss.student_id = st.id AND ss.subject_id = :sub
             WHERE st.school_id=:s AND st.level_id=:l AND st.status="active"
             ORDER BY st.full_name'
        );
        $st_stmt->execute([':s' => $selected['school_id'], ':l' => $selected['level_id'], ':sub' => $selected['subject_id']]);
    } else {
        $st_stmt = db()->prepare(
            'SELECT st.id, st.full_name, st.admission_no, st.sex
             FROM students st
             JOIN student_combinations sc ON sc.student_id = st.id
             JOIN alevel_combination_subjects cs ON cs.combination_id = sc.combination_id AND cs.subject_id = :sub
             WHERE st.school_id=:s AND st.level_id=:l AND st.status="active"
             ORDER BY st.full_name'
        );
        $st_stmt->execute([':s' => $selected['school_id'], ':l' => $selected['level_id'], ':sub' => $selected['subject_id']]);
    }
    $students = $st_stmt->fetchAll();

    if ($students) {
        $sids = array_map('intval', array_column($students, 'id'));
        $placeholders = implode(',', array_fill(0, count($sids), '?'));
        $mk_stmt = db()->prepare(
            "SELECT student_id, theory_mark, practical_mark, total_percent, grade, points
             FROM marks WHERE exam_id=? AND subject_id=? AND student_id IN ({$placeholders})"
        );
        $mk_stmt->execute(array_merge([$selected['exam_id'], $selected['subject_id']], $sids));
        foreach ($mk_stmt->fetchAll() as $m) {
            $marks_map[(int)$m['student_id']] = $m;
        }
    }
}

// ── Entry window check ─────────────────────────────────────────
$today      = date('Y-m-d');
$entry_open = true;
$entry_msg  = '';
if ($selected) {
    $from = $selected['marks_open_from'];
    $to   = $selected['marks_open_to'];
    if ($selected['exam_status'] !== 'open') {
        $entry_open = false;
        $entry_msg  = 'This exam ' . ($selected['exam_status'] === 'draft' ? 'is still a draft' : 'is closed') . '.';
    } elseif ($from && $today < $from) {
        $entry_open = false;
        $entry_msg  = 'The marks entry window opens on ' . date('d/m/Y', strtotime($from)) . '.';
    } elseif ($to && $today > $to) {
        $entry_open = false;
        $entry_msg  = 'The marks entry window closed on ' . date('d/m/Y', strtotime($to)) . '.';
    }
}

$has_p      = $selected ? (bool)$selected['has_practical'] : false;
$p_max      = $selected ? (float)($selected['practical_max'] ?? 0) : 0;
$theory_max = $has_p ? (100 - $p_max) : 100;

$scales_json = json_encode($scales, JSON_HEX_TAG);

render_header('Enter Marks');
?>

<?php if (!$selected): ?>
<!-- ══ Assignment list ══════════════════════════════════════════ -->
<div class="page-heading">
  <h4>Enter Marks — Select Assignment</h4>
</div>

<?php
// ── Mitihani inayopatikana lakini haina assignments (headmaster only) ──
if ($role === 'headmaster' && !empty($missing_exams)):
    $assign_url = url('school/assignments.php');
    $status_badges = [
        'open'   => '<span class="badge bg-success">Open</span>',
        'draft'  => '<span class="badge bg-secondary">Draft</span>',
        'closed' => '<span class="badge bg-danger">Closed</span>',
    ];
?>
<div class="alert alert-warning mb-3">
  <div class="fw-semibold mb-1">
    <?= icon('exam') ?> Mitihani inayopatikana — assignments hazijawekwa bado
  </div>
  <p class="mb-2 small">
    Mitihani ifuatayo ipo katika mfumo lakini <strong>haionekani kwenye orodha yako</strong>
    kwa sababu hakuna mwalimu aliyepangiwa masomo yanayofanana na madarasa ya mtihani huo.
    Nenda kwenye <a href="<?= e($assign_url) ?>">Assignments</a> na uweke mwalimu, somo, na darasa.
  </p>
  <table class="table table-sm table-bordered mb-0 bg-white" style="font-size:.85rem">
    <thead class="table-light">
      <tr>
        <th>Jina la Mtihani</th>
        <th>Ngazi</th>
        <th>Madarasa</th>
        <th>Mwaka</th>
        <th>Hali</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($missing_exams as $mx): ?>
      <tr>
        <td class="fw-semibold"><?= e($mx['name']) ?></td>
        <td><?= $mx['category'] === 'o_level' ? 'O-Level' : 'A-Level' ?></td>
        <td class="text-muted small"><?= e($mx['level_names']) ?></td>
        <td><?= (int)$mx['year'] ?></td>
        <td><?= $status_badges[$mx['status']] ?? '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="mt-2">
    <a href="<?= e($assign_url) ?>" class="btn btn-warning btn-sm">
      Pangia Walimu Masomo &rarr;
    </a>
  </div>
</div>
<?php endif; ?>

<?php if (empty($assignments)): ?>
  <div class="text-center text-muted py-5">
    <?php if ($role === 'headmaster'): ?>
      Hakuna assignments bado. <a href="<?= e(url('school/assignments.php')) ?>">Pangia walimu masomo</a> ili uweze kuingiza alama.
    <?php else: ?>
      No subjects assigned yet. Contact the headmaster to set up your assignments.
    <?php endif; ?>
  </div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" id="assignTable">
      <thead class="table-light">
        <tr>
          <th>Exam</th>
          <th>School</th>
          <?php if ($role !== 'teacher'): ?><th>Teacher</th><?php endif; ?>
          <th>Subject</th>
          <th>Class</th>
          <th>Period</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($assignments as $a):
            $today2   = date('Y-m-d');
            $is_open  = $a['exam_status'] === 'open';
            $in_range = (!$a['marks_open_from'] || $today2 >= $a['marks_open_from'])
                     && (!$a['marks_open_to']   || $today2 <= $a['marks_open_to']);
            $can_enter = $is_open && $in_range;

            // Build the link for this slot
            $slot_link = url('teacher/marks_entry.php?ta_id=' . (int)$a['ta_id'] . '&exam_id=' . (int)$a['exam_id']);
        ?>
        <tr>
          <td class="col-asgn-exam">
            <div class="fw-semibold"><?= e($a['exam_name']) ?></div>
            <div class="text-muted small"><?= (int)$a['year'] ?></div>
          </td>
          <td class="small col-asgn-school"><?= e($a['school_name']) ?></td>
          <?php if ($role !== 'teacher'): ?>
          <td class="small col-asgn-teacher"><?= e($a['teacher_name']) ?></td>
          <?php endif; ?>
          <td class="col-asgn-subject">
            <?= e($a['subject_name']) ?>
            <?php if ($a['has_practical']): ?>
              <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">+P</span>
            <?php endif; ?>
          </td>
          <td class="col-asgn-class"><?= e($a['level_name']) ?></td>
          <td class="small col-asgn-period">
            <?php if ($a['marks_open_from'] && $a['marks_open_to']): ?>
              <?= e(date('d/m/Y', strtotime($a['marks_open_from']))) ?> –
              <?= e(date('d/m/Y', strtotime($a['marks_open_to']))) ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="col-asgn-status">
            <?php if (!$is_open): ?>
              <span class="badge bg-secondary"><?= e($a['exam_status']) ?></span>
            <?php elseif (!$in_range): ?>
              <span class="badge bg-warning text-dark">Out of window</span>
            <?php else: ?>
              <span class="badge bg-success">Open</span>
            <?php endif; ?>
          </td>
          <td class="col-asgn-action">
            <a href="<?= e($slot_link) ?>"
               class="btn btn-sm <?= $can_enter ? 'btn-primary' : 'btn-outline-secondary' ?>">
              <?= $can_enter ? 'Enter Marks' : 'View' ?>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ══ Mark entry form ══════════════════════════════════════════ -->

<div class="mb-3">
  <a href="<?= e($back_url) ?>" class="btn btn-outline-secondary btn-sm">← Back</a>
</div>

<div class="page-heading">
  <div>
    <h4>
      <?= e($selected['subject_name']) ?>
      <span class="badge bg-light text-dark border ms-1" style="font-size:.75rem"><?= e($selected['subject_code']) ?></span>
    </h4>
    <div class="text-muted small">
      <?= e($selected['exam_name']) ?> · <?= (int)$selected['year'] ?> ·
      <?= e($selected['level_name']) ?> · <?= e($selected['school_name']) ?>
    </div>
  </div>
  <div class="text-end small text-muted">
    <?php if ($selected['marks_open_to']): ?>
      Deadline: <strong><?= e(date('d/m/Y', strtotime($selected['marks_open_to']))) ?></strong>
    <?php endif; ?>
  </div>
</div>

<?php if (!$entry_open): ?>
  <div class="alert alert-danger"><?= e($entry_msg) ?></div>
<?php endif; ?>

<?php if (empty($students)): ?>
  <div class="alert alert-warning">No students found for this class in this school.</div>
<?php else: ?>

<div class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-3 align-items-center">
      <div class="col-auto">
        <span class="text-muted small">Students:</span>
        <strong class="ms-1"><?= count($students) ?></strong>
      </div>
      <div class="col-auto">
        <span class="text-muted small">Marks saved:</span>
        <strong class="ms-1"><?= count($marks_map) ?></strong>
      </div>
      <?php if ($has_p): ?>
      <div class="col-auto">
        <span class="badge bg-info text-dark">Subject has Practical</span>
        <span class="text-muted small ms-1">Theory: 0–<?= (int)$theory_max ?> | Practical: 0–<?= (int)$p_max ?></span>
      </div>
      <?php else: ?>
      <div class="col-auto">
        <span class="text-muted small">Theory: 0–100</span>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="post" id="marksForm">
  <?= csrf_field() ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle" id="marksTable">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Reg. No.</th>
            <th>Student Name</th>
            <th>G</th>
            <th style="width:110px">Theory <small class="text-muted">/<?= (int)$theory_max ?></small></th>
            <?php if ($has_p): ?>
            <th style="width:110px">Practical <small class="text-muted">/<?= (int)$p_max ?></small></th>
            <?php endif; ?>
            <th style="width:80px">Total</th>
            <th style="width:60px">Grade</th>
            <th style="width:60px">Points</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $i => $st):
              $m = $marks_map[(int)$st['id']] ?? null;
          ?>
          <tr class="<?= $has_p ? 'has-practical' : 'no-practical' ?>">
            <td class="text-muted small col-num"><?= $i + 1 ?></td>
            <td class="small col-regno"><span class="badge bg-light text-dark border"><?= e($st['admission_no']) ?></span></td>
            <td class="fw-semibold col-name"><?= e($st['full_name']) ?></td>
            <td class="small text-muted col-gender"><?= $st['sex'] ?? '—' ?></td>
            <td class="col-theory" data-label="Theory /<?= (int)$theory_max ?>">
              <input type="number" class="form-control form-control-sm mark-theory"
                     name="theory_<?= (int)$st['id'] ?>"
                     min="0" max="<?= (int)$theory_max ?>" step="0.5"
                     inputmode="decimal"
                     value="<?= $m ? e((string)$m['theory_mark']) : '' ?>"
                     <?= !$entry_open ? 'readonly' : '' ?>
                     data-student="<?= (int)$st['id'] ?>">
            </td>
            <?php if ($has_p): ?>
            <td class="col-practical" data-label="Practical /<?= (int)$p_max ?>">
              <input type="number" class="form-control form-control-sm mark-practical"
                     name="practical_<?= (int)$st['id'] ?>"
                     min="0" max="<?= (int)$p_max ?>" step="0.5"
                     inputmode="decimal"
                     value="<?= $m ? e((string)$m['practical_mark']) : '' ?>"
                     <?= !$entry_open ? 'readonly' : '' ?>
                     data-student="<?= (int)$st['id'] ?>">
            </td>
            <?php endif; ?>
            <td class="col-total">
              <span class="total-display fw-semibold text-primary" id="total_<?= (int)$st['id'] ?>">
                <?= $m ? number_format((float)$m['total_percent'], 1) : '—' ?>
              </span>
            </td>
            <td class="col-grade">
              <span class="grade-display badge" id="grade_<?= (int)$st['id'] ?>">
                <?= $m ? e($m['grade']) : '—' ?>
              </span>
            </td>
            <td class="text-muted small col-points" id="pts_<?= (int)$st['id'] ?>">
              <?= $m && $m['points'] !== null ? $m['points'] : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($entry_open): ?>
  <div class="d-flex gap-2 justify-content-end mt-3">
    <a href="<?= e($back_url) ?>" class="btn btn-outline-secondary">Close</a>
    <button type="submit" class="btn btn-primary px-4">Save Marks</button>
  </div>
  <?php endif; ?>
</form>

<?php endif; ?>
<?php endif; ?>

<style>
/* ── Mobile: Assignment list ──────────────────────── */
@media (max-width: 767.98px) {
  #assignTable { display: block; width: 100%; border: 0; }
  #assignTable thead { display: none; }
  #assignTable tbody { display: block; }
  #assignTable tbody tr {
    display: block;
    border: 1px solid #dee2e6;
    border-radius: .5rem;
    margin-bottom: .625rem;
    padding: .75rem;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
  }
  #assignTable tbody td { display: block; border: 0; padding: 1px 0; }

  #assignTable td.col-asgn-exam .fw-semibold { font-size: 1rem; }
  #assignTable td.col-asgn-status { margin: 4px 0; }
  #assignTable td.col-asgn-school,
  #assignTable td.col-asgn-teacher,
  #assignTable td.col-asgn-period  { color: #6c757d; font-size: .8rem; }
  #assignTable td.col-asgn-class   { font-size: .85rem; }
  #assignTable td.col-asgn-action  { margin-top: .5rem; }
  #assignTable td.col-asgn-action .btn { width: 100%; }
}

/* ── Mobile: Marks entry form ────────────────────── */
@media (max-width: 767.98px) {
  #marksForm .table-responsive { overflow-x: visible !important; }

  #marksTable { display: block; width: 100%; border: 0; }
  #marksTable thead { display: none; }
  #marksTable tbody { display: block; }

  /* Each student appears as a card */
  #marksTable tbody tr {
    display: grid;
    grid-template-columns: auto 1fr auto auto;
    grid-template-areas:
      "num  name   gender regno"
      "thy  thy    prac   prac"
      "tot  grade  pts    pts";
    border: 1px solid #dee2e6;
    border-radius: .5rem;
    margin-bottom: .625rem;
    padding: .75rem;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    column-gap: .5rem;
    row-gap: .375rem;
  }

  /* Subject without practical */
  #marksTable tbody tr.no-practical {
    grid-template-areas:
      "num  name   gender regno"
      "thy  thy    thy    thy"
      "tot  grade  pts    pts";
  }

  #marksTable tbody td { border: 0; padding: 0; align-self: center; }

  /* First row: number + name + gender + reg no */
  #marksTable td.col-num    { grid-area: num; font-size: .78rem; color: #6c757d; }
  #marksTable td.col-name   { grid-area: name; font-size: 1.05rem; font-weight: 600; }
  #marksTable td.col-gender { grid-area: gender; font-size: .78rem; color: #6c757d; }
  #marksTable td.col-regno  { grid-area: regno; }

  /* Second row: mark input fields */
  #marksTable td.col-theory    { grid-area: thy; align-self: end; }
  #marksTable td.col-practical { grid-area: prac; align-self: end; }

  #marksTable td.col-theory::before,
  #marksTable td.col-practical::before {
    content: attr(data-label);
    display: block;
    font-size: .7rem;
    color: #6c757d;
    font-weight: 500;
    margin-bottom: 3px;
  }

  /* Larger touch-friendly input fields */
  #marksTable td.col-theory input,
  #marksTable td.col-practical input {
    height: 46px;
    font-size: 1.15rem;
    text-align: center;
    padding: .375rem .5rem;
  }

  /* Third row: total + grade + points */
  #marksTable td.col-total  { grid-area: tot; }
  #marksTable td.col-grade  { grid-area: grade; }
  #marksTable td.col-points { grid-area: pts; font-size: .82rem; color: #6c757d; }

  #marksTable td.col-total .total-display { font-size: .95rem; }

  /* Full-width save button on mobile */
  #marksForm .d-flex.justify-content-end { flex-direction: column; }
  #marksForm .d-flex.justify-content-end .btn { width: 100%; justify-content: center; }
}
</style>

<script>
(() => {
  'use strict';

  const SCALES   = <?= $scales_json ?>;
  const CATEGORY = <?= json_encode($selected['category'] ?? 'o_level') ?>;
  const HAS_P    = <?= json_encode($has_p) ?>;
  const P_MAX    = <?= json_encode($p_max) ?>;
  const T_MAX    = <?= json_encode($theory_max) ?>;

  const GRADE_COLORS = {
    A:'bg-success', B:'bg-primary', C:'bg-info text-dark',
    D:'bg-warning text-dark', E:'bg-secondary', S:'bg-secondary', F:'bg-danger'
  };

  function calcGrade(pct) {
    const scale = SCALES[CATEGORY] ?? [];
    for (const sc of scale) {
      if (pct >= parseFloat(sc.min_mark) && pct <= parseFloat(sc.max_mark)) {
        return { grade: sc.grade, points: sc.points };
      }
    }
    return { grade: 'F', points: null };
  }

  function updateRow(studentId) {
    const tInput  = document.querySelector(`[name="theory_${studentId}"]`);
    const pInput  = document.querySelector(`[name="practical_${studentId}"]`);
    const totalEl = document.getElementById(`total_${studentId}`);
    const gradeEl = document.getElementById(`grade_${studentId}`);
    const ptsEl   = document.getElementById(`pts_${studentId}`);

    if (!tInput || !totalEl) return;

    const tVal = tInput.value.trim();
    if (tVal === '') {
      totalEl.textContent = '—'; gradeEl.textContent = '—';
      gradeEl.className = 'grade-display badge';
      if (ptsEl) ptsEl.textContent = '—';
      return;
    }

    let total = parseFloat(tVal);
    if (isNaN(total)) return;

    if (HAS_P && pInput) {
      const pVal = pInput.value.trim();
      if (pVal === '') {
        totalEl.textContent = '—'; gradeEl.textContent = '—';
        gradeEl.className = 'grade-display badge';
        if (ptsEl) ptsEl.textContent = '—';
        return;
      }
      total += parseFloat(pVal) || 0;
    }

    const { grade, points } = calcGrade(total);
    totalEl.textContent = total.toFixed(1);
    gradeEl.textContent = grade;
    gradeEl.className   = 'grade-display badge ' + (GRADE_COLORS[grade] ?? 'bg-secondary');
    if (ptsEl) ptsEl.textContent = points ?? '—';

    tInput.classList.toggle('is-invalid', parseFloat(tVal) > T_MAX || parseFloat(tVal) < 0);
    if (HAS_P && pInput) {
      pInput.classList.toggle('is-invalid', parseFloat(pInput.value) > P_MAX || parseFloat(pInput.value) < 0);
    }
  }

  document.querySelectorAll('.mark-theory, .mark-practical').forEach(input => {
    const sid = input.dataset.student;
    input.addEventListener('input', () => updateRow(sid));
    updateRow(sid);
  });

  document.querySelectorAll('.mark-theory').forEach(input => {
    input.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const sid  = input.dataset.student;
      const prac = document.querySelector(`[name="practical_${sid}"]`);
      if (prac) { prac.focus(); return; }
      const all = [...document.querySelectorAll('.mark-theory')];
      const idx = all.indexOf(input);
      if (all[idx + 1]) all[idx + 1].focus();
    });
  });

  document.querySelectorAll('.mark-practical').forEach(input => {
    input.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const sid = input.dataset.student;
      const all = [...document.querySelectorAll('.mark-theory')];
      const cur = document.querySelector(`[name="theory_${sid}"]`);
      const idx = all.indexOf(cur);
      if (all[idx + 1]) all[idx + 1].focus();
    });
  });
})();
</script>

<?php
render_footer();
