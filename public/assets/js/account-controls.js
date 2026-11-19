'use strict';

const root = document.querySelector('#account-controls');
const message = document.querySelector('#control-message');
const results = document.querySelector('#account-results');
const detail = document.querySelector('#account-detail');
const detailBody = document.querySelector('#account-detail-body');
const detailTitle = document.querySelector('#account-detail-title');
const restrictionMessage = document.querySelector('#restriction-message');
const identityMessage = document.querySelector('#identity-message');
const batchMessage = document.querySelector('#batch-message');
const locale = root.dataset.locale || document.documentElement.lang || 'en';
const translate = (key, values = {}) => window.D2SI18n.tr('account_controls.' + key, values);
const statusKeys = { pending:'status.pending', active:'status.active', disabled:'status.disabled' };
const identityStatusKeys = {
  NOT_REQUIRED:'identity.status.not_required',
  PENDING:'identity.status.pending',
  VERIFIED:'identity.status.verified',
  REJECTED:'identity.status.rejected',
};
let current = null;
let preview = null;
let busy = false;

function csrf() {
  const prefix = 'D2S_ADMIN_SESSION_CSRF=';
  const item = document.cookie.split('; ').find((value) => value.startsWith(prefix));
  return item ? decodeURIComponent(item.slice(prefix.length)) : '';
}

function report(value, target = message) { if (target) target.textContent = value; }

function text(tag, value, className = '') {
  const node = document.createElement(tag);
  node.textContent = value ?? '';
  if (className) node.className = className;
  return node;
}

async function api(path, data) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    ...(data === undefined ? {} : {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify(data),
    }),
  });
  const result = await response.json();
  if (!response.ok || !result.success) {
    const error = new Error(translate('error.api'));
    error.code = result.error || 'service_unavailable';
    throw error;
  }
  return result.data;
}

function date(value) {
  if (!value) return '';
  return new Intl.DateTimeFormat(locale, { dateStyle:'medium', timeStyle:'short' }).format(new Date(Number(value) * 1000));
}

function countryName(value) {
  const code = String(value || '').toUpperCase();
  if (!/^[A-Z]{2}$/.test(code)) return code;
  try { return new Intl.DisplayNames([locale], { type:'region' }).of(code) || code; }
  catch { return code; }
}

function statusLabel(value) {
  const key = statusKeys[String(value || '').toLowerCase()];
  return key ? translate(key) : String(value || '');
}

function markOpenUser(id) {
  for (const button of results.querySelectorAll('button')) {
    button.setAttribute('aria-expanded', String(Number(button.dataset.user) === id));
  }
}

function renderResults(items) {
  results.replaceChildren();
  if (items.length === 0) {
    results.append(text('li', translate('search.no_results'), 'account-control-message'));
    return;
  }
  for (const user of items) {
    const row = document.createElement('li');
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.user = user.id;
    button.setAttribute('aria-controls', 'account-detail');
    button.append(
      text('strong', user.username),
      text('span', translate('search.row', {id:user.id, region:countryName(user.region), status:statusLabel(user.status)})),
      text('span', translate('search.created', {date:date(user.created_at)})),
    );
    button.addEventListener('click', () => {
      if (!busy) void openUser(Number(user.id), true);
    });
    row.append(button);
    results.append(row);
  }
  markOpenUser(current ? Number(current.id) : null);
}

async function openUser(id, focus = false) {
  busy = true;
  try {
    current = await api('/api/v2/admin/accounts/' + id + '/control');
    detailBody.replaceChildren();
    detailBody.append(text('p', translate('detail.user', {
      id:current.id, username:current.username, region:countryName(current.region),
    })));
    detailBody.append(text('p', translate('detail.account_status', { status:statusLabel(current.status) })));
    for (const contact of current.contacts) {
      const kind = contact.kind === 'email' ? translate('contact.email') : translate('contact.phone');
      const verification = translate(contact.verified_at ? 'status.verified' : 'status.unverified');
      detailBody.append(text('p', translate('detail.contact', { kind, value:contact.value, verification })));
    }
    detailBody.append(text('p', translate('detail.restrictions', {
      login:translate(current.login_blocked ? 'status.yes' : 'status.no'),
      purchase:translate(current.purchase_blocked ? 'status.yes' : 'status.no'),
    })));
    detailBody.append(text('p', translate('detail.latest_reason', {
      reason:current.reason || translate('detail.no_reason'),
    })));
    const identity = current.identity;
    const identitySummary = document.querySelector('#identity-summary');
    identitySummary.replaceChildren();
    const identityStatusKey = identityStatusKeys[identity.status];
    identitySummary.append(text('p', translate('detail.identity_status', {
      status:identityStatusKey ? translate(identityStatusKey) : identity.status,
    })));
    if (identity.requested_at) {
      identitySummary.append(text('p', translate('detail.requested_at', { date:date(identity.requested_at) })));
    }
    if (identity.decision_reason) {
      identitySummary.append(text('p', translate('detail.latest_note', { reason:identity.decision_reason })));
    }
    const requestForm = document.querySelector('#identity-request-form');
    const decisionForm = document.querySelector('#identity-decision-form');
    if (requestForm) requestForm.hidden = ['PENDING','VERIFIED'].includes(identity.status);
    if (decisionForm) decisionForm.hidden = identity.status !== 'PENDING';
    for (const selector of ['#identity-request-reason','#identity-number','#identity-decision-reason']) {
      const input = document.querySelector(selector);
      if (input) input.value = '';
    }
    if (document.querySelector('#control-login')) {
      document.querySelector('#control-login').checked = current.login_blocked;
      document.querySelector('#control-purchase').checked = current.purchase_blocked;
      document.querySelector('#control-force-logout').checked = false;
      document.querySelector('#control-reason').value = current.reason || '';
      document.querySelector('#control-confirm').checked = false;
    }
    detail.hidden = false;
    markOpenUser(id);
    if (focus) {
      report('', restrictionMessage);
      report('', identityMessage);
      detailTitle.focus({preventScroll:true});
      detail.scrollIntoView({block:'start'});
    }
    report('');
  } catch (error) {
    report(translate('error.detail_load'));
  } finally {
    busy = false;
  }
}

document.querySelector('#account-search').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (busy) return;
  busy = true;
  try {
    renderResults(await api('/api/v2/admin/accounts?query=' +
      encodeURIComponent(document.querySelector('#account-query').value.trim())));
    report('');
  } catch (error) {
    report(translate('error.search'));
  } finally {
    busy = false;
  }
});

document.querySelector('#account-detail-close').addEventListener('click', () => {
  const selected = results.querySelector('button[aria-expanded=true]');
  detail.hidden = true;
  current = null;
  markOpenUser(null);
  (selected || document.querySelector('#account-query')).focus();
});

document.querySelector('#account-control-form')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!current || busy) return;
  if (!document.querySelector('#control-confirm').checked) {
    report(translate('error.confirm_required'), restrictionMessage);
    return;
  }
  busy = true;
  try {
    const result = await api('/api/v2/admin/accounts/' + current.id + '/control', {
      login_blocked:document.querySelector('#control-login').checked,
      purchase_blocked:document.querySelector('#control-purchase').checked,
      force_logout:document.querySelector('#control-force-logout').checked,
      reason:document.querySelector('#control-reason').value,
      confirm:true,
    });
    const success = result.operation_type === 'EMERGENCY_UNLOCK'
      ? translate('success.unlocked')
      : translate(result.force_logout ? 'success.force_logout' : 'success.restricted');
    await openUser(current.id);
    report(success, restrictionMessage);
  } catch (error) {
    report(translate('error.restriction'), restrictionMessage);
  } finally {
    busy = false;
  }
});

document.querySelector('#identity-request-form')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!current || busy) return;
  busy = true;
  try {
    await api('/api/v2/admin/accounts/' + current.id + '/identity/request', {
      reason:document.querySelector('#identity-request-reason').value,
    });
    await openUser(current.id);
    report(translate('success.identity_requested'), identityMessage);
  } catch (error) {
    report(translate('error.identity_request'), identityMessage);
  } finally {
    busy = false;
  }
});

document.querySelector('#identity-decision-form')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!current || busy) return;
  busy = true;
  try {
    await api('/api/v2/admin/accounts/' + current.id + '/identity/decision', {
      decision:document.querySelector('#identity-decision').value,
      document_type:document.querySelector('#identity-document-type').value,
      document_number:document.querySelector('#identity-number').value,
      document_country:document.querySelector('#identity-country').value,
      reason:document.querySelector('#identity-decision-reason').value,
    });
    await openUser(current.id);
    report(translate('success.identity_decided'), identityMessage);
  } catch (error) {
    report(translate(error.code === 'identity_reviewer_separation_required'
      ? 'error.identity_same_reviewer' : 'error.identity_decision'), identityMessage);
  } finally {
    busy = false;
  }
});

function epoch(value) {
  return value ? String(Math.floor(new Date(value).getTime() / 1000)) : '';
}

document.querySelector('#batch-form')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (busy) return;
  const login = document.querySelector('#batch-login').checked;
  const purchase = document.querySelector('#batch-purchase').checked;
  if (!login && !purchase) {
    report(translate('error.batch_action_required'), batchMessage);
    return;
  }
  busy = true;
  try {
    preview = await api('/api/v2/admin/accounts/batch/preview', {
      selector: {
        ip_cidr:document.querySelector('#batch-ip').value.trim(),
        email_domain:document.querySelector('#batch-domain').value.trim(),
        register_from:Number(epoch(document.querySelector('#batch-from').value) || 0),
        register_until:Number(epoch(document.querySelector('#batch-until').value) || 0),
      },
      actions: {
        login_blocked:login,
        purchase_blocked:purchase,
        reason:document.querySelector('#batch-reason').value,
      },
    });
    document.querySelector('#batch-preview-summary').textContent =
      translate('batch.preview_summary', { count:preview.match_count });
    document.querySelector('#batch-preview').hidden = false;
    report('', batchMessage);
  } catch (error) {
    report(translate('error.batch_preview'), batchMessage);
  } finally {
    busy = false;
  }
});

document.querySelector('#batch-apply')?.addEventListener('click', async () => {
  if (!preview || busy) return;
  const summary = document.querySelector('#batch-preview-summary').textContent;
  if (!window.confirm(summary + '\n\n' + translate('batch.confirm'))) return;
  busy = true;
  try {
    const result = await api('/api/v2/admin/accounts/batch/apply', {
      preview_id:preview.preview_id,
      confirmation_count:preview.match_count,
      confirmed:true,
    });
    report(translate('success.batch', { count:result.affected_accounts }), batchMessage);
    preview = null;
    document.querySelector('#batch-preview').hidden = true;
  } catch (error) {
    report(translate(error.code === 'batch_selection_changed'
      ? 'error.batch_changed' : 'error.batch_apply'), batchMessage);
  } finally {
    busy = false;
  }
});
