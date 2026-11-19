'use strict';

(() => {
  const storageKey = 'd2s-admin-menu-groups';
  const body = document.body;
  const sidebar = document.querySelector('#admin-sidebar');
  const toggle = document.querySelector('#admin-sidebar-toggle');
  const backdrop = document.querySelector('#admin-sidebar-backdrop');
  const groups = [...document.querySelectorAll('[data-admin-nav-group]')];

  const readGroups = () => {
    try {
      const value = JSON.parse(localStorage.getItem(storageKey) || '{}');
      return value && typeof value === 'object' ? value : {};
    } catch {
      return {};
    }
  };
  const groupState = readGroups();
  const saveGroups = () => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(groupState));
    } catch {
      // Menu state persistence is optional.
    }
  };

  groups.forEach((group) => {
    const id = group.dataset.adminNavGroup || '';
    const active = group.classList.contains('is-active');
    const open = active || groupState[id] === true;
    group.classList.toggle('open', open);
    const button = group.querySelector('[data-admin-group-toggle]');
    button?.setAttribute('aria-expanded', open ? 'true' : 'false');
    button?.addEventListener('click', () => {
      const next = !group.classList.contains('open');
      group.classList.toggle('open', next);
      button.setAttribute('aria-expanded', next ? 'true' : 'false');
      if (id) groupState[id] = next;
      saveGroups();
    });
  });

  const setSidebarOpen = (open) => {
    body.classList.toggle('admin-sidebar-open', open);
    toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  toggle?.addEventListener('click', () => setSidebarOpen(!body.classList.contains('admin-sidebar-open')));
  backdrop?.addEventListener('click', () => setSidebarOpen(false));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setSidebarOpen(false);
  });
  window.addEventListener('resize', () => {
    if (window.innerWidth > 1023) setSidebarOpen(false);
  });
  sidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    if (window.innerWidth <= 1023) setSidebarOpen(false);
  }));

  document.querySelector('#admin-logout')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const prefix = 'D2S_ADMIN_SESSION_CSRF=';
    const cookie = document.cookie.split('; ').find((value) => value.startsWith(prefix));
    const csrf = cookie ? decodeURIComponent(cookie.slice(prefix.length)) : '';
    button.disabled = true;
    try {
      await fetch('/api/v2/admin/logout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: '{}',
      });
    } finally {
      window.location.assign(button.dataset.redirect || '/admin/login');
    }
  });
})();
