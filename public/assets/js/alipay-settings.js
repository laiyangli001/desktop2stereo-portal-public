const form = document.querySelector('#alipay-settings-form');
const message = document.querySelector('#alipay-message');
let pending = false;
const alipayTr = key => window.D2SI18n.tr(key);
const errors = {
  alipay_configuration_incomplete: 'alipay.error.alipay_configuration_incomplete',
  alipay_key_invalid: 'alipay.error.alipay_key_invalid',
  alipay_identity_invalid: 'alipay.error.alipay_identity_invalid',
  alipay_callback_url_invalid: 'alipay.error.alipay_callback_url_invalid',
  alipay_setting_invalid: 'alipay.error.alipay_setting_invalid',
  permission_denied: 'alipay.error.permission_denied',
  csrf_invalid: 'alipay.error.csrf_invalid',
  service_unavailable: 'alipay.error.service_unavailable'
};
form.addEventListener('submit', async event => {
  event.preventDefault();
  if (pending) return;
  pending = true;
  const button = form.querySelector('button');
  button.disabled = true;
  const values = {};
  const clear = [];
  for (const [name, value] of new FormData(form)) {
    if (name.startsWith('clear__')) clear.push(name.slice(7));
    else values[name] = value;
  }
  const prefix = 'D2S_ADMIN_SESSION_CSRF=';
  const cookie = document.cookie.split('; ').find(value => value.startsWith(prefix));
  try {
    const response = await fetch('/api/v2/admin/settings/alipay', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cookie ? decodeURIComponent(cookie.slice(prefix.length)) : '' },
      body: JSON.stringify({ values, clear_fields: clear })
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(alipayTr(errors[result.error] || 'alipay.error.generic'));
    for (const [name, present] of Object.entries(result.data.secrets_configured)) {
      form.elements[name].value = '';
      form.elements[name].placeholder = alipayTr(present ? 'alipay.secret.configured' : 'alipay.secret.missing');
    }
    for (const input of form.querySelectorAll('input[type="checkbox"]')) input.checked = false;
    message.textContent = alipayTr('alipay.status.saved');
  } catch (error) {
    message.textContent = error.message;
  } finally {
    pending = false;
    button.disabled = false;
  }
});
