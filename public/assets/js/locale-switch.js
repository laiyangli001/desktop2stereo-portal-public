'use strict';
(() => {
  const cookie = name => {
    const prefix = name + '=';
    const item = document.cookie.split('; ').find(value => value.startsWith(prefix));
    return item ? decodeURIComponent(item.slice(prefix.length)) : '';
  };
  const persistPreference = async locale => {
    const path = window.location.pathname;
    const adminCsrf = cookie('D2S_ADMIN_SESSION_CSRF');
    const userCsrf = cookie('D2S_USER_SESSION_CSRF');
    const adminPage = /^\/admin(?:\/|$)/.test(path);
    const realm = adminPage && adminCsrf ? 'admin' : (userCsrf ? 'user' : (adminCsrf ? 'admin' : ''));
    if (!realm) return;
    const csrf = realm === 'admin' ? adminCsrf : userCsrf;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 1500);
    try {
      await fetch(realm === 'admin' ? '/api/v2/admin/locale' : '/api/v2/me/locale', {
        method: 'POST',
        credentials: 'same-origin',
        signal: controller.signal,
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
          'X-D2S-Locale': locale,
        },
        body: JSON.stringify({ locale }),
      });
    } catch {
      // Keep locale switching responsive if the session expired or the preference endpoint is unavailable.
    } finally {
      window.clearTimeout(timeout);
    }
  };
  for (const picker of document.querySelectorAll('[data-locale-picker]')) {
    const select = picker.querySelector('[data-locale-switch]');
    const toggle = picker.querySelector('[data-locale-toggle]');
    const menu = picker.querySelector('[data-locale-menu]');
    if (!select || !toggle || !menu) continue;
    const options = [...menu.querySelectorAll('[data-locale-option]')];
    if (!options.length) continue;

    const selectedOption = () => options.find(option => option.getAttribute('aria-selected') === 'true') || options[0];
    const close = (restoreFocus = false) => {
      menu.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      if (restoreFocus) toggle.focus();
    };
    const open = (focusSelected = false) => {
      menu.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      if (focusSelected) selectedOption().focus();
    };
    const choose = option => {
      const locale = option.dataset.locale;
      const value = option.dataset.value;
      if (!locale || !value) return;
      options.forEach(item => item.setAttribute('aria-selected', item === option ? 'true' : 'false'));
      const currentName = toggle.querySelector('[data-locale-current-name]');
      if (currentName) currentName.textContent = option.querySelector('span:not(.locale-globe)')?.textContent?.trim() || '';
      close();
      select.value = value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    toggle.hidden = false;
    select.setAttribute('aria-hidden', 'true');
    select.tabIndex = -1;
    picker.dataset.enhanced = 'true';
    toggle.addEventListener('click', () => {
      if (menu.hidden) open();
      else close();
    });
    toggle.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        open(true);
      }
    });
    menu.addEventListener('click', event => {
      const option = event.target.closest('[data-locale-option]');
      if (option) choose(option);
    });
    menu.addEventListener('keydown', event => {
      const index = options.indexOf(document.activeElement);
      if (event.key === 'Escape') {
        event.preventDefault();
        close(true);
      } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        options[(index + step + options.length) % options.length].focus();
      } else if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        options[event.key === 'Home' ? 0 : options.length - 1].focus();
      }
    });
    document.addEventListener('pointerdown', event => {
      if (!picker.contains(event.target)) close();
    });
    select.addEventListener('change', () => {
      const target = new URL(select.value, window.location.href);
      if (target.origin !== window.location.origin || !['http:', 'https:'].includes(target.protocol)) return;
      const locale = select.selectedOptions[0]?.dataset.locale;
      if (!locale) return;
      document.cookie = 'D2S_LOCALE=' + encodeURIComponent(locale) + '; Path=/; Max-Age=31536000; SameSite=Lax' +
        (window.location.protocol === 'https:' ? '; Secure' : '');
      target.hash = window.location.hash;
      void persistPreference(locale).finally(() => window.location.assign(target.href));
    });
  }
})();
