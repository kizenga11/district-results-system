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

  // ── Auto-dismiss flash toasts ─────────────────────────────────
  document.querySelectorAll('.flash-toast').forEach(el => {
    setTimeout(() => el.remove(), 4200);
  });

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
