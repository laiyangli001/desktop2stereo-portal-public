const form = document.querySelector('#delivery-settings-form');
const message = document.querySelector('#settings-message');
const section = ['smtp', 'ses', 'sms'].includes(form?.dataset.section) ? form.dataset.section : 'smtp';
const tr = (key, params = {}) => window.D2SI18n.tr('delivery.' + key, params);
let pending = false;
const errorKeys = {
  permission_denied: 'message.error.permission_denied',
  delivery_setting_invalid: 'message.error.delivery_setting_invalid',
  smtp_configuration_invalid: 'message.error.smtp_configuration_invalid',
  ses_configuration_invalid: 'message.error.ses_configuration_invalid',
  ses_template_invalid: 'message.error.ses_template_invalid',
  transaction_template_invalid: 'message.error.transaction_template_invalid',
  sms_configuration_invalid: 'message.error.sms_configuration_invalid',
  verification_rate_limited: 'message.error.verification_rate_limited',
  delivery_test_failed: 'message.error.delivery_test_failed',
  email_delivery_unavailable: 'message.error.email_delivery_unavailable',
  csrf_invalid: 'message.error.csrf_invalid',
  service_unavailable: 'message.error.service_unavailable'
};
function csrf() {
  const item = document.cookie.split('; ').find(value => value.startsWith('D2S_ADMIN_SESSION_CSRF='));
  return item ? decodeURIComponent(item.slice('D2S_ADMIN_SESSION_CSRF='.length)) : '';
}
async function perform(path, payload) {
  const response = await fetch(path, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(), 'X-D2S-Locale': document.documentElement.lang },
    body: JSON.stringify(payload)
  });
  const body = await response.json();
  if (!response.ok || !body.success) throw new Error(tr(errorKeys[body.error] || 'message.error.generic'));
  return body.data;
}
function busy(value) {
  pending = value;
  for (const button of form.querySelectorAll('button')) button.disabled = value;
}
function refreshCoverage(coverage) {
  if (!coverage || !Array.isArray(coverage.locales)) return;
  const names = new Map(coverage.locales.map(locale => [locale.locale, locale.native_name]));
  const fallbackName = names.get(coverage.fallback_locale) || coverage.fallback_locale;
  const description = document.querySelector('[data-coverage-description]');
  if (description) {
    description.dataset.fallbackName = fallbackName;
    description.textContent = tr('coverage.description', { language: fallbackName });
  }
  for (const row of document.querySelectorAll('[data-coverage-locale]')) {
    const locale = coverage.locales.find(item => item.locale === row.dataset.coverageLocale);
    if (!locale) continue;
    for (const cell of row.querySelectorAll('[data-coverage-channel]')) {
      const result = locale.channels?.[cell.dataset.coverageChannel];
      if (!result) continue;
      cell.dataset.status = result.status;
      cell.dataset.effectiveLocale = result.effective_locale || '';
      cell.dataset.effectiveName = result.effective_name || '';
      const messageKey = result.status === 'direct' ? 'coverage.direct' :
        result.status === 'fallback' ? 'coverage.fallback_to' : 'coverage.unavailable';
      cell.textContent = tr(messageKey, { language: result.effective_name || '' });
    }
  }
}
form.addEventListener('submit', async event => {
  event.preventDefault();
  if (pending) return;
  const values = {};
  const clear = [];
  for (const [name, value] of new FormData(form)) {
    if (name.startsWith('clear__')) clear.push(name.slice(7));
    else values[name] = value;
  }
  const driverAction = form.elements['mail-driver-action']?.value || 'keep';
  if (driverAction === 'enable') values.D2S_MAIL_DRIVER = section === 'ses' ? 'ses' : 'smtp';
  if (driverAction === 'disable') values.D2S_MAIL_DRIVER = '';
  busy(true);
  try {
    const result = await perform('/api/v2/admin/settings/delivery', { values, clear_fields: clear });
    for (const [name, configured] of Object.entries(result.secrets_configured)) {
      const input = form.elements.namedItem(name);
      if (!input) continue;
      input.value = '';
      input.placeholder = configured ? tr('secret.configured') : tr('secret.empty');
    }
    for (const checkbox of form.querySelectorAll('input[type="checkbox"]')) checkbox.checked = false;
    refreshCoverage(result.notification_template_coverage);
    message.textContent = section === 'sms' ? tr('message.saved.sms') :
      section === 'ses' ? tr('message.saved.ses') : tr('message.saved.smtp');
  } catch (error) {
    message.textContent = error.message;
  } finally {
    busy(false);
  }
});
document.querySelector('#delivery-test')?.addEventListener('click', async () => {
  if (pending) return;
  busy(true);
  try {
    await perform('/api/v2/admin/settings/delivery/test', {});
    message.textContent = tr('message.test_sent');
  } catch (error) {
    message.textContent = error.message;
  } finally {
    busy(false);
  }
});
