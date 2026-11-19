'use strict';
(() => {
  const form = document.querySelector('#install-form');
  if (!form) return;
  const tr = (key, params = {}) => window.D2SI18n.tr('installation.' + key, params);
  const output = document.querySelector('#install-message');
  const run = document.querySelector('#install-run');
  const test = document.querySelector('#install-test');
  const errorKeys = {
    installation_token_invalid: 'error.token_invalid',
    installation_locked: 'error.locked',
    database_connection_failed: 'error.database_connection',
    installation_database_not_empty: 'error.database_not_empty',
    installation_local_database_required: 'error.local_database_required',
    database_configuration_invalid: 'error.database_configuration',
    password_policy: 'error.password_policy',
    password_confirmation_invalid: 'error.password_confirmation',
    installation_busy: 'error.busy',
    installation_environment_failed: 'error.environment_failed',
    installation_url_invalid: 'error.url_invalid',
    installation_runtime_verification_failed: 'error.runtime_verification',
    installation_configuration_exists: 'error.configuration_exists',
    installation_configuration_write_failed: 'error.configuration_write',
    installation_lock_write_failed: 'error.lock_write',
  };
  const environmentKeys = {
    'PHP 8.4': 'environment.php',
    pdo_mysql: 'environment.extension.pdo_mysql',
    openssl: 'environment.extension.openssl',
    sodium: 'environment.extension.sodium',
    mbstring: 'environment.extension.mbstring',
    curl: 'environment.extension.curl',
    gd: 'environment.extension.gd',
    fileinfo: 'environment.extension.fileinfo',
    json: 'environment.extension.json',
    dom: 'environment.extension.dom',
    intl: 'environment.extension.intl',
    storage: 'environment.storage',
    'Composer dependencies': 'environment.composer',
  };
  const currentKeys = {
    enabled: 'environment.current.enabled',
    missing: 'environment.current.missing',
    writable: 'environment.current.writable',
    'not writable': 'environment.current.not_writable',
    present: 'environment.current.present',
  };
  const errorMessage = (code, fallback = '') => {
    const key = errorKeys[code];
    return key ? tr(key) : tr('error.unexpected', {code: code || fallback || 'unknown'});
  };
  form.elements.app_url.value = location.origin;
  async function request(path, body) {
    const response = await fetch('/api/v2/install/' + path, body ? {
      method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body),
    } : {cache: 'no-store'});
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(errorMessage(payload.error, String(response.status)));
    return payload.data;
  }
  async function environment() {
    const data = await request('environment');
    const target = document.querySelector('#install-environment'); target.replaceChildren();
    data.items.forEach(item => {
      const row = document.createElement('p');
      const name = environmentKeys[item.name] ? tr(environmentKeys[item.name]) : item.name;
      const current = currentKeys[item.current] ? tr(currentKeys[item.current]) : item.current;
      row.textContent = (item.ok ? '✓ ' : '× ') + name + ' · ' + current;
      target.append(row);
    });
    run.disabled = !data.pass || data.installed;
  }
  test.addEventListener('click', async () => {
    test.disabled = true;
    try { await request('database', Object.fromEntries(new FormData(form))); output.textContent = tr('status.database_connected'); }
    catch (error) { output.textContent = error.message; } finally { test.disabled = false; }
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!form.reportValidity() || run.disabled) return;
    run.disabled = true; test.disabled = true;
    output.textContent = tr('status.installing');
    try {
      const data = await request('run', Object.fromEntries(new FormData(form)));
      output.replaceChildren(document.createTextNode(tr('status.complete') + ' '));
      const link = document.createElement('a'); link.href = data.login_url; link.textContent = tr('status.sign_in'); output.append(link);
      for (const input of form.elements) input.disabled = true;
    } catch (error) { output.textContent = error.message; run.disabled = false; test.disabled = false; }
  });
  document.querySelector('#install-recheck').addEventListener('click', () => environment().catch(() => { output.textContent = tr('status.environment_unavailable'); }));
  environment().catch(() => { output.textContent = tr('status.environment_unavailable'); });
})();
