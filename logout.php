<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

start_app_session();

if (is_post()) {
    csrf_verify();
    auth_logout();
    redirect('login.php');
}

// GET request — show confirmation page
render_header('Logout');
?>
<div class="auth-wrap">
  <div class="auth-card card shadow-sm">
    <div class="card-body p-4 text-center">
      <div class="mb-3">
        <div class="auth-title"><?= e(APP_NAME) ?></div>
        <div class="auth-subtitle">Are you sure you want to log out?</div>
      </div>
      <form method="post">
        <?= csrf_field() ?>
        <button class="btn btn-primary w-100" type="submit">Yes, Log Out</button>
      </form>
      <div class="mt-2">
        <a href="<?= e(url('dashboard.php')) ?>" class="btn btn-outline-secondary w-100">Cancel</a>
      </div>
    </div>
  </div>
</div>
<?php
render_footer();
