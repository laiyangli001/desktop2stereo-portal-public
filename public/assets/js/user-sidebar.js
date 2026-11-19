'use strict';

(() => {
  const storageKey = 'd2s-user-menu-groups';
  const sidebar = document.querySelector('#user-sidebar');
  if (!sidebar) return;
  const toggle = document.querySelector('#user-sidebar-toggle');
  const groups = [...sidebar.querySelectorAll('[data-user-nav-group]')];
  let saved = {};
  try {
    const parsed = JSON.parse(localStorage.getItem(storageKey) || '{}');
    if (parsed && typeof parsed === 'object') saved = parsed;
  } catch {
    saved = {};
  }
  const save = () => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(saved));
    } catch {
      // Menu state persistence is optional.
    }
  };

  groups.forEach((group) => {
    const id = group.dataset.userNavGroup || '';
    const open = group.classList.contains('is-active') || saved[id] === true;
    group.classList.toggle('open', open);
    const button = group.querySelector('[data-user-group-toggle]');
    button?.setAttribute('aria-expanded', open ? 'true' : 'false');
    button?.addEventListener('click', () => {
      const next = !group.classList.contains('open');
      group.classList.toggle('open', next);
      button.setAttribute('aria-expanded', next ? 'true' : 'false');
      if (id) saved[id] = next;
      save();
    });
  });

  const setOpen = (open) => {
    sidebar.classList.toggle('is-open', open);
    toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  toggle?.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-open')));
  window.addEventListener('resize', () => {
    if (window.innerWidth > 900) setOpen(false);
  });
  sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    if (window.innerWidth <= 900) setOpen(false);
  }));

  const logoutButton = sidebar.querySelector('#logout');
  const status = sidebar.querySelector('#user-sidebar-status');
  logoutButton?.addEventListener('click', async () => {
    const prefix = 'D2S_USER_SESSION_CSRF=';
    const cookie = document.cookie.split('; ').find((value) => value.startsWith(prefix));
    const csrf = cookie ? decodeURIComponent(cookie.slice(prefix.length)) : '';
    logoutButton.disabled = true;
    if (status) status.textContent = '';
    try {
      const response = await fetch('/api/v2/auth/logout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: '{}',
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.success === false) throw new Error('logout failed');
      window.location.assign(logoutButton.dataset.redirect || '/login');
    } catch {
      if (status) status.textContent = logoutButton.dataset.error || '';
      logoutButton.disabled = false;
    }
  });
})();
