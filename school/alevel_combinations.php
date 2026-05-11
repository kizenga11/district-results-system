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

// Toggle activation for this school
if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $combo_id = (int)($_POST['combo_id'] ?? 0);

    if ($action === 'toggle_combo' && $combo_id > 0) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $combo = $pdo->prepare('SELECT id, code, name FROM alevel_combinations WHERE id=:id AND status="active" LIMIT 1');
            $combo->execute([':id' => $combo_id]);
            $combo_row = $combo->fetch();
            if (!$combo_row) {
                flash_set('error', 'Combination not found (or inactive).');
                $pdo->rollBack();
                redirect('school/alevel_combinations.php');
            }

            $st = $pdo->prepare('SELECT status FROM school_alevel_combinations WHERE school_id=:s AND combination_id=:c LIMIT 1');
            $st->execute([':s' => $school_id, ':c' => $combo_id]);
            $cur = $st->fetch();
            $cur_status = $cur ? (string)$cur['status'] : 'inactive';
            $to_status = ($cur_status === 'active') ? 'inactive' : 'active';

            // Block deactivation if in use
            if ($to_status === 'inactive') {
                // Any students currently assigned this combo?
                $in_use = $pdo->prepare(
                    'SELECT 1
                     FROM student_combinations sc
                     JOIN students st ON st.id = sc.student_id
                     WHERE st.school_id = :s AND sc.combination_id = :c
                     LIMIT 1'
                );
                $in_use->execute([':s' => $school_id, ':c' => $combo_id]);
                if ($in_use->fetch()) {
                    flash_set('error', 'Cannot deactivate: this combination is already assigned to students.');
                    $pdo->rollBack();
                    redirect('school/alevel_combinations.php');
                }

                // Any teacher assignments for subjects in this combo (Form 5/6)?
                $in_use = $pdo->prepare(
                    'SELECT 1
                     FROM teacher_assignments ta
                     JOIN alevel_combination_subjects cs ON cs.subject_id = ta.subject_id AND cs.combination_id = :c
                     WHERE ta.school_id = :s AND ta.level_id IN (5,6)
                     LIMIT 1'
                );
                $in_use->execute([':s' => $school_id, ':c' => $combo_id]);
                if ($in_use->fetch()) {
                    flash_set('error', 'Cannot deactivate: one or more teachers are assigned to subjects in this combination (Form 5/6).');
                    $pdo->rollBack();
                    redirect('school/alevel_combinations.php');
                }

                // Any marks exist for subjects in this combo for this school?
                $in_use = $pdo->prepare(
                    'SELECT 1
                     FROM marks m
                     JOIN students st ON st.id = m.student_id
                     JOIN alevel_combination_subjects cs ON cs.subject_id = m.subject_id AND cs.combination_id = :c
                     WHERE st.school_id = :s
                     LIMIT 1'
                );
                $in_use->execute([':s' => $school_id, ':c' => $combo_id]);
                if ($in_use->fetch()) {
                    flash_set('error', 'Cannot deactivate: there are saved marks for subjects in this combination.');
                    $pdo->rollBack();
                    redirect('school/alevel_combinations.php');
                }
            }

            // Upsert school_alevel_combinations
            $pdo->prepare(
                'INSERT INTO school_alevel_combinations (school_id, combination_id, status)
                 VALUES (:s,:c,:st)
                 ON DUPLICATE KEY UPDATE status=VALUES(status)'
            )->execute([':s' => $school_id, ':c' => $combo_id, ':st' => $to_status]);

            if ($to_status === 'active') {
                // Add subjects for this combo to school_subjects
                $sub_ids = $pdo->prepare('SELECT subject_id FROM alevel_combination_subjects WHERE combination_id=:c');
                $sub_ids->execute([':c' => $combo_id]);
                $rows = $sub_ids->fetchAll();
                if ($rows) {
                    $ins = $pdo->prepare('INSERT IGNORE INTO school_subjects (school_id, subject_id) VALUES (:s,:sub)');
                    foreach ($rows as $r) {
                        $ins->execute([':s' => $school_id, ':sub' => (int)$r['subject_id']]);
                    }
                }
                $pdo->commit();
                flash_set('success', 'Combination activated for this school.');
                redirect('school/alevel_combinations.php');
            }

            // Deactivate: remove combo subjects that are not needed by any other active combo in this school
            $sub_ids = $pdo->prepare('SELECT subject_id FROM alevel_combination_subjects WHERE combination_id=:c');
            $sub_ids->execute([':c' => $combo_id]);
            $candidate = array_map('intval', array_column($sub_ids->fetchAll(), 'subject_id'));
            if ($candidate) {
                $cands = implode(',', $candidate);
                $needed_stmt = $pdo->prepare(
                    "SELECT DISTINCT cs.subject_id
                     FROM school_alevel_combinations sac
                     JOIN alevel_combination_subjects cs ON cs.combination_id = sac.combination_id
                     WHERE sac.school_id = :s AND sac.status = 'active' AND sac.combination_id <> :c
                       AND cs.subject_id IN ({$cands})"
                );
                $needed_stmt->execute([':s' => $school_id, ':c' => $combo_id]);
                $needed = array_fill_keys(array_map('intval', array_column($needed_stmt->fetchAll(), 'subject_id')), true);

                $del = $pdo->prepare('DELETE FROM school_subjects WHERE school_id=:s AND subject_id=:sub');
                foreach ($candidate as $sid) {
                    if (!isset($needed[$sid])) {
                        $del->execute([':s' => $school_id, ':sub' => $sid]);
                    }
                }
            }

            $pdo->commit();
            flash_set('success', 'Combination deactivated for this school.');
            redirect('school/alevel_combinations.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    redirect('school/alevel_combinations.php');
}

// Fetch all global combos + this school's activation
$combos = db()->query(
    'SELECT c.id, c.code, c.name, c.status,
            (SELECT COUNT(*) FROM alevel_combination_subjects cs WHERE cs.combination_id=c.id) AS subject_count,
            COALESCE(sac.status, "inactive") AS school_status
     FROM alevel_combinations c
     LEFT JOIN school_alevel_combinations sac
       ON sac.combination_id = c.id AND sac.school_id = ' . (int)$school_id . '
     ORDER BY c.code'
)->fetchAll();

render_header('A-Level Combinations');
?>

<div class="page-heading">
  <h4>A-Level Combinations <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?= count($combos) ?></span></h4>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('school/subjects.php')) ?>">Back</a>
</div>

<div class="alert alert-info py-2 small">
  Activate the combinations taught in your school. Activating a combination automatically activates its subjects.
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Code</th>
          <th>Name</th>
          <th class="text-center">Subjects</th>
          <th class="text-center">District Status</th>
          <th class="text-center">My School</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($combos as $i => $c): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold"><span class="badge bg-light text-dark border"><?= e($c['code']) ?></span></td>
            <td><?= e($c['name'] ?? '—') ?></td>
            <td class="text-center"><span class="badge bg-secondary"><?= (int)$c['subject_count'] ?></span></td>
            <td class="text-center">
              <?= $c['status'] === 'active'
                ? '<span class="badge bg-success">Active</span>'
                : '<span class="badge bg-danger">Inactive</span>' ?>
            </td>
            <td class="text-center">
              <?= $c['school_status'] === 'active'
                ? '<span class="badge bg-success">Activated</span>'
                : '<span class="badge bg-secondary">Not active</span>' ?>
            </td>
            <td class="text-end">
              <?php if ($c['status'] !== 'active'): ?>
                <span class="text-muted small">—</span>
              <?php else: ?>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_combo">
                  <input type="hidden" name="combo_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm <?= $c['school_status'] === 'active' ? 'btn-outline-danger' : 'btn-primary' ?>" type="submit"
                          onclick="return confirm('<?= $c['school_status'] === 'active' ? 'Deactivate' : 'Activate' ?> this combination for your school?')">
                    <?= $c['school_status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($combos)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No combinations yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
render_footer();
