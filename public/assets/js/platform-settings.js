'use strict';

(() => {
  const form = document.querySelector('#platform-settings-form');
  const message = document.querySelector('#platform-message');
  const purgeButton = document.querySelector('#platform-cdn-purge');
  if (!form) return;
  let pending = false;

  const tr = (key, parameters = {}) => window.D2SI18n.tr(key, parameters);
  const errorKeys = {
    permission_denied: 'platform.message.error.permission_denied',
    administrator_inactive: 'platform.message.error.session',
    csrf_invalid: 'platform.message.error.session',
    origin_denied: 'platform.message.error.session',
    platform_setting_invalid: 'platform.message.error.invalid',
    platform_template_invalid: 'platform.message.error.invalid',
    platform_theme_invalid: 'platform.message.error.invalid',
    platform_captcha_enabled_invalid: 'platform.message.error.invalid',
    platform_captcha_targets_invalid: 'platform.message.error.captcha_targets',
    platform_captcha_ttl_invalid: 'platform.message.error.captcha_ttl',
    platform_captcha_tolerance_invalid: 'platform.message.error.captcha_tolerance',
    platform_cache_ttl_invalid: 'platform.message.error.cache_ttl',
    platform_seo_index_invalid: 'platform.message.error.invalid',
    platform_seo_sitemap_invalid: 'platform.message.error.invalid',
    service_unavailable: 'platform.message.error.service',
  };

  function csrf() {
    const prefix = 'D2S_ADMIN_SESSION_CSRF=';
    const item = document.cookie.split('; ').find(value => value.startsWith(prefix));
    return item ? decodeURIComponent(item.slice(prefix.length)) : '';
  }

  async function request(path, payload) {
    const response = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf(),
        'X-D2S-Locale': document.documentElement.lang,
      },
      body: JSON.stringify(payload),
    });
    const body = await response.json();
    if (!response.ok || !body.success) {
      throw new Error(tr(errorKeys[body.error] || 'platform.message.error.generic'));
    }
    return body.data;
  }

  function busy(value) {
    pending = value;
    form.setAttribute('aria-busy', String(value));
    form.querySelectorAll('button, input, select').forEach(control => { control.disabled = value; });
    if (purgeButton) purgeButton.disabled = value;
  }

  function applyValues(values) {
    for (const [name, value] of Object.entries(values)) {
      const control = form.elements.namedItem(name);
      if (!control) continue;
      if (control instanceof HTMLInputElement && control.type === 'checkbox') control.checked = Boolean(value);
      else control.value = String(value);
    }
  }

  function applyCdn(cdn) {
    const badge = document.querySelector('[data-cdn-state]');
    if (badge) {
      badge.textContent = cdn.enabled ? tr('platform.status.enabled') : tr('platform.status.disabled');
      badge.classList.toggle('muted', !cdn.enabled);
    }
    const configuration = document.querySelector('[data-cdn-configuration]');
    if (configuration) configuration.textContent = cdn.configuration_ready ? tr('platform.status.ready') : tr('platform.status.incomplete');
    for (const [status, count] of Object.entries(cdn.counts || {})) {
      const target = document.querySelector('[data-cdn-count="' + status + '"]');
      if (target) target.textContent = String(count);
    }
    const lastError = document.querySelector('[data-cdn-last-error]');
    if (lastError) lastError.textContent = cdn.last_error || tr('platform.status.none');
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (pending || !form.reportValidity()) return;
    const values = {};
    for (const control of form.elements) {
      if (!control.name) continue;
      if (control instanceof HTMLInputElement && control.type === 'checkbox') values[control.name] = control.checked;
      else if (control instanceof HTMLInputElement && control.type === 'number') values[control.name] = Number(control.value);
      else values[control.name] = control.value;
    }
    busy(true);
    try {
      const data = await request('/api/v2/admin/settings/platform', { values });
      applyValues(data.values || values);
      applyCdn(data.cdn || {});

      message.textContent = tr('platform.message.saved');
    } catch (error) {
      message.textContent = error.message;
    } finally {
      busy(false);
    }
  });

  purgeButton?.addEventListener('click', async () => {
    if (pending) return;
    busy(true);
    try {
      const data = await request('/api/v2/admin/settings/platform/cdn-purge', {});
      applyCdn(data.cdn || {});
      message.textContent = tr('platform.message.cdn_queued', { count: Number(data.queued || 0) });
    } catch (error) {
      message.textContent = error.message;
    } finally {
      busy(false);
    }
  });
})();
