<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['super_admin']);

$errors     = [];
$edit_action = false;

if (is_post()) {
    csrf_verify();

    $action = (string)($_POST['action'] ?? '');

    // ── Toggle status ──────────────────────────────────────────
    if ($action === 'toggle_status') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            $stmt = db()->prepare('UPDATE users SET status = IF(status="active","inactive","active") WHERE id = :id');
            $stmt->execute([':id' => $uid]);
            flash_set('success', 'User status has been updated.');
        }
        redirect('super/users.php');
    }

    // ── Delete user ────────────────────────────────────────────
    if ($action === 'delete_user') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $self = (int)(current_user()['id'] ?? 0);
        if ($uid > 0 && $uid !== $self) {
            db()->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
            flash_set('success', 'User deleted.');
        } else {
            flash_set('error', 'You cannot delete your own account.');
        }
        redirect('super/users.php');
    }

    // ── Add user ───────────────────────────────────────────────
    if ($action === 'add_user') {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email     = trim(strtolower((string)($_POST['email'] ?? '')));
        $username  = trim((string)($_POST['username'] ?? ''));
        $password  = (string)($_POST['password'] ?? '');
        $role      = (string)($_POST['role'] ?? '');
        $school_id = (int)($_POST['school_id'] ?? 0);
        $status    = (string)($_POST['status'] ?? 'active');

        $valid_roles  = ['super_admin', 'district_admin', 'headmaster', 'teacher'];
        $needs_school = in_array($role, ['headmaster', 'teacher'], true);

        if ($full_name === '')                                             $errors[] = 'Full name is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'A valid email address is required.';
        if ($username === '' || strlen($username) < 3)                    $errors[] = 'Username must be at least 3 characters.';
        if (strlen($password) < 6)                                        $errors[] = 'Password must be at least 6 characters.';
        if (!in_array($role, $valid_roles, true))                         $errors[] = 'Please select a valid role.';
        if ($needs_school && $school_id === 0)                            $errors[] = 'Please select a school for headmaster or teacher.';
        if (!in_array($status, ['active', 'inactive'], true))             $errors[] = 'Invalid status.';

        if (empty($errors)) {
            $check = db()->prepare('SELECT id FROM users WHERE email = :e OR username = :u LIMIT 1');
            $check->execute([':e' => $email, ':u' => $username]);
            if ($check->fetch()) $errors[] = 'Email or username is already in use.';
        }

        if (empty($errors)) {
            $stmt = db()->prepare(
                'INSERT INTO users (school_id, full_name, email, username, password_hash, role, status)
                 VALUES (:sid, :name, :email, :uname, :hash, :role, :status)'
            );
            $stmt->execute([
                ':sid'    => $needs_school ? $school_id : null,
                ':name'   => $full_name,
                ':email'  => $email,
                ':uname'  => $username,
                ':hash'   => password_hash($password, PASSWORD_BCRYPT),
                ':role'   => $role,
                ':status' => $status,
            ]);
            flash_set('success', 'User ' . $full_name . ' added.');
            redirect('super/users.php');
        }
    }

    // ── Edit user ──────────────────────────────────────────────
    if ($action === 'edit_user') {
        $edit_action = true;
        $uid       = (int)($_POST['user_id'] ?? 0);
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email     = trim(strtolower((string)($_POST['email'] ?? '')));
        $username  = trim((string)($_POST['username'] ?? ''));
        $password  = (string)($_POST['password'] ?? '');
        $role      = (string)($_POST['role'] ?? '');
        $school_id = (int)($_POST['school_id'] ?? 0);
        $status    = (string)($_POST['status'] ?? 'active');

        $valid_roles  = ['super_admin', 'district_admin', 'headmaster', 'teacher'];
        $needs_school = in_array($role, ['headmaster', 'teacher'], true);
        $self         = (int)(current_user()['id'] ?? 0);

        if ($uid <= 0)                                                    $errors[] = 'Invalid user.';
        if ($full_name === '')                                             $errors[] = 'Full name is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'A valid email address is required.';
        if ($username === '' || strlen($username) < 3)                    $errors[] = 'Username must be at least 3 characters.';
        if ($password !== '' && strlen($password) < 6)                    $errors[] = 'New password must be at least 6 characters.';
        if (!in_array($role, $valid_roles, true))                         $errors[] = 'Please select a valid role.';
        if ($needs_school && $school_id === 0)                            $errors[] = 'Please select a school for headmaster or teacher.';
        if (!in_array($status, ['active', 'inactive'], true))             $errors[] = 'Invalid status.';

        if (empty($errors)) {
            $check = db()->prepare('SELECT id FROM users WHERE (email = :e OR username = :u) AND id != :id LIMIT 1');
            $check->execute([':e' => $email, ':u' => $username, ':id' => $uid]);
            if ($check->fetch()) $errors[] = 'Email or username is already in use by another user.';
        }

        if (empty($errors)) {
            if ($password !== '') {
                $stmt = db()->prepare(
                    'UPDATE users SET school_id=:sid, full_name=:name, email=:email, username=:uname,
                     password_hash=:hash, role=:role, status=:status WHERE id=:id'
                );
                $stmt->execute([
                    ':sid'    => $needs_school ? $school_id : null,
                    ':name'   => $full_name,
                    ':email'  => $email,
                    ':uname'  => $username,
                    ':hash'   => password_hash($password, PASSWORD_BCRYPT),
                    ':role'   => $role,
                    ':status' => $status,
                    ':id'     => $uid,
                ]);
            } else {
                $stmt = db()->prepare(
                    'UPDATE users SET school_id=:sid, full_name=:name, email=:email, username=:uname,
                     role=:role, status=:status WHERE id=:id'
                );
                $stmt->execute([
                    ':sid'    => $needs_school ? $school_id : null,
                    ':name'   => $full_name,
                    ':email'  => $email,
                    ':uname'  => $username,
                    ':role'   => $role,
                    ':status' => $status,
                    ':id'     => $uid,
                ]);
            }
            flash_set('success', 'User ' . $full_name . ' updated.');
            redirect('super/users.php');
        }
    }
}

// ── Fetch data ─────────────────────────────────────────────────
$users = db()->query(
    'SELECT u.id, u.full_name, u.email, u.username, u.role, u.status,
            u.school_id, s.name AS school_name
     FROM users u
     LEFT JOIN schools s ON s.id = u.school_id
     ORDER BY u.role, u.full_name'
)->fetchAll();

$schools = db()->query('SELECT id, name FROM schools WHERE status = "active" ORDER BY name')->fetchAll();

$self_id = (int)(current_user()['id'] ?? 0);

// Pass user data to JS for edit modal population
$users_json = json_encode(array_column($users, null, 'id'), JSON_HEX_TAG);

render_header('Users');
?>

<div class="page-heading">
  <h4>Users <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?= count($users) ?></span></h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAdd">
    + Add User
  </button>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Name</th>
          <th class="d-none d-md-table-cell">Email</th>
          <th class="d-none d-sm-table-cell">Username</th>
          <th>Role</th>
          <th class="d-none d-md-table-cell">School</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $i => $u): ?>
        <tr>
          <td class="text-muted small"><?= $i + 1 ?></td>
          <td><?= e($u['full_name']) ?></td>
          <td class="small d-none d-md-table-cell"><?= e($u['email']) ?></td>
          <td class="small text-muted d-none d-sm-table-cell"><?= e($u['username']) ?></td>
          <td><span class="badge bg-secondary"><?= e($u['role']) ?></span></td>
          <td class="small"><?= $u['school_name'] ? e($u['school_name']) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <?php if ($u['status'] === 'active'): ?>
              <span class="badge bg-success">Active</span>
            <?php else: ?>
              <span class="badge bg-danger">Inactive</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end">
              <!-- Edit -->
              <button class="btn btn-outline-primary btn-sm btn-edit"
                      data-id="<?= (int)$u['id'] ?>"
                      data-bs-toggle="modal" data-bs-target="#modalEdit">
                Edit
              </button>

              <!-- Toggle status -->
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit"
                        onclick="return confirm('Change this user\'s status?')">
                  <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>

              <!-- Delete (hidden for self) -->
              <?php if ((int)$u['id'] !== $self_id): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit"
                        onclick="return confirm('Are you sure you want to delete user <?= e(addslashes($u['full_name'])) ?>? This action cannot be undone.')">
                  Delete
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No users yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Modal: Add User ──────────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_user">
        <div class="modal-header">
          <h5 class="modal-title">Add New User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= users_form_fields($schools, $_POST, 'add') ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Edit User ──────────────────────────────── -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formEdit">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="modal-header">
          <h5 class="modal-title">Edit User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= users_form_fields($schools, [], 'edit') ?>
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

  const USERS = <?= $users_json ?>;

  // ── Generic school-toggle helper ────────────────────────────
  function bindSchoolToggle(formEl) {
    const role   = formEl.querySelector('[name="role"]');
    const row    = formEl.querySelector('.school-row');
    const sel    = formEl.querySelector('[name="school_id"]');
    if (!role || !row || !sel) return;

    function toggle() {
      const needs = ['headmaster', 'teacher'].includes(role.value);
      row.style.display = needs ? '' : 'none';
      sel.required = needs;
    }
    role.addEventListener('change', toggle);
    toggle();
  }

  // ── Password toggle helper ───────────────────────────────────
  function bindPwdToggle(formEl, inputId, btnId) {
    const btn   = formEl.querySelector('#' + btnId);
    const input = formEl.querySelector('#' + inputId);
    if (!btn || !input) return;
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Ficha' : 'Onyesha';
    });
  }

  // ── Wire up Add modal ────────────────────────────────────────
  const addModal = document.getElementById('modalAdd');
  bindSchoolToggle(addModal);
  bindPwdToggle(addModal, 'addPwd', 'toggleAddPwd');

  // ── Wire up Edit modal ───────────────────────────────────────
  const editModal = document.getElementById('modalEdit');
  bindSchoolToggle(editModal);
  bindPwdToggle(editModal, 'editPwd', 'toggleEditPwd');

  // Populate edit modal when Edit button clicked
  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const u = USERS[btn.dataset.id];
      if (!u) return;

      const f = document.getElementById('formEdit');
      f.querySelector('#editUserId').value        = u.id;
      f.querySelector('[name="full_name"]').value = u.full_name;
      f.querySelector('[name="email"]').value     = u.email;
      f.querySelector('[name="username"]').value  = u.username;
      f.querySelector('[name="role"]').value      = u.role;
      f.querySelector('[name="status"]').value    = u.status;
      f.querySelector('[name="school_id"]').value = u.school_id ?? '';
      f.querySelector('[name="password"]').value  = '';

      // Trigger school row visibility
      f.querySelector('[name="role"]').dispatchEvent(new Event('change'));
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

// ── Shared form fields (add & edit) ──────────────────────────
function users_form_fields(array $schools, array $post, string $mode): string
{
    $prefix = $mode === 'edit' ? 'edit' : 'add';
    $p = fn(string $k, string $d = '') => e((string)($post[$k] ?? $d));

    $pwd_label = $mode === 'edit'
        ? 'Password Mpya <small class="text-muted">(acha wazi kama hubadilishi)</small>'
        : 'Password <span class="text-danger">*</span>';

    $roles = [
        'super_admin'    => 'Super Admin',
        'district_admin' => 'District Admin',
        'headmaster'     => 'Headmaster',
        'teacher'        => 'Teacher',
    ];

    $role_opts = '';
    foreach ($roles as $val => $label) {
        $sel = $p('role') === $val ? ' selected' : '';
        $role_opts .= "<option value=\"{$val}\"{$sel}>{$label}</option>";
    }

    $school_opts = '<option value="">-- Select School --</option>';
    foreach ($schools as $s) {
        $sel = ((int)($post['school_id'] ?? 0)) === (int)$s['id'] ? ' selected' : '';
        $school_opts .= '<option value="' . (int)$s['id'] . '"' . $sel . '>' . e($s['name']) . '</option>';
    }

    $status_active   = ($post['status'] ?? 'active') === 'active'   ? ' selected' : '';
    $status_inactive = ($post['status'] ?? 'active') === 'inactive' ? ' selected' : '';
    $pwd_attrs       = $mode === 'add' ? 'required minlength="6"' : 'minlength="6"';
    $prefix_ucfirst  = ucfirst($prefix);

    return <<<HTML
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Full Name <span class="text-danger">*</span></label>
        <input class="form-control" name="full_name" required value="{$p('full_name')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">Email <span class="text-danger">*</span></label>
        <input class="form-control" type="email" name="email" required value="{$p('email')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">Username <span class="text-danger">*</span></label>
        <input class="form-control" name="username" required minlength="3" value="{$p('username')}">
      </div>
      <div class="col-md-6">
        <label class="form-label">{$pwd_label}</label>
        <div class="input-group">
          <input class="form-control" type="password" name="password" id="{$prefix}Pwd"
                 {$pwd_attrs}>
          <button class="btn btn-outline-secondary" type="button" id="toggle{$prefix_ucfirst}Pwd">Onyesha</button>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label">Role <span class="text-danger">*</span></label>
        <select class="form-select" name="role" required>
          <option value="">-- Select --</option>
          {$role_opts}
        </select>
      </div>
      <div class="col-md-6 school-row" style="display:none">
        <label class="form-label">School <span class="text-danger">*</span></label>
        <select class="form-select" name="school_id">
          {$school_opts}
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="active"{$status_active}>Active</option>
          <option value="inactive"{$status_inactive}>Inactive</option>
        </select>
      </div>
    </div>
    HTML;
}
