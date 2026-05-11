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

if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_activation') {
        $selected = array_map('intval', (array)($_POST['subject_ids'] ?? []));
        $selected = array_values(array_unique(array_filter($selected)));

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM school_subjects WHERE school_id = :sid')->execute([':sid' => $school_id]);
            if (!empty($selected)) {
                $ins = $pdo->prepare('INSERT INTO school_subjects (school_id, subject_id) VALUES (:sid, :sub)');
                foreach ($selected as $subId) {
                    $ins->execute([':sid' => $school_id, ':sub' => $subId]);
                }
            }
            $pdo->commit();
            flash_set('success', 'Subjects activation saved.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Subjects activation failed: ' . $e->getMessage());
            flash_set('error', 'Failed to save subjects activation. Please try again.');
            redirect('school/subjects.php');
        }

        redirect('school/subjects.php');
    }
}

// Fetch district subjects
$subjects = db()->query(
    'SELECT id, category, name, code, has_practical, practical_max, is_principal, alevel_subject_type, status
     FROM subjects
     WHERE status = "active"
     ORDER BY category, name'
)->fetchAll();

// Active subjects for this school
$stmt = db()->prepare('SELECT subject_id FROM school_subjects WHERE school_id = :sid');
$stmt->execute([':sid' => $school_id]);
$active_ids = array_map('intval', array_column($stmt->fetchAll(), 'subject_id'));
$active_set = array_fill_keys($active_ids, true);

function subject_type_label(array $s): string
{
    if (($s['category'] ?? '') !== 'a_level') return '';
    $t = (string)($s['alevel_subject_type'] ?? '');
    if ($t === '') {
        $t = ((int)($s['is_principal'] ?? 0) === 1) ? 'principal' : 'subsidiary';
    }
    return ucfirst($t);
}

render_header('Subjects');
?>

<div class="page-heading">
  <h4>Subjects</h4>
  <a class="btn btn-outline-primary btn-sm" href="<?= e(url('school/alevel_combinations.php')) ?>">A-Level Combinations</a>
</div>

<div class="alert alert-info">
  Activate <strong>O-Level</strong> subjects taught in your school from the district list.
  For <strong>A-Level</strong>, activate combinations on the <a href="<?= e(url('school/alevel_combinations.php')) ?>">Combinations</a> page.
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_activation">

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">O-Level Subjects</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:40px"></th>
            <th>Subject</th>
            <th>Code</th>
            <th class="text-center">Practical</th>
            <th class="text-center">Prac Max</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subjects as $s): if ($s['category'] !== 'o_level') continue; ?>
            <tr>
              <td>
                <input class="form-check-input" type="checkbox" name="subject_ids[]" value="<?= (int)$s['id'] ?>" <?= isset($active_set[(int)$s['id']]) ? 'checked' : '' ?>>
              </td>
              <td class="fw-semibold"><?= e($s['name']) ?></td>
              <td><span class="badge bg-light text-dark border"><?= e($s['code']) ?></span></td>
              <td class="text-center"><?= $s['has_practical'] ? '<span class="badge bg-info text-dark">Yes</span>' : '<span class="text-muted">—</span>' ?></td>
              <td class="text-center"><?= $s['has_practical'] ? (int)$s['practical_max'] : '<span class="text-muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">A-Level</div>
    <div class="card-body">
      <div class="text-muted">
        A-Level subjects are activated automatically when you activate an A-Level combination.
      </div>
      <div class="mt-2">
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('school/alevel_combinations.php')) ?>">Manage A-Level Combinations</a>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary" type="submit">Save Activation</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('dashboard.php')) ?>">Back</a>
  </div>
</form>

<?php
render_footer();
