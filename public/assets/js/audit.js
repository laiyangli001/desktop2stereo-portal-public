'use strict';

const root = document.querySelector('#audit-app');
const message = document.querySelector('#audit-message');
const table = document.querySelector('#audit-table');
const resource = document.querySelector('#audit-resource');
const exportsList = document.querySelector('#audit-exports');
const filterForm = document.querySelector('#audit-filter');
let page = 1;
let busy = false;

const resourceMap = { events: 'access_log', registrations: 'registration', operations: 'admin_audit' };
const exportResourceKey = { events: 'audit.resource.events', access_log: 'audit.resource.access_log', registration: 'audit.resource.registration', registrations: 'audit.resource.registrations', operations: 'audit.resource.operations', admin_audit: 'audit.resource.admin_audit' };
const eventTypeKey = {
  REGISTER: 'register', LOGIN: 'login', LOGOUT: 'logout', PURCHASE: 'purchase',
  DOWNLOAD: 'download', PASSWORD_RESET: 'password_reset', PROFILE_UPDATE: 'profile_update', WITHDRAWAL: 'withdrawal',
};
const auditTr = (key, values = {}) => window.D2SI18n.tr(key, values);

const columns = {
  access_log: {
    labels: ['audit.column.id','audit.column.user_id','audit.column.email','audit.column.action','audit.column.utc_time','audit.column.source_ip','audit.column.source_port','audit.column.destination_ip','audit.column.destination_port','audit.column.user_agent','audit.column.device_hash','audit.column.path','audit.column.result','audit.column.hash','audit.column.version'],
    fields: ['id','user_id','email','action_type','event_time','source_ip','source_port','destination_ip','destination_port','user_agent','device_fingerprint','request_path','result_status','record_hash','hash_version'],
  },
  registration: {
    labels: ['audit.column.user_id','audit.column.email','audit.column.register_ip','audit.column.register_time','audit.column.user_agent','audit.column.email_verified','audit.column.verified_at','audit.column.contact_kind','audit.column.contact_value'],
    fields: ['user_id','email','register_ip','register_time','register_user_agent','email_verified','email_verified_at','contact_kind','contact_value'],
  },
  admin_audit: {
    labels: ['audit.column.id','audit.column.operator_id','audit.column.operator_email','audit.column.action','audit.column.utc_time','audit.column.target','audit.column.source_ip','audit.column.result','audit.column.details'],
    fields: ['id','operator_id','operator_email','operation_type','operation_time','target_resource','source_ip','result','details_json'],
  },
};

function csrf() {
  const prefix = 'D2S_ADMIN_SESSION_CSRF=';
  const item = document.cookie.split('; ').find((value) => value.startsWith(prefix));
  return item ? decodeURIComponent(item.slice(prefix.length)) : '';
}

function report(value) { message.textContent = value; }

function setBusy(value) {
  busy = value;
  root.setAttribute('aria-busy', String(value));
  for (const control of root.querySelectorAll('#audit-filter button, #audit-previous, #audit-next')) control.disabled = value;
}

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
  if (!response.ok || !result.success) throw new Error(result.error || 'service_unavailable');
  return result.data;
}

function epoch(value) {
  if (!value) return '';
  return String(Math.floor(new Date(value).getTime() / 1000));
}

function filters() {
  const type = resourceMap[resource.value];
  return {
    start_time: epoch(document.querySelector('#audit-start').value),
    end_time: epoch(document.querySelector('#audit-end').value),
    ...(type === 'access_log' ? {
      user_id: document.querySelector('#audit-user-id').value.trim(),
      email: document.querySelector('#audit-email').value.trim(),
      source_ip: document.querySelector('#audit-ip').value.trim(),
      action_type: document.querySelector('#audit-action').value,
    } : {}),
    ...(type === 'admin_audit' ? {
      operator_email: document.querySelector('#audit-operator').value.trim(),
      operation_type: document.querySelector('#audit-operation').value.trim(),
    } : {}),
  };
}

function endpoint(type) {
  return type === 'access_log' ? '/api/v2/admin/audit/events' :
    type === 'registration' ? '/api/v2/admin/audit/registrations' : '/api/v2/admin/audit/operations';
}

function queryString(type) {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters())) {
    if (value !== '') params.set(key, value);
  }
  params.set('page', String(page));
  params.set('page_size', '50');
  return params.toString();
}

function updateFilterVisibility() {
  const type = resourceMap[resource.value];
  for (const item of document.querySelectorAll('[data-filter]')) {
    item.hidden = item.dataset.filter !== type;
  }
}

function display(value, field) {
  if (value === null || value === undefined) return '';
  if (['event_time','register_time','email_verified_at','operation_time'].includes(field) && Number(value) > 0) {
    return new Date(Number(value) * 1000).toISOString().replace('.000Z', 'Z');
  }
  const normalized = String(value).toUpperCase();
  if (field === 'action_type' && eventTypeKey[normalized]) return auditTr('audit.action_type.' + eventTypeKey[normalized]);
  if (['result_status','result'].includes(field) && ['SUCCESS','FAILURE','BLOCKED','PENDING'].includes(normalized)) {
    return auditTr('audit.event_result.' + normalized.toLowerCase());
  }
  if (field === 'contact_kind' && ['EMAIL','PHONE'].includes(normalized)) {
    return auditTr('audit.contact.' + normalized.toLowerCase());
  }
  if (field === 'email_verified' && ['0','1'].includes(String(value))) {
    return auditTr('audit.boolean.' + (String(value) === '1' ? 'verified' : 'unverified'));
  }
  return String(value);
}

function render(data) {
  const type = resourceMap[resource.value];
  const definition = columns[type];
  const head = document.createElement('thead');
  const headRow = document.createElement('tr');
  for (const key of definition.labels) headRow.append(text('th', auditTr(key)));
  head.append(headRow);
  const body = document.createElement('tbody');
  if (data.items.length === 0) {
    const row = document.createElement('tr');
    const cell = text('td', auditTr('audit.empty.records'));
    cell.colSpan = definition.labels.length;
    row.append(cell);
    body.append(row);
  }
  for (const entry of data.items) {
    const row = document.createElement('tr');
    for (const field of definition.fields) {
      const cell = document.createElement('td');
      const value = display(entry[field], field);
      if (['result_status','result'].includes(field) && ['SUCCESS','FAILURE','BLOCKED','PENDING'].includes(String(entry[field]).toUpperCase())) {
        cell.append(text('span', value, 'audit-result ' + String(entry[field]).toLowerCase()));
      } else {
        cell.textContent = value;
        if (['id','user_id','operator_id','device_fingerprint','record_hash'].includes(field)) cell.classList.add('audit-mono');
        if (['device_fingerprint','record_hash'].includes(field)) cell.classList.add('audit-hash');
        if (['email','contact_value','user_agent','request_path','details_json','target_resource'].includes(field)) cell.classList.add('audit-wrap');
      }
      row.append(cell);
    }
    if (type === 'access_log') {
      const verify = text('button', auditTr('audit.action.verify_hash'), 'audit-inline-button');
      verify.type = 'button';
      verify.addEventListener('click', async () => {
        try {
          const result = await api('/api/v2/admin/audit/events/' + entry.id + '/verify', {});
          report(auditTr(result.consistent ? 'audit.status.hash_ok' : 'audit.status.hash_mismatch'));
        } catch (error) {
          report(auditTr('audit.status.hash_failed'));
        }
      });
      row.lastElementChild.append(document.createElement('br'), verify);
    }
    body.append(row);
  }
  table.replaceChildren(head, body);
  document.querySelector('#audit-page').textContent = auditTr('audit.status.page', { page: data.page, count: data.total });
  document.querySelector('#audit-previous').disabled = data.page <= 1;
  document.querySelector('#audit-next').disabled = data.page * data.per_page >= data.total;
}

async function search() {
  if (busy) return;
  setBusy(true);
  report(auditTr('audit.status.loading'));
  try {
    const type = resourceMap[resource.value];
    render(await api(endpoint(type) + '?' + queryString(type)));
    report('');
  } catch (error) {
    report(auditTr(error.message === 'permission_denied' ? 'audit.status.no_permission' : 'audit.status.query_failed'));
  } finally {
    setBusy(false);
  }
}

function statusText(status) {
  const key = { PENDING: 'audit.status.pending', APPROVED: 'audit.status.approved', REJECTED: 'audit.status.rejected', EXPIRED: 'audit.status.expired' }[status];
  return key ? auditTr(key) : status;
}

function renderExports(items) {
  exportsList.replaceChildren();
  if (items.length === 0) {
    exportsList.append(text('p', auditTr('audit.empty.exports')));
    return;
  }
  for (const item of items) {
    const row = document.createElement('article');
    row.className = 'export-item';
    row.append(text('strong', statusText(item.status) + ' · ' + auditTr(exportResourceKey[item.resource_type] || 'audit.resource.operations')));
    row.append(text('span', auditTr('audit.export.requester', { name: item.requester_username, date: new Intl.DateTimeFormat(window.D2SI18n.locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(Number(item.requested_at) * 1000)) })));
    row.append(text('span', auditTr('audit.export.scope', { filters: item.filters_json })));
    const actions = document.createElement('div');
    if (['APPROVED','PENDING'].includes(item.status)) {
      const download = text('button', auditTr('audit.action.download'), 'portal-primary');
      download.type = 'button';
      download.addEventListener('click', () => downloadCsv(item.id, download));
      actions.append(download);
    }
    if (actions.childElementCount) row.append(actions);
    exportsList.append(row);
  }
}

async function downloadCsv(id, button) {
  button.disabled = true;
  try {
    const response = await fetch('/api/v2/admin/audit/exports/' + id + '/download', {
      method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() },
    });
    if (!response.ok) {
      const result = await response.json();
      throw new Error(result.error || 'service_unavailable');
    }
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const filename = match ? match[1] : 'audit_export.csv';
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    report(auditTr('audit.status.download_started'));
  } catch (error) {
    report(auditTr(error.message === 'export_approval_expired' ? 'audit.status.export_expired' : 'audit.status.download_failed'));
  } finally {
    button.disabled = false;
  }
}

async function loadExports() {
  try {
    if (root.dataset.canRequest !== 'true') return;
    renderExports(await api('/api/v2/admin/audit/exports'));
  } catch (error) {
    if (root.dataset.canRequest === 'true') report(auditTr('audit.status.exports_failed'));
  }
}

filterForm.addEventListener('submit', event => {
  event.preventDefault();
  page = 1;
  search();
});
resource.addEventListener('change', () => {
  updateFilterVisibility();
  page = 1;
  table.replaceChildren();
  loadExports();
});
document.querySelector('#audit-previous').addEventListener('click', () => {
  if (page > 1 && !busy) { page--; search(); }
});
document.querySelector('#audit-next').addEventListener('click', () => {
  if (!busy) { page++; search(); }
});
document.querySelector('#audit-export-request').addEventListener('click', async event => {
  const type = resourceMap[resource.value];
  if (!document.querySelector('#audit-start').value || !document.querySelector('#audit-end').value) {
    report(auditTr('audit.error.export_time'));
    return;
  }
  const button = event.currentTarget;
  button.disabled = true;
  try {
    const result = await api('/api/v2/admin/audit/exports', { resource_type: type, ...filters() });
    await loadExports();
    await downloadCsv(result.id, document.querySelector('#audit-export-request'));
  } catch (error) {
    report(auditTr('audit.error.export'));
  } finally {
    button.disabled = false;
  }
});

const now = new Date();
const monthAgo = new Date(now.getTime() - 30 * 86400 * 1000);
const local = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
document.querySelector('#audit-start').value = local(monthAgo);
document.querySelector('#audit-end').value = local(new Date(now.getTime() + 60000));
updateFilterVisibility();
search();
loadExports();
