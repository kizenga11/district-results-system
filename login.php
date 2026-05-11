<?php

declare(strict_types=1);

// Temporarily enable error display for login page debugging.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/layout.php';

start_app_session();

if (current_user()) {
    redirect('dashboard.php');
}

if (is_post()) {
    csrf_verify();

    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        flash_set('error', 'Please enter your email and password.');
    } else {
        if (auth_login($email, $password)) {
            redirect('dashboard.php');
        }
        flash_set('error', 'Invalid email or password, or account is inactive.');
    }
}

// Pre-read flash so render_header won't output them (we show inside card)
$login_error   = flash_get('error');
$login_success = flash_get('success');

render_header('Login');
?>
<div class="auth-page">

  <!-- ── Brand panel (desktop only) ─────────────────────── -->
  <div class="auth-brand-panel">
    <div class="auth-brand-inner">

      <div class="auth-brand-chip">
        <span class="auth-brand-chip-dot"></span>
        School Management System
      </div>

      <h1 class="auth-brand-title"><?= e(APP_FULLNAME) ?></h1>

      <p class="auth-brand-desc">
        A centralised platform for tracking student results, teacher
        performance and teaching progress across all secondary schools
        in Iramba District.
      </p>

      <div class="auth-feature-list">
        <div class="auth-feature-item">
          <span class="auth-feature-check">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
              <path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425z"/>
            </svg>
          </span>
          Student &amp; Teacher Management
        </div>
        <div class="auth-feature-item">
          <span class="auth-feature-check">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
              <path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425z"/>
            </svg>
          </span>
          Teaching Progress Tracking
        </div>
        <div class="auth-feature-item">
          <span class="auth-feature-check">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
              <path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425z"/>
            </svg>
          </span>
          Exam Results &amp; Analysis Reports
        </div>
        <div class="auth-feature-item">
          <span class="auth-feature-check">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
              <path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425z"/>
            </svg>
          </span>
          Secure Role-Based Access Control
        </div>
      </div>

    </div>
  </div>

  <!-- ── Form panel ──────────────────────────────────────── -->
  <div class="auth-form-panel">
    <div class="auth-form-inner">

      <!-- Logo mark -->
      <div class="auth-form-logo">IR</div>

      <h2 class="auth-form-title">Welcome back</h2>
      <p class="auth-form-subtitle">Sign in to your account to continue</p>

      <?php if ($login_error): ?>
      <div class="auth-alert auth-alert-error">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16" style="flex-shrink:0;margin-top:1px">
          <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
          <path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0M7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0z"/>
        </svg>
        <?= e($login_error) ?>
      </div>
      <?php endif; ?>

      <?php if ($login_success): ?>
      <div class="auth-alert auth-alert-success">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16" style="flex-shrink:0;margin-top:1px">
          <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
          <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05"/>
        </svg>
        <?= e($login_success) ?>
      </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <?= csrf_field() ?>

        <div class="auth-field">
          <label class="auth-label" for="email">Email Address</label>
          <input
            class="auth-input"
            type="email"
            id="email"
            name="email"
            required
            autofocus
            autocomplete="email"
            placeholder="name@example.go.tz"
          >
        </div>

        <div class="auth-field">
          <label class="auth-label" for="password">Password</label>
          <div class="auth-input-wrap">
            <input
              class="auth-input"
              type="password"
              id="password"
              name="password"
              required
              autocomplete="current-password"
              placeholder="••••••••"
            >
            <button class="auth-eye-btn toggle-pass" type="button" data-target="password" tabindex="-1" title="Show/hide password">
              <!-- eye open -->
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
              </svg>
              <!-- eye slash (hidden initially) -->
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="d-none">
                <path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7 7 0 0 0-2.79.588l.77.771A6 6 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755q-.247.248-.517.486z"/>
                <path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829"/>
                <path d="M3.35 5.47q-.27.24-.518.487A13 13 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7 7 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12z"/>
              </svg>
            </button>
          </div>
        </div>

        <button class="auth-submit" type="submit">
          Sign In
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8"/>
          </svg>
        </button>
      </form>

      <p class="auth-help-text">
        Don't have an account? Contact your district administrator.
      </p>

    </div>
  </div>

</div>
<?php render_footer(); ?>
