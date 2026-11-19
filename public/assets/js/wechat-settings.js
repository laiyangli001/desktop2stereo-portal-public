const form = document.querySelector('#wechat-settings-form');
const message = document.querySelector('#wechat-message');
const readiness = document.querySelector('#wechat-readiness');
const wechatTr = key => window.D2SI18n.tr(key);
const errors = {
  wechat_setting_invalid: 'wechat.error.wechat_setting_invalid',
  wechat_identity_invalid: 'wechat.error.wechat_identity_invalid',
  wechat_api_v3_key_invalid: 'wechat.error.wechat_api_v3_key_invalid',
  wechat_key_invalid: 'wechat.error.wechat_key_invalid',
  wechat_verification_key_invalid: 'wechat.error.wechat_verification_key_invalid',
  wechat_verification_certificate_invalid: 'wechat.error.wechat_verification_certificate_invalid',
  wechat_verification_key_duplicate: 'wechat.error.wechat_verification_key_duplicate',
  wechat_rotation_material_required: 'wechat.error.wechat_rotation_material_required',
  wechat_callback_url_invalid: 'wechat.error.wechat_callback_url_invalid',
  wechat_configuration_incomplete: 'wechat.error.wechat_configuration_incomplete',
  wechat_checkout_not_integrated: 'wechat.error.wechat_checkout_not_integrated',
  permission_denied: 'wechat.error.permission_denied',
  csrf_invalid: 'wechat.error.csrf_invalid',
  service_unavailable: 'wechat.error.service_unavailable'
};
let pending = false;
form.addEventListener('submit', async event => {
  event.preventDefault();
  if (pending) return;
  pending = true;
  message.textContent = '';
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  form.setAttribute('aria-busy', 'true');
  const values = {};
  const clear = [];
  for (const [name, value] of new FormData(form)) {
    if (name.startsWith('clear__')) clear.push(name.slice(7));
    else values[name] = value;
  }
  const prefix = 'D2S_ADMIN_SESSION_CSRF=';
  const cookie = document.cookie.split('; ').find(value => value.startsWith(prefix));
  try {
    const response = await fetch('/api/v2/admin/settings/wechat', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cookie ? decodeURIComponent(cookie.slice(prefix.length)) : '' },
      body: JSON.stringify({ values, clear_fields: clear })
    });
    const result = await response.json();
    if (!response.ok || !result.success) {
      throw new Error(wechatTr(errors[result.error] || 'wechat.error.generic'));
    }
    for (const [name, present] of Object.entries(result.data.secrets_configured)) {
      form.elements[name].value = '';
      form.elements[name].placeholder = wechatTr(present ? 'wechat.secret.configured' : 'wechat.secret.missing');
    }
    for (const [name, value] of Object.entries(result.data.values)) {
      if (form.elements[name]) form.elements[name].value = value;
    }
    for (const input of form.querySelectorAll('input[type="checkbox"]')) input.checked = false;
    readiness.textContent = wechatTr(result.data.configuration_ready ? 'wechat.status.ready' : 'wechat.status.incomplete');
    message.textContent = wechatTr('wechat.status.saved');
  } catch (error) {
    message.textContent = error.message;
  } finally {
    pending = false;
    button.disabled = false;
    form.removeAttribute('aria-busy');
  }
});
