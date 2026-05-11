<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['headmaster']);

$user      = current_user();
$school_id = (int)($user['school_id'] ?? 0);
$pdo       = db();

// ── POST actions ────────────────────────────────────────────────
if (is_post()) {
    csrf_verify();
    $action   = (string)($_POST['action'] ?? '');
    $test_id  = (int)($_POST['test_id'] ?? 0);

    try {
        if ($action === 'approve') {
            $chk = $pdo->prepare(
                'SELECT tt.id, tpc.title AS topic_title, ta.teacher_id
                 FROM topic_tests tt
                 JOIN teacher_topics tpc ON tpc.id = tt.teacher_topic_id
                 JOIN teacher_assignments ta ON ta.id = tpc.teacher_assignment_id
                 WHERE tt.id=:id AND ta.school_id=:sid AND tt.status="pending"'
            );
            $chk->execute([':id' => $test_id, ':sid' => $school_id]);
            $chk_row = $chk->fetch();
            if (!$chk_row) {
                throw new \RuntimeException('Test not found or not pending.');
            }

            $pdo->prepare(
                'UPDATE topic_tests SET status="approved", approved_by=:uid, approved_at=NOW() WHERE id=:id'
            )->execute([':id' => $test_id, ':uid' => (int)$user['id']]);

            // Notify teacher of approval
            notify_send(
                (int)$chk_row['teacher_id'],
                'topic_test_approved',
                'Test Approved',
                'Your topic test "' . $chk_row['topic_title'] . '" has been approved by the headmaster. You may proceed with the next topic.',
                $test_id,
                'topic_test'
            );

            flash_set('success', 'Test approved. The teacher can now proceed to the next topic.');
            redirect('school/topic_test_approvals.php');
        }

        if ($action === 'reject') {
            $reason = trim((string)($_POST['reject_reason'] ?? ''));
            if ($reason === '') {
                throw new \RuntimeException('Reject reason is required.');
            }

            $chk = $pdo->prepare(
                'SELECT tt.id, tpc.title AS topic_title, ta.teacher_id
                 FROM topic_tests tt
                 JOIN teacher_topics tpc ON tpc.id = tt.teacher_topic_id
                 JOIN teacher_assignments ta ON ta.id = tpc.teacher_assignment_id
                 WHERE tt.id=:id AND ta.school_id=:sid AND tt.status="pending"'
            );
            $chk->execute([':id' => $test_id, ':sid' => $school_id]);
            $chk_row = $chk->fetch();
            if (!$chk_row) {
                throw new \RuntimeException('Test not found or not pending.');
            }

            $pdo->prepare(
                'UPDATE topic_tests SET status="rejected", reject_reason=:reason, approved_by=:uid, approved_at=NOW() WHERE id=:id'
            )->execute([':id' => $test_id, ':reason' => $reason, ':uid' => (int)$user['id']]);

            // Notify teacher of rejection
            notify_send(
                (int)$chk_row['teacher_id'],
                'topic_test_rejected',
                'Test Rejected',
                'Your topic test "' . $chk_row['topic_title'] . '" has been rejected. Reason: ' . $reason . ' — Please reteach the topic and try again.',
                $test_id,
                'topic_test'
            );

            flash_set('success', 'Test rejected. Teacher will be notified to reteach.');
            redirect('school/topic_test_approvals.php');
        }
    } catch (\RuntimeException $ex) {
        flash_set('error', $ex->getMessage());
        redirect('school/topic_test_approvals.php');
    }
}

// ── List pending tests for this school ──────────────────────────
$stmt = $pdo->prepare(
    'SELECT tt.id AS test_id, tt.attempt_no, tt.test_date, tt.pass_rate, tt.submitted_at,
            tpc.id AS topic_id, tpc.title AS topic_title,
            u.full_name AS teacher_name,
            sub.name AS subject_name, sub.code AS subject_code,
            lv.name AS level_name
     FROM topic_tests tt
     JOIN teacher_topics tpc ON tpc.id = tt.teacher_topic_id
     JOIN teacher_assignments ta ON ta.id = tpc.teacher_assignment_id
     JOIN users u ON u.id = tt.submitted_by
     JOIN subjects sub ON sub.id = ta.subject_id
     JOIN levels lv ON lv.id = ta.level_id
     WHERE ta.school_id = :sid AND tt.status = "pending"
     ORDER BY tt.submitted_at ASC'
);
$stmt->execute([':sid' => $school_id]);
$pending_tests = $stmt->fetchAll();

// ── Also show recent approved/rejected for reference ────────────
$recent = $pdo->prepare(
    'SELECT tt.id AS test_id, tt.attempt_no, tt.test_date, tt.pass_rate, tt.status,
            tt.reject_reason, tt.approved_at,
            tpc.title AS topic_title,
            u.full_name AS teacher_name,
            sub.name AS subject_name
     FROM topic_tests tt
     JOIN teacher_topics tpc ON tpc.id = tt.teacher_topic_id
     JOIN teacher_assignments ta ON ta.id = tpc.teacher_assignment_id
     JOIN users u ON u.id = tt.submitted_by
     JOIN subjects sub ON sub.id = ta.subject_id
     WHERE ta.school_id = :sid AND tt.status IN ("approved","rejected")
     ORDER BY tt.approved_at DESC LIMIT 10'
);
$recent->execute([':sid' => $school_id]);
$recent_tests = $recent->fetchAll();

render_header('Topic Test Approvals');
?>

<div class="page-heading">
  <h4>Topic Test Approvals</h4>
</div>

<?php if (empty($pending_tests)): ?>
  <div class="alert alert-info">No pending topic test approvals.</div>
<?php else: ?>
<div class="card mb-4">
  <div class="card-header"><strong>Pending Approvals (<?= count($pending_tests) ?>)</strong></div>
  <?php foreach ($pending_tests as $pt):
      // Load student scores for this test
      $ss = $pdo->prepare(
          'SELECT tts.score, tts.absent, st.full_name, st.admission_no
           FROM topic_test_students tts
           JOIN students st ON st.id = tts.student_id
           WHERE tts.topic_test_id = :ttid
           ORDER BY st.full_name'
      );
      $ss->execute([':ttid' => $pt['test_id']]);
      $scores = $ss->fetchAll();

      $present = array_filter($scores, fn($s) => !$s['absent']);
      $pass = array_filter($present, fn($s) => (float)$s['score'] >= 50);
      $fail = array_filter($present, fn($s) => (float)$s['score'] < 50);
  ?>
  <div class="card-body border-bottom">
    <div class="d-flex justify-content-between align-items-start mb-2">
      <div>
        <h5 class="mb-1"><?= e($pt['topic_title']) ?></h5>
        <div class="text-muted small">
          <?= e($pt['subject_name']) ?> (<?= e($pt['subject_code']) ?>) ·
          <?= e($pt['level_name']) ?> ·
          Teacher: <?= e($pt['teacher_name']) ?> ·
          Attempt #<?= (int)$pt['attempt_no'] ?>
        </div>
      </div>
      <div class="text-end">
        <div class="fw-bold fs-5 <?= (float)$pt['pass_rate'] > 75 ? 'text-success' : 'text-danger' ?>">
          <?= (float)$pt['pass_rate'] ?>%
        </div>
        <div class="small text-muted">Pass Rate</div>
      </div>
    </div>

    <!-- Student scores table -->
    <div class="table-responsive mb-2">
      <table class="table table-sm small mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Admission No.</th>
            <th>Name</th>
            <th>Score</th>
            <th>Result</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($scores as $i => $s): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td><?= e($s['admission_no']) ?></td>
            <td><?= e($s['full_name']) ?></td>
            <td><?= $s['absent'] ? '—' : (float)$s['score'] ?></td>
            <td>
              <?= $s['absent'] ? '<span class="badge bg-secondary">Absent</span>'
                  : ((float)$s['score'] >= 50
                      ? '<span class="badge bg-success">Pass</span>'
                      : '<span class="badge bg-danger">Fail</span>') ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex gap-2">
      <form method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="test_id" value="<?= (int)$pt['test_id'] ?>">
        <button type="submit" class="btn btn-success btn-sm">Approve</button>
      </form>
      <button class="btn btn-danger btn-sm" data-bs-toggle="modal"
              data-bs-target="#rejectModal"
              data-test-id="<?= (int)$pt['test_id'] ?>"
              data-topic="<?= e($pt['topic_title']) ?>">
        Reject
      </button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Recent history ───────────────────────────────────────────── -->
<?php if (!empty($recent_tests)): ?>
<div class="card">
  <div class="card-header"><strong>Recent Approvals / Rejections</strong></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Topic</th>
          <th>Teacher</th>
          <th>Subject</th>
          <th>Attempt</th>
          <th>Pass Rate</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent_tests as $rt): ?>
        <tr>
          <td><?= e($rt['topic_title']) ?></td>
          <td><?= e($rt['teacher_name']) ?></td>
          <td><?= e($rt['subject_name']) ?></td>
          <td>#<?= (int)$rt['attempt_no'] ?></td>
          <td><?= (float)$rt['pass_rate'] ?>%</td>
          <td>
            <?php if ($rt['status'] === 'approved'): ?>
              <span class="badge bg-success">Approved</span>
            <?php else: ?>
              <span class="badge bg-danger" title="<?= e($rt['reject_reason']) ?>">Rejected</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= e(date('d/m/Y H:i', strtotime($rt['approved_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Reject Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="test_id" id="rejectTestId" value="">
        <div class="modal-header">
          <h5 class="modal-title">Reject Test: <span id="rejectTopicName" class="fw-normal"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Reason for rejection <span class="text-danger">*</span></label>
            <textarea class="form-control" name="reject_reason" rows="4" required
                      placeholder="Explain why the test is being rejected so the teacher can improve."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-danger">Reject Test</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rejectModal = document.getElementById('rejectModal');
    if (rejectModal) {
        rejectModal.addEventListener('show.bs.modal', function(event) {
            const btn = event.relatedTarget;
            const testId = btn.dataset.testId;
            const topic = btn.dataset.topic;
            document.getElementById('rejectTestId').value = testId;
            document.getElementById('rejectTopicName').textContent = topic;
        });
    }
});
</script>

<?php
render_footer();
