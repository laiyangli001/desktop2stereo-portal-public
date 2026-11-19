'use strict';

const accountTr = (key, parameters) => window.D2SI18n.tr(key, parameters);
const messages = {
  verification_invalid: accountTr('accounts.errors.verification_invalid'),
  verification_rate_limited: accountTr('accounts.errors.verification_rate_limited'),
  phone_registration_unavailable: accountTr('accounts.errors.phone_registration_unavailable'),
  contact_binding_unavailable: accountTr('accounts.errors.phone_registration_unavailable'),
  email_delivery_unavailable: accountTr('accounts.errors.email_delivery_unavailable'),
  account_or_contact_exists: accountTr('accounts.errors.account_or_contact_exists'),
  invalid_credentials: accountTr('accounts.errors.invalid_credentials'),
  login_temporarily_locked: accountTr('accounts.errors.login_temporarily_locked'),
  account_restricted_or_unverified: accountTr('accounts.errors.account_restricted_or_unverified'),
  password_policy: accountTr('accounts.errors.password_policy'),
  password_unchanged: accountTr('accounts.errors.password_unchanged'),
  legal_consent_required: accountTr('accounts.errors.legal_consent_required'),
  legal_policy_unavailable: accountTr('accounts.errors.legal_policy_unavailable'),
  display_name_invalid: accountTr('accounts.errors.display_name_invalid'),
  region_invalid: accountTr('accounts.errors.region_invalid'),
  username_invalid: accountTr('accounts.errors.username_invalid'),
  email_invalid: accountTr('accounts.errors.email_invalid'),
  phone_invalid: accountTr('accounts.errors.phone_invalid'),
  csrf_invalid: accountTr('accounts.errors.csrf_invalid'),
  captcha_invalid: accountTr('accounts.errors.captcha_invalid'),
  captcha_unavailable: accountTr('accounts.errors.captcha_unavailable'),
  session_invalid: accountTr('accounts.errors.session_invalid'),
  authentication_required: accountTr('accounts.errors.authentication_required'),
  service_unavailable: accountTr('accounts.errors.service_unavailable')
};
const form = document.querySelector('#account-form');
const notice = document.querySelector('#form-message');

const passwordForm = document.querySelector('#password-form');
const passwordNotice = document.querySelector('#password-message');
const consentForm = document.querySelector('#consent-form');
const consentNotice = document.querySelector('#consent-message');
const sendButton = document.querySelector('#send-code');
const captchaOpenButton = document.querySelector('#captcha-open');
const captchaDialog = document.querySelector('#captcha-dialog');
const captchaIdInput = document.querySelector('#captcha-id');
const captchaClicksInput = document.querySelector('#captcha-clicks');
const captchaMasterImage = document.querySelector('#captcha-master');
const captchaThumbImage = document.querySelector('#captcha-thumb');
const captchaImageButton = document.querySelector('#captcha-image-button');
const captchaMarks = document.querySelector('#captcha-marks');
const captchaProgress = document.querySelector('#captcha-progress');
const captchaConfirmButton = document.querySelector('#captcha-confirm');
const captchaRefreshButton = document.querySelector('#captcha-refresh');
const captchaCancelButton = document.querySelector('#captcha-cancel');
const captchaError = document.querySelector('#captcha-error');
const captchaStatus = document.querySelector('#captcha-status');
const captchaEnabled = form?.dataset.captchaEnabled !== 'false';
const passwordToggleButtons = document.querySelectorAll('.portal-password-toggle');
let captchaData = null;
let captchaClicks = [];
let challengeId = null;
let cooldownUntil = 0;
let sending = false;
let submitting = false;

function safeAccountNext(value) {
  if (['/feedback', '/feedback/new', '/support/wechat', '/shop'].includes(value)) return value;
  const match = /^\/shop\?product=([a-z][a-z0-9_-]{2,31})$/.exec(value);
  return match && match[1] !== 'wallet_recharge' ? value : '';
}

function accountDestination(path, next = '') {
  const purchase = /^\/shop\?product=([a-z][a-z0-9_-]{2,31})$/.exec(path);
  const destination = window.D2SI18n.accountUrls[purchase ? '/shop' : path];
  if (typeof destination !== 'string') throw new Error('account_destination_invalid');
  const url = new URL(destination, window.location.origin);
  if (purchase) url.searchParams.set('product', purchase[1]);
  if (next) url.searchParams.set('next', safeAccountNext(next));
  return url.pathname + url.search;
}

function csrf(realm) {
  const name = realm === 'admin' ? 'D2S_ADMIN_SESSION_CSRF' : 'D2S_USER_SESSION_CSRF';
  const prefix = name + '=';
  const item = document.cookie.split('; ').find((value) => value.startsWith(prefix));
  return item ? decodeURIComponent(item.slice(prefix.length)) : '';
}

function report(text) {
  if (notice) notice.textContent = text;
}

function reportTo(node, text) {
  if (node) node.textContent = text;
}

function drawCaptchaMarks() {
  if (!captchaMarks) return;
  const context = captchaMarks.getContext('2d');
  context.clearRect(0, 0, captchaMarks.width, captchaMarks.height);
  captchaClicks.forEach((point, index) => {
    context.beginPath();
    context.arc(point.x, point.y, 13, 0, Math.PI * 2);
    context.fillStyle = 'rgba(83,108,245,.9)';
    context.fill();
    context.lineWidth = 2;
    context.strokeStyle = '#fff';
    context.stroke();
    context.fillStyle = '#fff';
    context.font = '700 13px sans-serif';
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillText(String(index + 1), point.x, point.y);
  });
}

function updateCaptchaProgress() {
  if (!captchaData) return;
  captchaProgress.textContent = captchaClicks.length + '/' + captchaData.required_clicks;
  captchaConfirmButton.disabled = captchaClicks.length !== captchaData.required_clicks;
  captchaImageButton.disabled = captchaClicks.length >= captchaData.required_clicks;
  captchaImageButton.setAttribute('aria-label',
    accountTr('accounts.captcha.progress', {done: captchaClicks.length, total: captchaData.required_clicks}));
  drawCaptchaMarks();
}

function invalidateCaptcha() {
  captchaIdInput.value = '';
  captchaClicksInput.value = '';
  captchaData = null;
  captchaClicks = [];
  captchaProgress.textContent = accountTr('accounts.captcha.counter', {done: 0, total: 0});
  captchaMasterImage.removeAttribute('src');
  captchaThumbImage.removeAttribute('src');
  captchaImageButton.disabled = false;
  captchaConfirmButton.disabled = true;
  drawCaptchaMarks();
}

async function refreshCaptcha() {
  if (!form || !captchaDialog) return;
  invalidateCaptcha();
  captchaError.textContent = '';
  captchaRefreshButton.disabled = true;
  const realm = form.dataset.mode === 'admin' ? 'admin' : 'user';
  const endpoint = realm === 'admin' ? '/api/v2/admin/captcha' : '/api/v2/auth/captcha';
  try {
    const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', headers: {'X-D2S-Locale': document.documentElement.lang} });
    const result = await response.json();
    if (!response.ok || !result.success || !result.data ||
        typeof result.data.id !== 'string' ||
        typeof result.data.master_image !== 'string' || !result.data.master_image.startsWith('data:image/png;base64,') ||
        typeof result.data.thumb_image !== 'string' || !result.data.thumb_image.startsWith('data:image/png;base64,') ||
        result.data.width !== 320 || result.data.height !== 160 ||
        ![2, 3, 4].includes(result.data.required_clicks)) {
      throw new Error(messages[result.error] || messages.captcha_unavailable);
    }
    captchaData = result.data;
    captchaClicks = [];
    captchaMasterImage.src = captchaData.master_image;
    captchaThumbImage.src = captchaData.thumb_image;
    captchaError.textContent = '';
    updateCaptchaProgress();
    captchaImageButton.focus();
  } catch (error) {
    captchaError.textContent = error.message || messages.captcha_unavailable;
  } finally {
    captchaRefreshButton.disabled = false;
  }
}

function openCaptcha() {
  if (!captchaDialog) return;
  captchaDialog.hidden = false;
  void refreshCaptcha();
}

function closeCaptcha(keepVerified = false) {
  if (!captchaDialog) return;
  captchaDialog.hidden = true;
  if (!keepVerified) invalidateCaptcha();
  captchaOpenButton?.focus();
}

captchaOpenButton?.addEventListener('click', openCaptcha);
captchaRefreshButton?.addEventListener('click', () => void refreshCaptcha());
captchaCancelButton?.addEventListener('click', () => closeCaptcha());
captchaDialog?.addEventListener('click', (event) => {
  if (event.target === captchaDialog) closeCaptcha();
});
captchaDialog?.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeCaptcha();
});
captchaImageButton?.addEventListener('click', (event) => {
  if (!captchaData || captchaClicks.length >= captchaData.required_clicks) return;
  const bounds = captchaImageButton.getBoundingClientRect();
  captchaClicks.push({
    x: Math.max(0, Math.min(captchaData.width,
      Math.round((event.clientX - bounds.left) * captchaData.width / bounds.width))),
    y: Math.max(0, Math.min(captchaData.height,
      Math.round((event.clientY - bounds.top) * captchaData.height / bounds.height)))
  });
  updateCaptchaProgress();
});
captchaConfirmButton?.addEventListener('click', () => {
  if (!captchaData || captchaClicks.length !== captchaData.required_clicks) return;
  captchaIdInput.value = captchaData.id;
  captchaClicksInput.value = JSON.stringify(captchaClicks);
  captchaStatus.textContent = accountTr('accounts.captcha.completed');
  closeCaptcha(true);
});

async function request(path, data, realm = 'user') {
  const headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(realm), 'X-D2S-Locale': document.documentElement.lang };
  const response = await fetch(path, {
    method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(data)
  });
  const result = await response.json();
  if (!response.ok || !result.success) {
    throw new Error(messages[result.error] || accountTr('accounts.notices.operation_failed'));
  }
  return result.data;
}

function updateSend() {
  if (!sendButton || !form) return;
  const kind = form.elements.kind.value;
  const available = kind === 'phone' ? form.dataset.phone === 'true' : form.dataset.email === 'true';
  const seconds = Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000));
  sendButton.disabled = sending || submitting || !available || seconds > 0;
  sendButton.textContent = seconds > 0 ? accountTr('accounts.notices.code_cooldown', {seconds}) : accountTr('accounts.actions.send_code');
  if (seconds > 0) window.setTimeout(updateSend, 1000);
}

function invalidateChallenge() {
  challengeId = null;
  if (form?.elements.code) form.elements.code.value = '';
}

function updateContact() {
  if (!form?.elements.kind) return;

  const isPhone = form.elements.kind.value === 'phone';
  form.elements.contact.type = isPhone ? 'tel' : 'email';
  form.elements.contact.autocomplete = isPhone ? 'tel' : 'email';
  invalidateChallenge();
  updateSend();
}

passwordToggleButtons.forEach((button) => {
  button.addEventListener('click', () => {
    const input = button.parentElement.querySelector('input[type="password"], input[type="text"]');
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    button.title = accountTr(visible ? 'accounts.actions.hide_password' : 'accounts.actions.show_password');
  });
});

if (form) {

  form.elements.kind?.addEventListener('change', updateContact);
  form.elements.contact?.addEventListener('input', invalidateChallenge);
  updateContact();

  sendButton?.addEventListener('click', async () => {
    if (!form.elements.contact.reportValidity() || sending || submitting) return;
    sending = true;
    updateSend();
    const kind = form.elements.kind.value;
    const contact = form.elements.contact.value;
    const purposes = { register: 'register', reset: 'password_reset', contact: 'bind_contact' };
    try {
      const result = await request('/api/v2/auth/verification', { kind, contact, purpose: purposes[form.dataset.mode] });
      if (kind === form.elements.kind.value && contact === form.elements.contact.value) {
        challengeId = result.challenge_id;
      }
      cooldownUntil = Date.now() + result.retry_after * 1000;
      report(accountTr('accounts.notices.code_queued'));
    } catch (error) {
      report(error.message);
    } finally {
      sending = false;
      updateSend();
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (submitting || sending) return;
    const mode = form.dataset.mode;
    const requestedNext = form.dataset.next || '';
    const next = safeAccountNext(requestedNext);
    const hasCode = ['register', 'reset', 'contact'].includes(mode);
    if (captchaEnabled && (mode === 'login' || mode === 'admin') && (!captchaIdInput?.value || !captchaClicksInput?.value)) {
      report(accountTr('accounts.captcha.before_submit'));
      openCaptcha();
      return;
    }
    if (hasCode && !challengeId) {
      report(accountTr('accounts.notices.code_required'));
      return;
    }
    const fields = Object.fromEntries(new FormData(form));
    if (mode === 'register' && fields.password !== fields.confirm_password) {
      report(accountTr('accounts.notices.password_mismatch'));
      form.elements.confirm_password.focus();
      return;
    }
    delete fields.confirm_password;
    if (captchaEnabled && (mode === 'login' || mode === 'admin')) {
      try {
        fields.captcha_clicks = JSON.parse(fields.captcha_clicks);
      } catch {
        report(accountTr('accounts.captcha.retry'));
        invalidateCaptcha();
        openCaptcha();
        return;
      }
    }
    submitting = true;
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    updateSend();
    try {
      if (mode === 'login' || mode === 'admin') {
        const realm = mode === 'admin' ? 'admin' : 'user';
        const session = await request('/api/v2/' + (realm === 'admin' ? 'admin' : 'auth') + '/login', fields, realm);
        window.location.assign(accountDestination(realm === 'admin' ? '/admin' :
          (session.consent_required ? '/consent' : (next || '/profile')), realm === 'user' && session.consent_required ? next : ''));
      } else if (mode === 'register') {
        await request('/api/v2/auth/register', {
          ...fields,
          privacy_accepted: fields.legal_accepted === 'on' || fields.legal_accepted === 'true',
          terms_accepted: fields.legal_accepted === 'on' || fields.legal_accepted === 'true',
          challenge_id: challengeId
        });
        window.location.assign(accountDestination('/login', next));
      } else if (mode === 'reset') {
        await request('/api/v2/auth/password-reset', { ...fields, challenge_id: challengeId });
        window.location.assign(accountDestination('/login', next));
      } else if (mode === 'contact') {
        await request('/api/v2/me/contact', { ...fields, current_password: fields.password, challenge_id: challengeId });
        window.location.assign(accountDestination('/login'));
      }
    } catch (error) {
      report(error.message);
      if (captchaEnabled && (mode === 'login' || mode === 'admin')) {
        closeCaptcha();
        captchaStatus.textContent = accountTr('accounts.captcha.expired');
      }
    } finally {
      submitting = false;
      submit.disabled = false;
      updateSend();
    }
  });
}

consentForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = consentForm.querySelector('button[type="submit"]');
  if (!consentForm.reportValidity()) return;
  button.disabled = true;
  reportTo(consentNotice, '');
  try {
    await request('/api/v2/me/consents', { legal_accepted: consentForm.elements.legal_accepted.checked });
    const next = safeAccountNext(new URLSearchParams(window.location.search).get('next') || '');
    window.location.assign(accountDestination(next || '/profile'));
  } catch (error) {
    reportTo(consentNotice, error.message);
    button.disabled = false;
  }
});



passwordForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = passwordForm.querySelector('button[type="submit"]');
  const fields = Object.fromEntries(new FormData(passwordForm));
  reportTo(passwordNotice, '');
  if (fields.new_password !== fields.confirm_password) {
    reportTo(passwordNotice, accountTr('accounts.notices.password_mismatch'));
    return;
  }
  button.disabled = true;
  try {
    await request('/api/v2/me/password', {
      current_password: fields.current_password,
      new_password: fields.new_password
    });
    window.location.assign(accountDestination('/login'));
  } catch (error) {
    reportTo(passwordNotice, error.message);
    button.disabled = false;
  }
});
