<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['teacher']);

$user    = current_user();
$role    = $user['role'];
$user_id = (int)$user['id'];

const PASS_THRESHOLD = 50;

function get_assignments(int $user_id, string $role): array
{
    $ta_query =
        'SELECT ta.id AS ta_id,
                ta.school_id, ta.teacher_id, ta.subject_id, ta.level_id,
                sub.name AS subject_name, sub.code AS subject_code, sub.category,
                lv.name AS level_name,
                sc.name AS school_name,
                u.full_name AS teacher_name
         FROM teacher_assignments ta
         JOIN subjects    sub ON sub.id       = ta.subject_id
         JOIN levels      lv  ON lv.id        = ta.level_id
         JOIN schools     sc  ON sc.id        = ta.school_id
         JOIN users       u   ON u.id         = ta.teacher_id';

    if ($role === 'headmaster') {
        $school_id = (int)(current_user()['school_id'] ?? 0);
        $stmt = db()->prepare($ta_query . ' WHERE ta.school_id = :sid ORDER BY u.full_name, lv.id, sub.name');
        $stmt->execute([':sid' => $school_id]);
        return $stmt->fetchAll();
    }

    $stmt = db()->prepare($ta_query . ' WHERE ta.teacher_id = :tid ORDER BY lv.id, sub.name');
    $stmt->execute([':tid' => $user_id]);
    return $stmt->fetchAll();
}

function get_topic_status_badge(string $status): string
{
    return match ($status) {
        'planned' => '<span class="badge bg-secondary">Planned</span>',
        'in_progress' => '<span class="badge bg-primary">In Progress</span>',
        'completed' => '<span class="badge bg-success">Completed</span>',
        default => '<span class="badge bg-light text-dark">' . e($status) . '</span>',
    };
}

// ── Resolve selected assignment ──────────────────────────────────
$ta_id = (int)($_GET['ta_id'] ?? 0);
$topic_id = (int)($_GET['topic_id'] ?? 0);
$assignments = get_assignments($user_id, $role);
$selected_ta = null;
if ($ta_id) {
    foreach ($assignments as $a) {
        if ((int)$a['ta_id'] === $ta_id) {
            $selected_ta = $a;
            break;
        }
    }
}

// ── POST actions ─────────────────────────────────────────────────
if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $pdo = db();

    try {
        if ($action === 'add_topic' && $selected_ta) {
            $title = trim((string)($_POST['title'] ?? ''));
            $competence = trim((string)($_POST['competence'] ?? ''));
            if ($title === '') {
                throw new \RuntimeException('Topic title is required.');
            }
            $max_sort = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM teacher_topics WHERE teacher_assignment_id=:aid');
            $max_sort->execute([':aid' => $ta_id]);
            $next_order = (int)$max_sort->fetchColumn();

            $pdo->prepare(
                'INSERT INTO teacher_topics (teacher_assignment_id, title, competence, sort_order) VALUES (:aid, :t, :c, :so)'
            )->execute([':aid' => $ta_id, ':t' => $title, ':c' => $competence, ':so' => $next_order]);

            flash_set('success', 'Topic added.');
            redirect('teacher/teaching_progress.php?ta_id=' . $ta_id);
        }

        if ($action === 'edit_topic' && $selected_ta) {
            $tid = (int)($_POST['topic_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $competence = trim((string)($_POST['competence'] ?? ''));
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            if ($title === '') {
                throw new \RuntimeException('Topic title is required.');
            }
            $pdo->prepare(
                'UPDATE teacher_topics SET title=:t, competence=:c, sort_order=:so WHERE id=:id AND teacher_assignment_id=:aid'
            )->execute([':t' => $title, ':c' => $competence, ':so' => $sort_order, ':id' => $tid, ':aid' => $ta_id]);
            flash_set('success', 'Topic updated.');
            redirect('teacher/teaching_progress.php?ta_id=' . $ta_id . '&topic_id=' . $tid);
        }

        if ($action === 'delete_topic' && $selected_ta) {
            $tid = (int)($_POST['topic_id'] ?? 0);
            $pdo->prepare('DELETE FROM teacher_topics WHERE id=:id AND teacher_assignment_id=:aid')
                ->execute([':id' => $tid, ':aid' => $ta_id]);
            flash_set('success', 'Topic deleted.');
            redirect('teacher/teaching_progress.php?ta_id=' . $ta_id);
        }

        if ($action === 'start_topic' && $selected_ta) {
            $tid = (int)($_POST['topic_id'] ?? 0);

            // Verify no other topic is in_progress for this assignment
            $chk = $pdo->prepare('SELECT id FROM teacher_topics WHERE teacher_assignment_id=:aid AND status="in_progress" AND id!=:tid LIMIT 1');
            $chk->execute([':aid' => $ta_id, ':tid' => $tid]);
            if ($chk->fetch()) {
                throw new \RuntimeException('Another topic is already In Progress. Complete it first.');
            }

            // Verify all previous topics (by sort_order) have approved tests with pass_rate > 75%
            $prev = $pdo->prepare(
                'SELECT t.id FROM teacher_topics t
                 WHERE t.teacher_assignment_id=:aid
                 AND t.sort_order < (SELECT sort_order FROM teacher_topics WHERE id=:tid)
                 AND NOT EXISTS (
                     SELECT 1 FROM topic_tests tt
                     WHERE tt.teacher_topic_id = t.id
                     AND tt.status = "approved"
                     AND tt.pass_rate > 75
                 )
                 ORDER BY t.sort_order DESC LIMIT 1'
            );
            $prev->execute([':aid' => $ta_id, ':tid' => $tid]);
            if ($prev->fetch()) {
                throw new \RuntimeException('Previous topic must be approved with pass rate > 75%. Please wait for headmaster approval.');
            }

            $pdo->prepare('UPDATE teacher_topics SET status="in_progress", updated_at=NOW() WHERE id=:id AND teacher_assignment_id=:aid')
                ->execute([':id' => $tid, ':aid' => $ta_id]);
            flash_set('success', 'Topic started. Log your daily progress below.');
            redirect('teacher/teaching_progress.php?ta_id=' . $ta_id . '&topic_id=' . $tid);
        }

        if ($action === 'add_log' && $selected_ta) {
            $tid = (int)($_POST['topic_id'] ?? 0);
            $log_date = (string)($_POST['log_date'] ?? date('Y-m-d'));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($notes === '') {
                throw new \RuntimeException('Notes are required for the daily log.');
            }
            $pdo->prepare(
                'INSERT INTO teaching_progress_log (teacher_topic_id, log_date, notes) VALUES (:tid, :d, :n)'
            )->execute([':tid' => $tid, ':d' => $log_date, ':n' => $notes]);
            flash_set('success', 'Daily progress logged.');
            redirect('teacher/teaching_progress.php?ta_id=' . $ta_id . '&topic_id=' . $tid);
        }

        if ($action === 'complete_topic' && $selected_ta) {
            $tid = (int)($_POST['topic_id'] ?? 0);
            $pdo->prepare('UPDATE teacher_topics SET status="completed", updated_at=NOW() WHERE id=:id AND teacher_assignment_id=:aid')
                ->execute([':id' => $tid, ':aid' => $ta_id]);
            flash_set('success', 'Topic marked as completed. Enter the test scores below.');
            redirect('teacher/teaching_progress.php?ta_id=' . $ta_id . '&topic_id=' . $tid);
        }

        if ($action === 'reteach_topic' && $selected_ta) {
            $tid = (int)($_POST['topic_id'] ?? 0);
            // Only allow reteach if topic has an approved test (it was fully completed before)
            $chk = $pdo->prepare(
                'SELECT 1 FROM topic_tests WHERE teacher_topic_id=:tid AND status="approved" LIMIT 1'
            );
            $chk->execute([':tid' => $tid]);
            if (!$chk->fetch()) {
                throw new \RuntimeException('This topic has no approved test to reteach from.');
            }
            $pdo->prepare('UPDATE teacher_topics SET status="in_progress", updated_at=NOW() WHERE id=:id AND teacher_assignment_id=:aid')
                ->execute([':id' => $tid, ':aid' => $ta_id]);
            flash_set('success', 'Topic set back to In Progress for reteaching.');
            redirect('teacher/teaching_progress.php?ta_id=' . $ta_id . '&topic_id=' . $tid);
        }

        if ($action === 'save_test_draft' && $selected_ta) {
            $tid = (int)($_POST['topic_id'] ?? 0);
            $test_date = (string)($_POST['test_date'] ?? date('Y-m-d'));

            // Upsert test record (reuse only draft, create new for rejected/none)
            $test = $pdo->prepare('SELECT id, attempt_no FROM topic_tests WHERE teacher_topic_id=:tid AND status="draft" ORDER BY attempt_no DESC LIMIT 1');
            $test->execute([':tid' => $tid]);
            $test_row = $test->fetch();

            if ($test_row) {
                $test_id = (int)$test_row['id'];
                $attempt_no = (int)$test_row['attempt_no'];
            } else {
                $last_attempt = $pdo->prepare('SELECT COALESCE(MAX(attempt_no),0)+1 FROM topic_tests WHERE teacher_topic_id=:tid');
                $last_attempt->execute([':tid' => $tid]);
                $attempt_no = (int)$last_attempt->fetchColumn();
                $pdo->prepare(
                    'INSERT INTO topic_tests (teacher_topic_id, attempt_no, test_date, status) VALUES (:tid, :at, :td, "draft")'
                )->execute([':tid' => $tid, ':at' => $attempt_no, ':td' => $test_date]);
                $test_id = (int)$pdo->lastInsertId();
            }

            // Save student scores
            $student_ids = array_keys(array_filter(
                $_POST,
                fn($k) => str_starts_with($k, 'score_'),
                ARRAY_FILTER_USE_KEY
            ));

            $present_count = 0;
            $pass_count = 0;
            foreach ($student_ids as $key) {
                $sid = (int)str_replace('score_', '', $key);
                $score_val = trim((string)($_POST['score_' . $sid] ?? ''));
                $absent = isset($_POST['absent_' . $sid]) ? 1 : 0;

                if ($absent) {
                    $score = null;
                } elseif ($score_val !== '') {
                    $score = (float)$score_val;
                    $present_count++;
                    if ($score >= PASS_THRESHOLD) {
                        $pass_count++;
                    }
                } else {
                    continue; // skip empty
                }

                $pdo->prepare(
                    'INSERT INTO topic_test_students (topic_test_id, student_id, score, absent)
                     VALUES (:ttid, :sid, :sc, :ab)
                     ON DUPLICATE KEY UPDATE score=VALUES(score), absent=VALUES(absent)'
                )->execute([':ttid' => $test_id, ':sid' => $sid, ':sc' => $score, ':ab' => $absent]);
            }

            // Calculate pass rate (based on ALL students, not just present)
            $total_students = count($student_ids);
            $pass_rate = $total_students > 0 ? round($pass_count / $total_students * 100, 2) : 0;
            $pdo->prepare('UPDATE topic_tests SET pass_rate=:pr WHERE id=:id')
                ->execute([':pr' => $pass_rate, ':id' => $test_id]);

            flash_set('success', 'Test draft saved. Pass rate: ' . $pass_rate . '%');
            redirect('teacher/teaching_progress.php?ta_id=' . $ta_id . '&topic_id=' . $tid);
        }

        if ($action === 'submit_test' && $selected_ta) {
            $tid = (int)($_POST['topic_id'] ?? 0);
            $test_date = (string)($_POST['test_date'] ?? date('Y-m-d'));

            // Find or create draft test
            $test = $pdo->prepare('SELECT id, attempt_no FROM topic_tests WHERE teacher_topic_id=:tid AND status="draft" ORDER BY attempt_no DESC LIMIT 1');
            $test->execute([':tid' => $tid]);
            $test_row = $test->fetch();

            if ($test_row) {
                $test_id = (int)$test_row['id'];
                $attempt_no = (int)$test_row['attempt_no'];
            } else {
                $last_attempt = $pdo->prepare('SELECT COALESCE(MAX(attempt_no),0)+1 FROM topic_tests WHERE teacher_topic_id=:tid');
                $last_attempt->execute([':tid' => $tid]);
                $attempt_no = (int)$last_attempt->fetchColumn();
                $pdo->prepare(
                    'INSERT INTO topic_tests (teacher_topic_id, attempt_no, test_date, status) VALUES (:tid, :at, :td, "draft")'
                )->execute([':tid' => $tid, ':at' => $attempt_no, ':td' => $test_date]);
                $test_id = (int)$pdo->lastInsertId();
            }

            // Save student scores
            $student_ids = array_keys(array_filter(
                $_POST, fn($k) => str_starts_with($k, 'score_'), ARRAY_FILTER_USE_KEY
            ));

            $present_count = 0;
            $pass_count = 0;
            foreach ($student_ids as $key) {
                $sid = (int)str_replace('score_', '', $key);
                $score_val = trim((string)($_POST['score_' . $sid] ?? ''));
                $absent = isset($_POST['absent_' . $sid]) ? 1 : 0;

                if ($absent) {
                    $score = null;
                } elseif ($score_val !== '') {
                    $score = (float)$score_val;
                    $present_count++;
                    if ($score >= PASS_THRESHOLD) { $pass_count++; }
                } else {
                    continue;
                }

                $pdo->prepare(
                    'INSERT INTO topic_test_students (topic_test_id, student_id, score, absent)
                     VALUES (:ttid, :sid, :sc, :ab)
                     ON DUPLICATE KEY UPDATE score=VALUES(score), absent=VALUES(absent)'
                )->execute([':ttid' => $test_id, ':sid' => $sid, ':sc' => $score, ':ab' => $absent]);
            }

            $total_students = count($student_ids);
            $pass_rate = $total_students > 0 ? round($pass_count / $total_students * 100, 2) : 0;

            if ($pass_rate <= 75) {
                $pdo->prepare('UPDATE topic_tests SET pass_rate=:pr WHERE id=:id')
                    ->execute([':pr' => $pass_rate, ':id' => $test_id]);
                throw new \RuntimeException('Pass rate must be above 75% to submit. Current: ' . $pass_rate . '%');
            }

            $pdo->prepare(
                'UPDATE topic_tests SET pass_rate=:pr, status="pending", submitted_by=:uid, submitted_at=NOW() WHERE id=:id'
            )->execute([':pr' => $pass_rate, ':id' => $test_id, ':uid' => $user_id]);

            // Notify headmaster that a test has been submitted
            $tp_info = $pdo->prepare('SELECT title FROM teacher_topics WHERE id=:tid LIMIT 1');
            $tp_info->execute([':tid' => $tid]);
            $tp_title = (string)($tp_info->fetchColumn() ?: 'Topic');

            $hm_q = $pdo->prepare('SELECT id FROM users WHERE school_id=:sid AND role="headmaster" AND status="active" LIMIT 1');
            $hm_q->execute([':sid' => $selected_ta['school_id']]);
            $hm_row = $hm_q->fetch();
            if ($hm_row) {
                notify_send(
                    (int)$hm_row['id'],
                    'topic_test_submitted',
                    'Topic Test Submitted',
                    'Teacher ' . $user['full_name'] . ' has submitted a test for topic "' . $tp_title . '" (' . $selected_ta['subject_name'] . ' · ' . $selected_ta['level_name'] . '). Pass rate: ' . $pass_rate . '%. Please approve.',
                    $test_id,
                    'topic_test'
                );
            }

            flash_set('success', 'Test submitted for headmaster approval. Pass rate: ' . $pass_rate . '%');
            redirect('teacher/teaching_progress.php?ta_id=' . $ta_id . '&topic_id=' . $tid);
        }
    } catch (\RuntimeException $ex) {
        flash_set('error', $ex->getMessage());
        redirect('teacher/teaching_progress.php?ta_id=' . $ta_id . '&topic_id=' . $topic_id);
    }
}

// ── Load topics ──────────────────────────────────────────────────
$topics = [];
if ($selected_ta) {
    $t_stmt = db()->prepare(
        'SELECT * FROM teacher_topics WHERE teacher_assignment_id=:aid ORDER BY sort_order'
    );
    $t_stmt->execute([':aid' => $ta_id]);
    $topics = $t_stmt->fetchAll();
}

// ── Load current topic ───────────────────────────────────────────
$current_topic = null;
$daily_logs = [];
$test = null;
$test_students = [];
if ($selected_ta && $topic_id) {
    foreach ($topics as $t) {
        if ((int)$t['id'] === $topic_id) {
            $current_topic = $t;
            break;
        }
    }
    if ($current_topic) {
        $log_stmt = db()->prepare('SELECT * FROM teaching_progress_log WHERE teacher_topic_id=:tid ORDER BY log_date DESC, created_at DESC');
        $log_stmt->execute([':tid' => $topic_id]);
        $daily_logs = $log_stmt->fetchAll();

        // Find the latest test (draft, pending, or rejected for re-submit)
        $test_stmt = db()->prepare('SELECT * FROM topic_tests WHERE teacher_topic_id=:tid ORDER BY attempt_no DESC LIMIT 1');
        $test_stmt->execute([':tid' => $topic_id]);
        $test = $test_stmt->fetch();

        if ($test) {
            $ss = db()->prepare(
                'SELECT tts.*, st.full_name, st.admission_no
                 FROM topic_test_students tts
                 JOIN students st ON st.id = tts.student_id
                 WHERE tts.topic_test_id=:ttid
                 ORDER BY st.full_name'
            );
            $ss->execute([':ttid' => $test['id']]);
            $test_students = $ss->fetchAll();
        }
    }
}

// ── Load all students for this assignment (for test entry) ───────
$all_students = [];
if ($selected_ta && $current_topic && $current_topic['status'] === 'completed') {
    $cat = $selected_ta['category'];
    if ($cat === 'o_level') {
        $st = db()->prepare(
            'SELECT st.id, st.full_name, st.admission_no, st.sex
             FROM students st
             JOIN student_subjects ss ON ss.student_id = st.id AND ss.subject_id = :sub
             WHERE st.school_id=:s AND st.level_id=:l AND st.status="active"
             ORDER BY st.full_name'
        );
        $st->execute([':s' => $selected_ta['school_id'], ':l' => $selected_ta['level_id'], ':sub' => $selected_ta['subject_id']]);
    } else {
        $st = db()->prepare(
            'SELECT st.id, st.full_name, st.admission_no, st.sex
             FROM students st
             JOIN student_combinations sc ON sc.student_id = st.id
             JOIN alevel_combination_subjects cs ON cs.combination_id = sc.combination_id AND cs.subject_id = :sub
             WHERE st.school_id=:s AND st.level_id=:l AND st.status="active"
             ORDER BY st.full_name'
        );
        $st->execute([':s' => $selected_ta['school_id'], ':l' => $selected_ta['level_id'], ':sub' => $selected_ta['subject_id']]);
    }
    $all_students = $st->fetchAll();
}

// ── Render ───────────────────────────────────────────────────────
render_header('Teaching Progress');
?>

<?php if (!$selected_ta): ?>
<!-- ══ Assignment list ══════════════════════════════════════════ -->
<div class="page-heading">
  <h4>Teaching Progress — Select Assignment</h4>
</div>

<?php if (empty($assignments)): ?>
  <div class="text-center text-muted py-5">No subjects assigned yet. Contact the headmaster.</div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Subject</th>
          <th>Class</th>
          <th>School</th>
          <?php if ($role !== 'teacher'): ?><th>Teacher</th><?php endif; ?>
          <th>Topics</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($assignments as $a):
            // Count topics per status
            $cnt = db()->prepare('SELECT status, COUNT(*) FROM teacher_topics WHERE teacher_assignment_id=:aid GROUP BY status');
            $cnt->execute([':aid' => $a['ta_id']]);
            $counts = ['planned' => 0, 'in_progress' => 0, 'completed' => 0];
            foreach ($cnt->fetchAll() as $c) { $counts[$c['status']] = (int)$c['COUNT(*)']; }
        ?>
        <tr>
          <td class="fw-semibold"><?= e($a['subject_name']) ?> <span class="badge bg-light text-dark border ms-1"><?= e($a['subject_code']) ?></span></td>
          <td><?= e($a['level_name']) ?></td>
          <td><?= e($a['school_name']) ?></td>
          <?php if ($role !== 'teacher'): ?>
          <td><?= e($a['teacher_name']) ?></td>
          <?php endif; ?>
          <td class="small">
            <?php if ($counts['completed']): ?><span class="badge bg-success me-1" title="Completed"><?= $counts['completed'] ?></span><?php endif; ?>
            <?php if ($counts['in_progress']): ?><span class="badge bg-primary me-1" title="In Progress"><?= $counts['in_progress'] ?></span><?php endif; ?>
            <?php if ($counts['planned']): ?><span class="badge bg-secondary me-1" title="Planned"><?= $counts['planned'] ?></span><?php endif; ?>
            <?php if (array_sum($counts) === 0): ?><span class="text-muted">0 topics</span><?php endif; ?>
          </td>
          <td>
            <a href="<?= e(url('teacher/teaching_progress.php?ta_id=' . $a['ta_id'])) ?>" class="btn btn-sm btn-outline-primary">Manage Topics</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php elseif (!$current_topic): ?>
<!-- ══ Topic list for this assignment ══════════════════════════ -->
<div class="mb-3">
  <a href="<?= e(url('teacher/teaching_progress.php')) ?>" class="btn btn-outline-secondary btn-sm">← Assignments</a>
</div>

<div class="page-heading">
  <h4>
    <?= e($selected_ta['subject_name']) ?>
    <span class="badge bg-light text-dark border ms-1" style="font-size:.75rem"><?= e($selected_ta['subject_code']) ?></span>
    <small class="text-muted ms-2"><?= e($selected_ta['level_name']) ?> · <?= e($selected_ta['school_name']) ?></small>
  </h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTopicModal">+ Add Topic</button>
</div>

<?php if (empty($topics)): ?>
  <div class="text-center text-muted py-5">No topics yet. Click "Add Topic" to start planning your syllabus.</div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Topic</th>
          <th>Competence</th>
          <th>Status</th>
          <th>Test</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topics as $t):
            $test_info = db()->prepare('SELECT status, pass_rate, attempt_no FROM topic_tests WHERE teacher_topic_id=:tid ORDER BY attempt_no DESC LIMIT 1');
            $test_info->execute([':tid' => $t['id']]);
            $ti = $test_info->fetch();
        ?>
        <tr>
          <td class="text-muted"><?= (int)$t['sort_order'] ?></td>
          <td class="fw-semibold"><?= e($t['title']) ?></td>
          <td class="small text-muted"><?= e(mb_substr((string)$t['competence'], 0, 80)) ?><?= strlen((string)$t['competence']) > 80 ? '...' : '' ?></td>
          <td><?= get_topic_status_badge($t['status']) ?></td>
          <td class="small">
            <?php if ($ti): ?>
              <?php if ($ti['status'] === 'approved'): ?>
                <span class="badge bg-success">Approved (<?= (int)$ti['pass_rate'] ?>%)</span>
              <?php elseif ($ti['status'] === 'pending'): ?>
                <span class="badge bg-warning text-dark">Pending (<?= (int)$ti['pass_rate'] ?>%)</span>
              <?php elseif ($ti['status'] === 'rejected'): ?>
                <span class="badge bg-danger">Rejected</span>
              <?php else: ?>
                <span class="badge bg-secondary">Draft (<?= (int)$ti['pass_rate'] ?>%)</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="<?= e(url('teacher/teaching_progress.php?ta_id=' . $ta_id . '&topic_id=' . $t['id'])) ?>" class="btn btn-sm btn-outline-primary">Manage</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Add Topic Modal ──────────────────────────────────────── -->
<div class="modal fade" id="addTopicModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_topic">
        <div class="modal-header">
          <h5 class="modal-title">Add Topic</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Topic Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title" required maxlength="255" placeholder="e.g. Introduction to Algebra">
          </div>
          <div class="mb-3">
            <label class="form-label">Competence (skill students should master)</label>
            <textarea class="form-control" name="competence" rows="3" placeholder="Describe what students should be able to do..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Save Topic</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══ Topic detail view ═══════════════════════════════════════ -->
<div class="mb-3">
  <a href="<?= e(url('teacher/teaching_progress.php?ta_id=' . $ta_id)) ?>" class="btn btn-outline-secondary btn-sm">← Topics</a>
</div>

<div class="page-heading">
  <h4>
    <?= e($current_topic['title']) ?>
    <?= get_topic_status_badge($current_topic['status']) ?>
  </h4>
  <div class="text-muted small">
    <?= e($selected_ta['subject_name']) ?> · <?= e($selected_ta['level_name']) ?> · <?= e($selected_ta['school_name']) ?>
  </div>
</div>

<?php if ($current_topic['competence']): ?>
  <div class="alert alert-info py-2 small"><strong>Competence:</strong> <?= e($current_topic['competence']) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <!-- ── Status actions ─────────────────────────────────────── -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-header"><strong>Actions</strong></div>
      <div class="card-body">
        <?php if ($current_topic['status'] === 'planned'): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="start_topic">
            <input type="hidden" name="topic_id" value="<?= (int)$current_topic['id'] ?>">
            <button type="submit" class="btn btn-primary w-100 mb-2">Start Teaching</button>
          </form>
          <button class="btn btn-outline-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#editTopicModal">Edit Topic</button>
          <form method="post" onsubmit="return confirm('Delete this topic?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_topic">
            <input type="hidden" name="topic_id" value="<?= (int)$current_topic['id'] ?>">
            <button type="submit" class="btn btn-outline-danger w-100">Delete Topic</button>
          </form>

        <?php elseif ($current_topic['status'] === 'in_progress'): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="complete_topic">
            <input type="hidden" name="topic_id" value="<?= (int)$current_topic['id'] ?>">
            <button type="submit" class="btn btn-success w-100 mb-2">Mark as Completed</button>
          </form>
          <button class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#editTopicModal">Edit Topic</button>

        <?php elseif ($current_topic['status'] === 'completed'): ?>
          <?php
            $has_approved = false;
            $has_pending = false;
            if ($test && $test['status'] === 'approved') { $has_approved = true; }
            if ($test && $test['status'] === 'pending') { $has_pending = true; }
          ?>
          <div class="text-center">
            <?php if ($has_approved): ?>
              <span class="badge bg-success fs-6 p-2">Approved</span>
              <form method="post" class="mt-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reteach_topic">
                <input type="hidden" name="topic_id" value="<?= (int)$current_topic['id'] ?>">
                <button type="submit" class="btn btn-outline-warning btn-sm w-100"
                        onclick="return confirm('Reteach this topic? A new test attempt will be created when you complete it again.')">
                  Reteach Topic
                </button>
              </form>
            <?php elseif ($has_pending): ?>
              <span class="badge bg-warning text-dark fs-6 p-2">Awaiting Approval</span>
            <?php else: ?>
              <span class="badge bg-secondary fs-6 p-2">Test Required</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Daily progress log ─────────────────────────────────── -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Daily Progress</strong>
        <?php if ($current_topic['status'] === 'in_progress'): ?>
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addLogModal">+ Add</button>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($daily_logs)): ?>
          <div class="text-muted small p-3">No log entries yet.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($daily_logs as $log): ?>
              <div class="list-group-item py-2 px-3">
                <div class="d-flex justify-content-between">
                  <small class="fw-semibold"><?= e(date('d/m/Y', strtotime($log['log_date']))) ?></small>
                  <small class="text-muted"><?= e(date('H:i', strtotime($log['created_at']))) ?></small>
                </div>
                <div class="small mt-1"><?= nl2br(e($log['notes'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Test status ────────────────────────────────────────── -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-header"><strong>Topic Test</strong></div>
      <div class="card-body">
        <?php if ($test): ?>
          <div class="mb-2">
            <span class="text-muted small">Attempt #<?= (int)$test['attempt_no'] ?></span>
            <span class="badge <?= $test['status'] === 'approved' ? 'bg-success' : ($test['status'] === 'pending' ? 'bg-warning text-dark' : ($test['status'] === 'rejected' ? 'bg-danger' : 'bg-secondary')) ?> ms-2">
              <?= e(ucfirst($test['status'])) ?>
            </span>
          </div>
          <?php if ($test['pass_rate'] !== null): ?>
            <div class="mb-2"><strong>Pass Rate:</strong> <?= (float)$test['pass_rate'] ?>%</div>
          <?php endif; ?>
          <?php if ($test['test_date']): ?>
            <div class="mb-2"><strong>Test Date:</strong> <?= e(date('d/m/Y', strtotime($test['test_date']))) ?></div>
          <?php endif; ?>
          <?php if ($test['status'] === 'rejected' && $test['reject_reason']): ?>
            <div class="alert alert-danger py-1 small"><strong>Rejected:</strong> <?= e($test['reject_reason']) ?></div>
          <?php endif; ?>
          <?php if ($test['status'] === 'approved' && $test['approved_at']): ?>
            <div class="text-muted small">Approved on <?= e(date('d/m/Y H:i', strtotime($test['approved_at']))) ?></div>
          <?php endif; ?>
          <?php if ($test['status'] === 'draft' || $test['status'] === 'rejected'): ?>
            <button class="btn btn-sm btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#testModal">
              <?= $test['status'] === 'rejected' ? 'Re-enter Scores' : 'Edit & Submit Test' ?>
            </button>
          <?php endif; ?>
        <?php elseif ($current_topic['status'] === 'completed'): ?>
          <p class="text-muted small mb-2">No test entered yet. Click below to create one.</p>
          <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#testModal">Enter Test Scores</button>
        <?php else: ?>
          <p class="text-muted small mb-0">Test entry will be available when the topic is completed.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Student scores table (read-only summary) ─────────────── -->
<?php if ($test && !empty($test_students)): ?>
<div class="card mb-4">
  <div class="card-header"><strong>Student Scores</strong></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Admission No.</th>
          <th>Student Name</th>
          <th>Score</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($test_students as $i => $ts): ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><?= e($ts['admission_no']) ?></td>
          <td><?= e($ts['full_name']) ?></td>
          <td><?= $ts['absent'] ? '—' : (float)$ts['score'] ?></td>
          <td><?= $ts['absent'] ? '<span class="badge bg-secondary">Absent</span>' : ((float)$ts['score'] >= 50 ? '<span class="badge bg-success">Pass</span>' : '<span class="badge bg-danger">Fail</span>') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Edit Topic Modal ─────────────────────────────────────── -->
<div class="modal fade" id="editTopicModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_topic">
        <input type="hidden" name="topic_id" value="<?= (int)$current_topic['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">Edit Topic</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Topic Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title" required maxlength="255" value="<?= e($current_topic['title']) ?>">
          </div>
          <div class="mb-2">
            <label class="form-label">Competence</label>
            <textarea class="form-control" name="competence" rows="3"><?= e((string)$current_topic['competence']) ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label">Sort Order</label>
            <input type="number" class="form-control" name="sort_order" value="<?= (int)$current_topic['sort_order'] ?>" min="1">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Add Daily Log Modal ──────────────────────────────────── -->
<div class="modal fade" id="addLogModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_log">
        <input type="hidden" name="topic_id" value="<?= (int)$current_topic['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">Add Daily Progress</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="log_date" value="<?= e(date('Y-m-d')) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes <span class="text-danger">*</span></label>
            <textarea class="form-control" name="notes" rows="4" required placeholder="What was covered today? Any observations?"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Save Log</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Test Entry Modal ─────────────────────────────────────── -->
<div class="modal fade" id="testModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="topic_id" value="<?= (int)$current_topic['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">
            <?php
              if ($test && $test['status'] === 'rejected') {
                  echo 'New Attempt #' . ((int)$test['attempt_no'] + 1) . ' (Previous Rejected)';
              } elseif ($test && $test['status'] === 'draft') {
                  echo 'Edit Test Scores (Attempt #' . (int)$test['attempt_no'] . ')';
              } else {
                  echo 'Enter Test Scores';
              }
            ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Test Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="test_date" value="<?= $test ? e($test['test_date']) : e(date('Y-m-d')) ?>" required>
          </div>
          <div class="alert alert-info py-1 small">
            Enter scores out of 100. Mark <strong>Absent</strong> for students who did not take the test.
            Pass mark is <strong><?= PASS_THRESHOLD ?>%</strong>. Students with &ge; <?= PASS_THRESHOLD ?>% count as passed.
          </div>
          <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Admission No.</th>
                  <th>Student Name</th>
                  <th style="width:100px">Score (0-100)</th>
                  <th style="width:80px">Absent</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  // Only pre-fill scores from draft tests (not rejected/approved)
                  $prefill = $test && $test['status'] === 'draft';
                  $student_score_map = [];
                  if ($prefill) {
                      foreach ($test_students as $ts) {
                          $student_score_map[(int)$ts['student_id']] = $ts;
                      }
                  }
                ?>
                <?php foreach ($all_students as $si => $st):
                    $existing = $student_score_map[(int)$st['id']] ?? null;
                ?>
                <tr>
                  <td class="text-muted small"><?= $si + 1 ?></td>
                  <td class="small"><?= e($st['admission_no']) ?></td>
                  <td class="fw-semibold small"><?= e($st['full_name']) ?></td>
                  <td>
                    <input type="number" class="form-control form-control-sm" name="score_<?= (int)$st['id'] ?>"
                           min="0" max="100" step="0.5" inputmode="decimal"
                           value="<?= $prefill && $existing && !$existing['absent'] ? e((string)$existing['score']) : '' ?>"
                           <?= $prefill && $existing && $existing['absent'] ? 'disabled' : '' ?>>
                  </td>
                  <td class="text-center">
                    <input type="checkbox" class="form-check-input" name="absent_<?= (int)$st['id'] ?>"
                           onchange="this.closest('tr').querySelector('[name^=score_]').disabled=this.checked"
                           <?= $prefill && $existing && $existing['absent'] ? 'checked' : '' ?>>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <div>
            <button type="submit" name="action" value="save_test_draft" class="btn btn-outline-secondary">Save Draft</button>
          </div>
          <div>
            <button type="submit" name="action" value="submit_test" class="btn btn-success">Submit for Approval</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<?php
render_footer();
