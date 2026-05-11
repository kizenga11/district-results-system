<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

require_auth();

$user = current_user();
$role = $user['role'];

// ── Stats per role ─────────────────────────────────────────────
$stats = [];

if (in_array($role, ['super_admin', 'district_admin'], true)) {
    $stats['schools']  = (int)db()->query('SELECT COUNT(*) FROM schools  WHERE status="active"')->fetchColumn();
    $stats['users']    = (int)db()->query('SELECT COUNT(*) FROM users    WHERE status="active"')->fetchColumn();
    $stats['subjects'] = (int)db()->query('SELECT COUNT(*) FROM subjects WHERE status="active"')->fetchColumn();
    $stats['exams']    = (int)db()->query('SELECT COUNT(*) FROM exams')->fetchColumn();
    $stats['open_exams']= (int)db()->query('SELECT COUNT(*) FROM exams WHERE status="open"')->fetchColumn();
    $stats['students'] = (int)db()->query('SELECT COUNT(*) FROM students WHERE status="active"')->fetchColumn();
}

if ($role === 'headmaster') {
    $sid = (int)$user['school_id'];
    $s = db()->prepare('SELECT COUNT(*) FROM students WHERE school_id=:s AND status="active"');
    $s->execute([':s' => $sid]);
    $stats['students'] = (int)$s->fetchColumn();

    $s = db()->prepare('SELECT COUNT(*) FROM teacher_assignments WHERE school_id=:s');
    $s->execute([':s' => $sid]);
    $stats['assignments'] = (int)$s->fetchColumn();

    // Exams available to this school based on permanent assignments
    $s = db()->prepare(
        'SELECT COUNT(DISTINCT e.id)
         FROM teacher_assignments ta
         JOIN subjects sub ON sub.id = ta.subject_id
         JOIN exams e ON e.category = sub.category
         JOIN exam_levels el ON el.exam_id = e.id AND el.level_id = ta.level_id
         WHERE ta.school_id = :s'
    );
    $s->execute([':s' => $sid]);
    $stats['exams'] = (int)$s->fetchColumn();
}

if ($role === 'teacher') {
    $tid = (int)$user['id'];
    $s = db()->prepare('SELECT COUNT(*) FROM teacher_assignments WHERE teacher_id=:t');
    $s->execute([':t' => $tid]);
    $stats['assignments'] = (int)$s->fetchColumn();

    $s = db()->prepare('SELECT COUNT(*) FROM marks WHERE created_by=:t');
    $s->execute([':t' => $tid]);
    $stats['marks'] = (int)$s->fetchColumn();

    $s = db()->prepare(
        'SELECT COUNT(DISTINCT e.id)
         FROM teacher_assignments ta
         JOIN subjects sub ON sub.id = ta.subject_id
         JOIN exams e ON e.category = sub.category
         JOIN exam_levels el ON el.exam_id = e.id AND el.level_id = ta.level_id
         WHERE ta.teacher_id = :t'
    );
    $s->execute([':t' => $tid]);
    $stats['exams'] = (int)$s->fetchColumn();
}

// ── Recent exams (open) ────────────────────────────────────────
$open_exams = [];
if (in_array($role, ['super_admin', 'district_admin'], true)) {
    $open_exams = db()->query(
        'SELECT id, name, year, category, marks_open_to FROM exams
         WHERE status="open" ORDER BY marks_open_to ASC LIMIT 5'
    )->fetchAll();
}

render_header('Dashboard');
?>

<div class="page-heading">
  <h4>Welcome, <?= e($user['full_name']) ?></h4>
  <span class="badge bg-primary" style="font-size:.78rem;padding:.4em .8em"><?= e(str_replace('_',' ', $role)) ?></span>
</div>

<!-- ── Stat cards ──────────────────────────────────────────── -->
<div class="row g-3 mb-4">

<?php if (in_array($role, ['super_admin', 'district_admin'], true)): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon blue"><?= icon('school') ?></div>
      <div>
        <div class="stat-value"><?= $stats['schools'] ?></div>
        <div class="stat-label">Schools</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon green"><?= icon('users') ?></div>
      <div>
        <div class="stat-value"><?= $stats['users'] ?></div>
        <div class="stat-label">Users</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon purple"><?= icon('subjects') ?></div>
      <div>
        <div class="stat-value"><?= $stats['subjects'] ?></div>
        <div class="stat-label">Subjects</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon orange"><?= icon('exam') ?></div>
      <div>
        <div class="stat-value"><?= $stats['exams'] ?></div>
        <div class="stat-label">Exams</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon teal"><?= icon('exam') ?></div>
      <div>
        <div class="stat-value"><?= $stats['open_exams'] ?></div>
        <div class="stat-label">Open Exams</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon green"><?= icon('students') ?></div>
      <div>
        <div class="stat-value"><?= $stats['students'] ?></div>
        <div class="stat-label">Students</div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($role === 'headmaster'): ?>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="stat-icon blue"><?= icon('students') ?></div>
      <div>
        <div class="stat-value"><?= $stats['students'] ?></div>
        <div class="stat-label">Students</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="stat-icon orange"><?= icon('exam') ?></div>
      <div>
        <div class="stat-value"><?= $stats['exams'] ?></div>
        <div class="stat-label">Exams</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="stat-icon green"><?= icon('assign') ?></div>
      <div>
        <div class="stat-value"><?= $stats['assignments'] ?></div>
        <div class="stat-label">Assignments</div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($role === 'teacher'): ?>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="stat-icon blue"><?= icon('exam') ?></div>
      <div>
        <div class="stat-value"><?= $stats['exams'] ?></div>
        <div class="stat-label">Exams</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="stat-icon orange"><?= icon('assign') ?></div>
      <div>
        <div class="stat-value"><?= $stats['assignments'] ?></div>
        <div class="stat-label">Assignments</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="stat-icon green"><?= icon('marks') ?></div>
      <div>
        <div class="stat-value"><?= $stats['marks'] ?></div>
        <div class="stat-label">Marks Entered</div>
      </div>
    </div>
  </div>
<?php endif; ?>

</div>

<!-- ── Open exams table ────────────────────────────────────── -->
<?php if (!empty($open_exams)): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><?= icon('exam') ?> &nbsp;Open Exams</span>
    <a href="<?= e(url('district/exams.php')) ?>" class="btn btn-outline-primary btn-sm">View All</a>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead class="table-light">
        <tr>
          <th>Exam Name</th>
          <th>Level</th>
          <th>Year</th>
          <th>Marks Deadline</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($open_exams as $ex): ?>
        <tr>
          <td class="fw-semibold"><?= e($ex['name']) ?></td>
          <td><?= $ex['category'] === 'o_level' ? 'O-Level' : 'A-Level' ?></td>
          <td><?= (int)$ex['year'] ?></td>
          <td>
            <?php if ($ex['marks_open_to']): ?>
              <?php
                $days = (int)ceil((strtotime($ex['marks_open_to']) - time()) / 86400);
                $cls  = $days <= 3 ? 'text-danger fw-semibold' : ($days <= 7 ? 'text-warning' : '');
              ?>
              <span class="<?= $cls ?>"><?= e(date('d/m/Y', strtotime($ex['marks_open_to']))) ?></span>
              <?php if ($days >= 0): ?>
                <small class="text-muted ms-1">(<?= $days ?> days left)</small>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-success">Open</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
render_footer();
