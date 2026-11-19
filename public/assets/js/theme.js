'use strict';

(() => {
  const storageKey = 'd2s-color-theme';
  const root = document.documentElement;
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  const validTheme = (value) => value === 'light' || value === 'dark';
  const validDefaultTheme = (value) => ['system', 'light', 'dark'].includes(value) ? value : 'system';
  const platformSource = document.currentScript || document.querySelector('script[data-default-theme]');
  const platformButton = document.querySelector('button[data-default-theme]');
  const defaultTheme = validDefaultTheme(platformSource?.dataset.defaultTheme || platformButton?.dataset.defaultTheme);
  root.dataset.template = (platformSource?.dataset.platformTemplate || platformButton?.dataset.platformTemplate) === 'compact' ? 'compact' : 'classic';
  const readSavedTheme = () => {
    try {
      const value = window.localStorage.getItem(storageKey);
      return validTheme(value) ? value : null;
    } catch {
      return null;
    }
  };
  let savedTheme = readSavedTheme();
  let activeTheme = savedTheme || (defaultTheme === 'system' ? (media.matches ? 'dark' : 'light') : defaultTheme);

  const applyTheme = (theme, persist) => {
    activeTheme = validTheme(theme) ? theme : activeTheme;
    root.dataset.theme = activeTheme;
    root.style.colorScheme = activeTheme;
    if (persist) {
      try {
        window.localStorage.setItem(storageKey, activeTheme);
      } catch {
        // Storage can be unavailable in strict privacy modes.
      }
    }
    updateControls();
  };

  const updateControls = () => {
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
      const nextTheme = activeTheme === 'dark' ? 'light' : 'dark';
      const label = nextTheme === 'dark' ? button.dataset.labelDark || '' : button.dataset.labelLight || '';
      const visible = button.querySelector('[data-theme-label]');
      if (visible) {
        visible.textContent = nextTheme === 'dark' ? button.dataset.textDark || '' : button.dataset.textLight || '';
      }
      button.setAttribute('aria-label', label);
      button.setAttribute('title', label);
      button.setAttribute('aria-pressed', activeTheme === 'dark' ? 'true' : 'false');
    });
  };

  applyTheme(activeTheme, false);

  const bindControls = () => {
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
      button.addEventListener('click', () => {
        savedTheme = activeTheme === 'dark' ? 'light' : 'dark';
        applyTheme(savedTheme, true);
      });
    });
    updateControls();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindControls, { once: true });
  } else {
    bindControls();
  }

  media.addEventListener('change', (event) => {
    if (savedTheme !== null || defaultTheme !== 'system') return;
    applyTheme(event.matches ? 'dark' : 'light', false);
  });
})();
