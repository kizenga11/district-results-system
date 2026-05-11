<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['headmaster']);

$user      = current_user();
$school_id = (int)($user['school_id'] ?? 0);
$errors    = [];
$edit_action = false;

// ── POST actions ───────────────────────────────────────────────
if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_status') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            $chk = db()->prepare('SELECT 1 FROM users WHERE id=:id AND school_id=:s AND role="teacher" LIMIT 1');
            $chk->execute([':id' => $uid, ':s' => $school_id]);
            if ($chk->fetch()) {
                db()->prepare('UPDATE users SET status = IF(status="active","inactive","active") WHERE id=:id')
                   ->execute([':id' => $uid]);
                flash_set('success', 'Teacher status updated.');
            }
        }
        redirect('school/teachers.php');
    }

    if ($action === 'delete_teacher') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            $chk = db()->prepare('SELECT 1 FROM users WHERE id=:id AND school_id=:s AND role="teacher" LIMIT 1');
            $chk->execute([':id' => $uid, ':s' => $school_id]);
            if ($chk->fetch()) {
                // Do not allow deleting a teacher referenced by assignments (permanent or per-exam)
                $has_assign = db()->prepare(
                    'SELECT 1
                     FROM teacher_assignments ta
                     WHERE ta.teacher_id=:id
                     UNION ALL
                     SELECT 1
                     FROM teacher_subjects ts
                     WHERE ts.teacher_id=:id
                     LIMIT 1'
                );
                $has_assign->execute([':id' => $uid]);
                if ($has_assign->fetch()) {
                    flash_set('error', 'Cannot delete a teacher who has assignments. Remove their assignments first.');
                } else {
                    db()->prepare('DELETE FROM users WHERE id=:id AND role="teacher" AND school_id=:s')
                       ->execute([':id' => $uid, ':s' => $school_id]);
                    flash_set('success', 'Teacher deleted.');
                }
            }
        }
        redirect('school/teachers.php');
    }

    if ($action === 'add_teacher') {
        $errors = validate_teacher($_POST);

        if (empty($errors)) {
            $check = db()->prepare('SELECT id FROM users WHERE email=:e OR username=:u LIMIT 1');
            $check->execute([
                ':e' => strtolower(trim((string)($_POST['email'] ?? ''))),
                ':u' => trim((string)($_POST['username'] ?? '')),
            ]);
            if ($check->fetch()) $errors[] = 'Email or username is already in use.';
        }

        if (empty($errors)) {
            $d = clean_teacher($_POST, $school_id);
            db()->prepare(
                'INSERT INTO users (school_id, full_name, email, username, password_hash, role, status)
                 VALUES (:sid, :name, :email, :uname, :hash, "teacher", :status)'
            )->execute($d);
            flash_set('success', 'Teacher ' . $d[':name'] . ' registered successfully.');
            redirect('school/teachers.php');
        }
    }

    if ($action === 'edit_teacher') {
        $edit_action = true;
        $uid         = (int)($_POST['user_id'] ?? 0);
        $errors      = validate_teacher($_POST, editing: true);
        if ($uid <= 0) $errors[] = 'Invalid teacher.';

        if (empty($errors)) {
            $chk = db()->prepare('SELECT 1 FROM users WHERE id=:id AND school_id=:s AND role="teacher" LIMIT 1');
            $chk->execute([':id' => $uid, ':s' => $school_id]);
            if (!$chk->fetch()) $errors[] = 'Teacher not found.';
        }

        if (empty($errors)) {
            $email    = strtolower(trim((string)($_POST['email'] ?? '')));
            $username = trim((string)($_POST['username'] ?? ''));
            $check    = db()->prepare('SELECT id FROM users WHERE (email=:e OR username=:u) AND id!=:id LIMIT 1');
            $check->execute([':e' => $email, ':u' => $username, ':id' => $uid]);
            if ($check->fetch()) $errors[] = 'Email or username is already used by another account.';
        }

        if (empty($errors)) {
            $password = (string)($_POST['password'] ?? '');
            if ($password !== '') {
                $d = clean_teacher($_POST, $school_id);
                $d[':id'] = $uid;
                db()->prepare(
                    'UPDATE users SET full_name=:name, email=:email, username=:uname,
                     password_hash=:hash, status=:status WHERE id=:id AND role="teacher" AND school_id=:sid'
                )->execute($d);
            } else {
                $d = clean_teacher($_POST, $school_id, skip_password: true);
                $d[':id'] = $uid;
                db()->prepare(
                    'UPDATE users SET full_name=:name, email=:email, username=:uname,
                     status=:status WHERE id=:id AND role="teacher" AND school_id=:sid'
                )->execute($d);
            }
            flash_set('success', 'Teacher updated.');
            redirect('school/teachers.php');
        }
    }
}

// ── Fetch teachers for this school only ────────────────────────
$stmt = db()->prepare(
    'SELECT u.id, u.full_name, u.email, u.username, u.status,
            COUNT(ta.id) AS assign_count
     FROM users u
     LEFT JOIN teacher_assignments ta ON ta.teacher_id = u.id AND ta.school_id = u.school_id
     WHERE u.role = "teacher" AND u.school_id = :s
     GROUP BY u.id
     ORDER BY u.full_name'
);
$stmt->execute([':s' => $school_id]);
$teachers = $stmt->fetchAll();

$teachers_json = json_encode(array_column($teachers, null, 'id'), JSON_HEX_TAG);

render_header('Teachers');
?>

<div class="page-heading">
  <h4>Teachers <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?= count($teachers) ?></span></h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAdd">
    + Register Teacher
  </button>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if (empty($teachers)): ?>
  <div class="text-center text-muted py-5">No teachers registered yet for this school.</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Full Name</th>
          <th>Email</th>
          <th class="d-none d-sm-table-cell">Username</th>
          <th class="d-none d-md-table-cell">Assignments</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($teachers as $i => $t): ?>
        <tr>
          <td class="text-muted small"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= e($t['full_name']) ?></td>
          <td class="small"><?= e($t['email']) ?></td>
          <td class="small text-muted d-none d-sm-table-cell"><?= e($t['username']) ?></td>
          <td class="d-none d-md-table-cell">
            <span class="badge bg-light text-dark border"><?= (int)$t['assign_count'] ?></span>
          </td>
          <td>
            <span class="badge <?= $t['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
              <?= $t['status'] === 'active' ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end">
              <button class="btn btn-outline-primary btn-sm btn-edit"
                      data-id="<?= (int)$t['id'] ?>"
                      data-bs-toggle="modal" data-bs-target="#modalEdit">
                Edit
              </button>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="toggle_status">
                <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit"
                        onclick="return confirm('Toggle status for <?= e(addslashes($t['full_name'])) ?>?')">
                  <?= $t['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="delete_teacher">
                <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit"
                        onclick="return confirm('Delete teacher <?= e(addslashes($t['full_name'])) ?>? This cannot be undone.')">
                  Delete
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal: Register Teacher ──────────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_teacher">
        <div class="modal-header">
          <h5 class="modal-title">Register Teacher</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= teacher_form($_POST, 'add') ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Register</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Edit Teacher ──────────────────────────────────── -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formEdit">
        <?= csrf_field() ?>
        <input type="hidden" name="action"  value="edit_teacher">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Teacher</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= teacher_form([], 'edit') ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  'use strict';
  const TEACHERS = <?= $teachers_json ?>;

  ['add', 'edit'].forEach(prefix => {
    const btn   = document.getElementById(`toggle_${prefix}_pwd`);
    const input = document.getElementById(`${prefix}_pwd`);
    if (!btn || !input) return;
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
    });
  });

  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const t = TEACHERS[btn.dataset.id];
      if (!t) return;
      const f = document.getElementById('formEdit');
      f.querySelector('#editUserId').value        = t.id;
      f.querySelector('[name="full_name"]').value = t.full_name;
      f.querySelector('[name="email"]').value     = t.email;
      f.querySelector('[name="username"]').value  = t.username;
      f.querySelector('[name="status"]').value    = t.status;
      f.querySelector('[name="password"]').value  = '';
    });
  });

  <?php if (!empty($errors) && $edit_action): ?>
  new bootstrap.Modal(document.getElementById('modalEdit')).show();
  <?php elseif (!empty($errors)): ?>
  new bootstrap.Modal(document.getElementById('modalAdd')).show();
  <?php endif; ?>
})();
</script>

<?php
render_footer();

// ── Helpers ────────────────────────────────────────────────────
function validate_teacher(array $post, bool $editing = false): array
{
    $errors   = [];
    $name     = trim((string)($post['full_name'] ?? ''));
    $email    = trim((string)($post['email'] ?? ''));
    $username = trim((string)($post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');

    if ($name === '')                                                   $errors[] = 'Full name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))   $errors[] = 'A valid email address is required.';
    if ($username === '' || strlen($username) < 3)                     $errors[] = 'Username must be at least 3 characters.';
    if (!$editing && strlen($password) < 6)                            $errors[] = 'Password must be at least 6 characters.';
    if ($editing && $password !== '' && strlen($password) < 6)         $errors[] = 'New password must be at least 6 characters.';

    return $errors;
}

function clean_teacher(array $post, int $school_id, bool $skip_password = false): array
{
    $d = [
        ':sid'    => $school_id,
        ':name'   => trim((string)($post['full_name'] ?? '')),
        ':email'  => strtolower(trim((string)($post['email'] ?? ''))),
        ':uname'  => trim((string)($post['username'] ?? '')),
        ':status' => in_array($post['status'] ?? '', ['active', 'inactive']) ? $post['status'] : 'active',
    ];
    if (!$skip_password) {
        $d[':hash'] = password_hash((string)($post['password'] ?? ''), PASSWORD_BCRYPT);
    }
    return $d;
}

function teacher_form(array $post, string $mode): string
{
    $p      = fn(string $k, string $d = '') => e((string)($post[$k] ?? $d));
    $prefix = $mode;

    $pwd_label = $mode === 'edit'
        ? 'New Password <small class="text-muted">(leave blank to keep current)</small>'
        : 'Password <span class="text-danger">*</span>';
    $pwd_req = $mode === 'add' ? 'required minlength="6"' : 'minlength="6"';

    $st_act = $p('status', 'active') === 'active'   ? ' selected' : '';
    $st_in  = $p('status', 'active') === 'inactive' ? ' selected' : '';

    return <<<HTML
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Full Name <span class="text-danger">*</span></label>
        <input class="form-control" name="full_name" required value="{$p('full_name')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">Email Address <span class="text-danger">*</span></label>
        <input class="form-control" type="email" name="email" required value="{$p('email')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">Username <span class="text-danger">*</span></label>
        <input class="form-control" name="username" required minlength="3" value="{$p('username')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">{$pwd_label}</label>
        <div class="input-group">
          <input class="form-control" type="password" name="password" id="{$prefix}_pwd" {$pwd_req}>
          <button class="btn btn-outline-secondary" type="button" id="toggle_{$prefix}_pwd">Show</button>
        </div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="active"{$st_act}>Active</option>
          <option value="inactive"{$st_in}>Inactive</option>
        </select>
      </div>
    </div>
    HTML;
}
