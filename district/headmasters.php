<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role(['super_admin', 'district_admin']);

$errors      = [];
$edit_action = false;

$schools = db()->query('SELECT id, name FROM schools WHERE status="active" ORDER BY name')->fetchAll();

// ── POST actions ───────────────────────────────────────────────
if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    // ── Toggle status ──────────────────────────────────────────
    if ($action === 'toggle_status') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            db()->prepare('UPDATE users SET status = IF(status="active","inactive","active") WHERE id=:id AND role="headmaster"')
               ->execute([':id' => $uid]);
            flash_set('success', 'Headmaster status updated.');
        }
        redirect('district/headmasters.php');
    }

    // ── Delete headmaster ──────────────────────────────────────
    if ($action === 'delete_headmaster') {
        $uid     = (int)($_POST['user_id'] ?? 0);
        $self_id = (int)(current_user()['id'] ?? 0);
        if ($uid > 0 && $uid !== $self_id) {
            db()->prepare('DELETE FROM users WHERE id=:id AND role="headmaster"')->execute([':id' => $uid]);
            flash_set('success', 'Headmaster account deleted.');
        }
        redirect('district/headmasters.php');
    }

    // ── Add headmaster ─────────────────────────────────────────
    if ($action === 'add_headmaster') {
        $errors = validate_headmaster($_POST);

        if (empty($errors)) {
            $check = db()->prepare('SELECT id FROM users WHERE email=:e OR username=:u LIMIT 1');
            $check->execute([':e' => strtolower(trim((string)($_POST['email'] ?? ''))), ':u' => trim((string)($_POST['username'] ?? ''))]);
            if ($check->fetch()) $errors[] = 'Email or username is already in use.';
        }

        if (empty($errors)) {
            // Check school doesn't already have an active headmaster
            $sid = (int)($_POST['school_id'] ?? 0);
            $existing = db()->prepare('SELECT id FROM users WHERE school_id=:s AND role="headmaster" AND status="active" LIMIT 1');
            $existing->execute([':s' => $sid]);
            if ($existing->fetch()) {
                $errors[] = 'This school already has an active headmaster. Deactivate them first.';
            }
        }

        if (empty($errors)) {
            $d = clean_headmaster($_POST);
            db()->prepare(
                'INSERT INTO users (school_id, full_name, email, username, password_hash, role, status)
                 VALUES (:sid, :name, :email, :uname, :hash, "headmaster", :status)'
            )->execute($d);
            flash_set('success', 'Headmaster ' . $d[':name'] . ' registered and assigned to school.');
            redirect('district/headmasters.php');
        }
    }

    // ── Edit headmaster ────────────────────────────────────────
    if ($action === 'edit_headmaster') {
        $edit_action = true;
        $uid         = (int)($_POST['user_id'] ?? 0);
        $errors      = validate_headmaster($_POST, editing: true);
        if ($uid <= 0) $errors[] = 'Invalid headmaster.';

        if (empty($errors)) {
            $email    = strtolower(trim((string)($_POST['email'] ?? '')));
            $username = trim((string)($_POST['username'] ?? ''));
            $check    = db()->prepare('SELECT id FROM users WHERE (email=:e OR username=:u) AND id!=:id LIMIT 1');
            $check->execute([':e' => $email, ':u' => $username, ':id' => $uid]);
            if ($check->fetch()) $errors[] = 'Email or username is already used by another account.';
        }

        if (empty($errors)) {
            $sid = (int)($_POST['school_id'] ?? 0);
            $conflict = db()->prepare('SELECT id FROM users WHERE school_id=:s AND role="headmaster" AND status="active" AND id!=:id LIMIT 1');
            $conflict->execute([':s' => $sid, ':id' => $uid]);
            if ($conflict->fetch()) {
                $errors[] = 'This school already has another active headmaster.';
            }
        }

        if (empty($errors)) {
            $password = (string)($_POST['password'] ?? '');
            if ($password !== '') {
                $d = clean_headmaster($_POST);
                $d[':id'] = $uid;
                db()->prepare(
                    'UPDATE users SET school_id=:sid, full_name=:name, email=:email, username=:uname,
                     password_hash=:hash, status=:status WHERE id=:id AND role="headmaster"'
                )->execute($d);
            } else {
                $d = clean_headmaster($_POST, skip_password: true);
                $d[':id'] = $uid;
                db()->prepare(
                    'UPDATE users SET school_id=:sid, full_name=:name, email=:email, username=:uname,
                     status=:status WHERE id=:id AND role="headmaster"'
                )->execute($d);
            }
            flash_set('success', 'Headmaster updated.');
            redirect('district/headmasters.php');
        }
    }
}

// ── Fetch headmasters with school info ─────────────────────────
$headmasters = db()->query(
    'SELECT u.id, u.full_name, u.email, u.username, u.status, u.school_id,
            s.name AS school_name, s.code AS school_code
     FROM users u
     LEFT JOIN schools s ON s.id = u.school_id
     WHERE u.role = "headmaster"
     ORDER BY s.name, u.full_name'
)->fetchAll();

// Schools that currently have NO active headmaster (for quick info)
$assigned_school_ids = array_filter(array_column($headmasters, 'school_id'), fn($id) => $id !== null);

$hm_json = json_encode(array_column($headmasters, null, 'id'), JSON_HEX_TAG);

render_header('Headmasters');
?>

<div class="page-heading">
  <h4>Headmasters <span class="badge bg-secondary ms-1" style="font-size:.7rem"><?= count($headmasters) ?></span></h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAdd">
    + Register Headmaster
  </button>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if (empty($headmasters)): ?>
  <div class="text-center text-muted py-5">No headmasters registered yet.</div>
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
          <th>Assigned School</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($headmasters as $i => $hm): ?>
        <tr>
          <td class="text-muted small"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= e($hm['full_name']) ?></td>
          <td class="small"><?= e($hm['email']) ?></td>
          <td class="small text-muted d-none d-sm-table-cell"><?= e($hm['username']) ?></td>
          <td>
            <?php if ($hm['school_name']): ?>
              <span class="fw-semibold"><?= e($hm['school_name']) ?></span>
              <span class="badge bg-light text-dark border ms-1"><?= e($hm['school_code']) ?></span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Unassigned</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $hm['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
              <?= $hm['status'] === 'active' ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end">
              <button class="btn btn-outline-primary btn-sm btn-edit"
                      data-id="<?= (int)$hm['id'] ?>"
                      data-bs-toggle="modal" data-bs-target="#modalEdit">
                Edit
              </button>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="toggle_status">
                <input type="hidden" name="user_id" value="<?= (int)$hm['id'] ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit"
                        onclick="return confirm('Toggle status for <?= e(addslashes($hm['full_name'])) ?>?')">
                  <?= $hm['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="delete_headmaster">
                <input type="hidden" name="user_id" value="<?= (int)$hm['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit"
                        onclick="return confirm('Delete headmaster <?= e(addslashes($hm['full_name'])) ?>? This cannot be undone.')">
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

<!-- ── Modal: Register Headmaster ─────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_headmaster">
        <div class="modal-header">
          <h5 class="modal-title">Register Headmaster</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= headmaster_form($schools, $_POST, 'add') ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Register</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Edit Headmaster ─────────────────────────────── -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formEdit">
        <?= csrf_field() ?>
        <input type="hidden" name="action"  value="edit_headmaster">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Headmaster</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= headmaster_form($schools, [], 'edit') ?>
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
  const HM = <?= $hm_json ?>;

  // Password show/hide
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

  // Populate edit modal
  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const hm = HM[btn.dataset.id];
      if (!hm) return;
      const f = document.getElementById('formEdit');
      f.querySelector('#editUserId').value          = hm.id;
      f.querySelector('[name="full_name"]').value   = hm.full_name;
      f.querySelector('[name="email"]').value       = hm.email;
      f.querySelector('[name="username"]').value    = hm.username;
      f.querySelector('[name="school_id"]').value   = hm.school_id ?? '';
      f.querySelector('[name="status"]').value      = hm.status;
      f.querySelector('[name="password"]').value    = '';
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
function validate_headmaster(array $post, bool $editing = false): array
{
    $errors   = [];
    $name     = trim((string)($post['full_name'] ?? ''));
    $email    = trim((string)($post['email'] ?? ''));
    $username = trim((string)($post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');
    $school   = (int)($post['school_id'] ?? 0);

    if ($name === '')                                            $errors[] = 'Full name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if ($username === '' || strlen($username) < 3)              $errors[] = 'Username must be at least 3 characters.';
    if (!$editing && strlen($password) < 6)                     $errors[] = 'Password must be at least 6 characters.';
    if ($editing && $password !== '' && strlen($password) < 6)  $errors[] = 'New password must be at least 6 characters.';
    if ($school === 0)                                          $errors[] = 'Please assign a school.';

    return $errors;
}

function clean_headmaster(array $post, bool $skip_password = false): array
{
    $d = [
        ':sid'    => (int)($post['school_id'] ?? 0) ?: null,
        ':name'   => trim((string)($post['full_name'] ?? '')),
        ':email'  => strtolower(trim((string)($post['email'] ?? ''))),
        ':uname'  => trim((string)($post['username'] ?? '')),
        ':status' => in_array($post['status'] ?? '', ['active','inactive']) ? $post['status'] : 'active',
    ];
    if (!$skip_password) {
        $d[':hash'] = password_hash((string)($post['password'] ?? ''), PASSWORD_BCRYPT);
    }
    return $d;
}

function headmaster_form(array $schools, array $post, string $mode): string
{
    $p      = fn(string $k, string $d = '') => e((string)($post[$k] ?? $d));
    $prefix = $mode;

    $school_opts = '<option value="">— Select School —</option>';
    foreach ($schools as $sc) {
        $sel = ((int)($post['school_id'] ?? 0)) === (int)$sc['id'] ? ' selected' : '';
        $school_opts .= '<option value="' . (int)$sc['id'] . '"' . $sel . '>' . e($sc['name']) . '</option>';
    }

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
      <div class="col-md-8">
        <label class="form-label">Assign to School <span class="text-danger">*</span></label>
        <select class="form-select" name="school_id" required>
          {$school_opts}
        </select>
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
