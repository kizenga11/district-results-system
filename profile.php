<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user    = current_user();
$user_id = (int)$user['id'];
$errors  = [];

$db_user = db()->prepare(
    'SELECT id, full_name, email, username, role, school_id, status, created_at FROM users WHERE id=:id LIMIT 1'
);
$db_user->execute([':id' => $user_id]);
$db_user = $db_user->fetch();

$school_name = '';
if (!empty($db_user['school_id'])) {
    $sc = db()->prepare('SELECT name FROM schools WHERE id=:id LIMIT 1');
    $sc->execute([':id' => $db_user['school_id']]);
    $school_name = (string)($sc->fetchColumn() ?: '');
}

if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email     = trim((string)($_POST['email'] ?? ''));

        if ($full_name === '') $errors[] = 'Full name is required.';
        if ($email === '')     $errors[] = 'Email is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is not valid.';
        }
        if (empty($errors)) {
            $dup = db()->prepare('SELECT id FROM users WHERE email=:e AND id!=:id LIMIT 1');
            $dup->execute([':e' => $email, ':id' => $user_id]);
            if ($dup->fetch()) $errors[] = 'This email is already in use by another user.';
        }
        if (empty($errors)) {
            db()->prepare('UPDATE users SET full_name=:n, email=:e WHERE id=:id')
                ->execute([':n' => $full_name, ':e' => $email, ':id' => $user_id]);
            $_SESSION['user']['full_name'] = $full_name;
            $_SESSION['user']['email']     = $email;
            flash_set('success', 'Your information has been saved.');
            redirect('profile.php');
        }
        $db_user['full_name'] = $full_name;
        $db_user['email']     = $email;
    }

    if ($action === 'change_password') {
        $old_pass  = (string)($_POST['old_password']      ?? '');
        $new_pass  = (string)($_POST['new_password']      ?? '');
        $conf_pass = (string)($_POST['confirm_password']  ?? '');

        if ($old_pass === '')          $errors[] = 'Current password is required.';
        if (strlen($new_pass) < 8)     $errors[] = 'New password must be at least 8 characters.';
        if ($new_pass !== $conf_pass)  $errors[] = 'New passwords do not match.';

        if (empty($errors)) {
            $ph = db()->prepare('SELECT password_hash FROM users WHERE id=:id LIMIT 1');
            $ph->execute([':id' => $user_id]);
            if (!password_verify($old_pass, (string)$ph->fetchColumn())) {
                $errors[] = 'Current password is incorrect.';
            }
        }
        if (empty($errors)) {
            db()->prepare('UPDATE users SET password_hash=:h WHERE id=:id')
                ->execute([':h' => password_hash($new_pass, PASSWORD_BCRYPT), ':id' => $user_id]);
            flash_set('success', 'Password changed. Please log in again.');
            redirect('profile.php');
        }
    }
}

$role_labels = [
    'super_admin'    => 'Super Admin',
    'district_admin' => 'District Admin',
    'headmaster'     => 'Headmaster',
    'teacher'        => 'Teacher',
];

render_header('My Profile');
?>

<div class="page-heading">
  <h4>My Profile</h4>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-3">
  <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">

  <div class="col-md-4">
    <div class="card">
      <div class="card-body text-center py-4">
        <div class="profile-avatar-large mx-auto">
          <?= e(strtoupper(mb_substr($db_user['full_name'], 0, 1))) ?>
        </div>
        <h5 class="fw-bold mb-1 mt-2"><?= e($db_user['full_name']) ?></h5>
        <div class="text-muted small mb-2">
          <?= e($role_labels[$db_user['role']] ?? $db_user['role']) ?>
        </div>
        <?php if ($school_name): ?>
          <span class="badge bg-light text-dark border"><?= e($school_name) ?></span>
        <?php endif; ?>
        <hr class="my-3">
        <div class="text-start small ps-2">
          <div class="mb-2">
            <span class="text-muted">Email:</span><br>
            <strong><?= e($db_user['email']) ?></strong>
          </div>
          <div class="mb-2">
            <span class="text-muted">Username:</span><br>
            <strong><?= e($db_user['username']) ?></strong>
          </div>
          <div class="mb-2">
            <span class="text-muted">Account Status:</span><br>
            <span class="badge <?= $db_user['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
              <?= $db_user['status'] === 'active' ? 'Active' : 'Disabled' ?>
            </span>
          </div>
          <div>
            <span class="text-muted">Registered:</span><br>
            <?= e(date('d M Y', strtotime($db_user['created_at']))) ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-8">

    <div class="card mb-4">
      <div class="card-header">Edit Basic Information</div>
      <div class="card-body">
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input class="form-control" name="full_name" required
                     value="<?= e((string)($_POST['full_name'] ?? $db_user['full_name'])) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Email <span class="text-danger">*</span></label>
              <input class="form-control" name="email" type="email" required
                     value="<?= e((string)($_POST['email'] ?? $db_user['email'])) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Username</label>
              <input class="form-control bg-light" value="<?= e($db_user['username']) ?>" disabled>
              <div class="form-text">Username cannot be changed. Contact an administrator if you need to change it.</div>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Change Password</div>
      <div class="card-body">
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Current Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input class="form-control" name="old_password" type="password" id="oldPass"
                       required autocomplete="current-password">
                <button class="btn btn-outline-secondary toggle-pass" type="button" data-target="oldPass" tabindex="-1">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="d-none"><path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/></svg>
                </button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">New Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input class="form-control" name="new_password" type="password" id="newPass"
                       required autocomplete="new-password" minlength="8">
                <button class="btn btn-outline-secondary toggle-pass" type="button" data-target="newPass" tabindex="-1">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="d-none"><path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/></svg>
                </button>
              </div>
              <div class="form-text">At least 8 characters.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input class="form-control" name="confirm_password" type="password" id="confPass"
                       required autocomplete="new-password" minlength="8">
                <button class="btn btn-outline-secondary toggle-pass" type="button" data-target="confPass" tabindex="-1">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="d-none"><path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/></svg>
                </button>
              </div>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-outline-danger btn-sm">Change Password</button>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<?php render_footer(); ?>
