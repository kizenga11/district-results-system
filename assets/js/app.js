(() => {
  'use strict';

  // ── Sidebar toggle (mobile) ──────────────────────────────────
  const sidebar  = document.getElementById('sidebar');
  const overlay  = document.getElementById('sidebarOverlay');
  const toggler  = document.getElementById('sidebarToggle');

  function openSidebar()  { sidebar?.classList.add('open');  overlay?.classList.add('open'); }
  function closeSidebar() { sidebar?.classList.remove('open'); overlay?.classList.remove('open'); }

  toggler?.addEventListener('click', () => sidebar?.classList.contains('open') ? closeSidebar() : openSidebar());
  overlay?.addEventListener('click', closeSidebar);

  // Close on resize to desktop
  window.addEventListener('resize', () => { if (window.innerWidth >= 992) closeSidebar(); });

  // ── Mark active sidebar link + auto-expand parent group ─────
  const currentPath = window.location.pathname.replace(/\\/g, '/');

  document.querySelectorAll('.sidebar-link, .sidebar-sublink').forEach(link => {
    const href = (link.getAttribute('href') ?? '').replace(/\\/g, '/');
    if (!href) return;
    const tail = href.replace(/^.*\/iramba-rms\//, '').replace(/^\//, '');
    if (currentPath.endsWith('/' + tail) || currentPath.endsWith(href)) {
      link.classList.add('current');
      // Auto-expand parent collapse group
      const body = link.closest('.sidebar-group-body');
      if (body) {
        body.classList.add('show');
        const toggle = body.previousElementSibling;
        if (toggle) toggle.classList.add('active');
      }
    }
  });

  // Keep toggle state in sync with Bootstrap collapse events
  document.querySelectorAll('.sidebar-group-toggle').forEach(btn => {
    const targetId = btn.getAttribute('data-bs-target');
    const body = targetId ? document.querySelector(targetId) : null;
    if (!body) return;
    body.addEventListener('show.bs.collapse',  () => btn.classList.add('active'));
    body.addEventListener('hide.bs.collapse',  () => btn.classList.remove('active'));
  });

  // Close sidebar on sub-link click (mobile)
  document.querySelectorAll('.sidebar-sublink').forEach(link => {
    link.addEventListener('click', () => { if (window.innerWidth < 992) closeSidebar(); });
  });

  // ── Toast notification system ─────────────────────────────────
  const TOAST_ICONS = {
    success: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>',
    error:   '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"/></svg>',
    warning: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M7.938 2.016A.13.13 0 0 1 8.002 2a.13.13 0 0 1 .063.016.15.15 0 0 1 .054.057l6.857 11.667c.036.06.035.124.002.183a.2.2 0 0 1-.054.06.1.1 0 0 1-.066.017H1.146a.1.1 0 0 1-.066-.017.2.2 0 0 1-.054-.06.18.18 0 0 1 .002-.183L7.884 2.073a.15.15 0 0 1 .054-.057m1.044-.45a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767z"/><path d="M7.002 12a1 1 0 1 1 2 0 1 1 0 0 1-2 0M7.1 5.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0z"/></svg>',
    info:    '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/></svg>',
  };
  const TOAST_TITLES = { success: 'Success', error: 'Error', warning: 'Warning', info: 'Information' };

  function dismissToast(el) {
    el.classList.add('hiding');
    el.addEventListener('animationend', () => el.remove(), { once: true });
  }

  function initToast(el) {
    const dur = parseInt(el.dataset.dur || '4500');
    const timer = setTimeout(() => dismissToast(el), dur);
    el.querySelector('.toast-close')?.addEventListener('click', () => {
      clearTimeout(timer);
      dismissToast(el);
    });
  }

  document.querySelectorAll('.toast-item').forEach(initToast);

  window.showToast = function(message, type = 'info', title, duration = 4500) {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
    const t = document.createElement('div');
    t.className = `toast-item toast-${type}`;
    t.dataset.dur = String(duration);
    t.style.setProperty('--toast-dur', duration + 'ms');
    t.innerHTML =
      `<div class="toast-icon">${TOAST_ICONS[type] || TOAST_ICONS.info}</div>` +
      `<div class="toast-body"><div class="toast-title">${title || TOAST_TITLES[type] || 'Notice'}</div>` +
      `<div class="toast-msg">${message}</div></div>` +
      `<button class="toast-close" aria-label="Close">&times;</button>` +
      `<div class="toast-progress"></div>`;
    container.appendChild(t);
    initToast(t);
  };

  // ── Password visibility toggle ────────────────────────────────
  document.querySelectorAll('.toggle-pass').forEach(btn => {
    btn.addEventListener('click', () => {
      const inp = document.getElementById(btn.getAttribute('data-target'));
      if (!inp) return;
      inp.type = inp.type === 'password' ? 'text' : 'password';
      btn.querySelectorAll('svg').forEach(s => s.classList.toggle('d-none'));
    });
  });
})();
