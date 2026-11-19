'use strict';

const tr = (key, parameters = {}) => window.D2SI18n.tr('support.' + key, parameters);
const statusText = { OPEN: tr('status.open'), IN_PROGRESS: tr('status.in_progress'), RESOLVED: tr('status.resolved'), CLOSED: tr('status.closed') };
const categoryText = {
  BUG: tr('category.bug'), PURCHASE: tr('category.purchase'), LICENSE: tr('category.license'),
  DOWNLOAD: tr('category.download'), ACCOUNT: tr('category.account'), SOFTWARE: tr('category.software'),
  SUGGESTION: tr('category.suggestion'), OTHER: tr('category.other'),
};
const message = document.querySelector('#ticket-message');
const list = document.querySelector('#ticket-list');
const detail = document.querySelector('#ticket-detail');
const messages = document.querySelector('#ticket-messages');
const replyForm = document.querySelector('#ticket-reply-form');
const closeButton = document.querySelector('#ticket-close');
let page = 1;
let currentTicket = null;
let pendingReply = null;
let selectedFiles = [];
let pagination = null;
let savedAttachmentCount = 0;
let savedAttachmentBytes = 0;
const replyInput = document.querySelector('#ticket-reply');
const replyEditorHost = document.querySelector('#ticket-reply-editor');
const fileInput = document.querySelector('#ticket-detail-files');
let replyEditor = null;
if (replyEditorHost && window.Quill) {
  replyEditorHost.hidden = false;
  replyInput.hidden = true;
  replyInput.required = false;
  replyEditor = new window.Quill(replyEditorHost, {
    theme: 'snow',
    placeholder: replyEditorHost.dataset.placeholder || '',
    modules: {
      toolbar: [
        ['bold', 'italic', 'underline', 'strike'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['blockquote', 'code-block'],
        ['link'],
        ['clean'],
      ],
    },
  });
  replyEditor.root.setAttribute('aria-describedby', 'ticket-reply-editor-label');
  replyEditor.root.setAttribute('aria-multiline', 'true');
  window.D2STicketReplyEditor = replyEditor;
}
let busy = false;

function replyContent() {
  if (replyEditor) {
    return { body: replyEditor.root.innerHTML.trim(), format: 'HTML', text: replyEditor.getText().trim() };
  }
  const value = replyInput.value.trim();
  return { body: value, format: 'PLAIN', text: value };
}

function resetReply() {
  if (replyEditor) replyEditor.setText('\n');
  else replyInput.value = '';
}

function csrf() {
  const prefix = 'D2S_USER_SESSION_CSRF=';
  const item = document.cookie.split('; ').find((value) => value.startsWith(prefix));
  return item ? decodeURIComponent(item.slice(prefix.length)) : '';
}

function report(text) {
  message.textContent = text;
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

async function upload(ticketId, messageId, file) {
  const form = new FormData();
  form.append('message_id', messageId);
  form.append('file', file);
  const response = await fetch('/api/v2/support/tickets/' + ticketId + '/attachments', {
    method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() }, body: form,
  });
  const result = await response.json();
  if (!response.ok || !result.success) throw new Error(result.error || 'attachment_upload_invalid');
  return result.data;
}

function text(tag, value, className = '') {
  const node = document.createElement(tag);
  node.textContent = value;
  if (className) node.className = className;
  return node;
}

function date(value) {
  return new Date(Number(value) * 1000).toLocaleString(document.documentElement.lang);
}

function setBusy(value) {
  busy = value;
  document.querySelector('#ticket-app').setAttribute('aria-busy', String(value));
  for (const control of document.querySelectorAll('#ticket-list button, #ticket-reply-form button, #ticket-close, #ticket-detail-close, #ticket-refresh')) {
    control.disabled = value;
  }
  replyInput.disabled = value || Boolean(pendingReply);
  if (replyEditor) replyEditor.enable(!value && !pendingReply);
  fileInput.disabled = value || Boolean(pendingReply);
  if (pagination) {
    document.querySelector('#ticket-previous').disabled = value || pagination.page <= 1;
    document.querySelector('#ticket-next').disabled = value || pagination.page * pagination.per_page >= pagination.total;
  }
}

function replyState(ticket) {
  const staff = Number(ticket.last_staff_reply_at || 0);
  if (ticket.status === 'CLOSED') return { label: tr('status.closed'), className: 'closed' };
  if (ticket.last_public_actor === 'ADMIN') return { label: tr('status.support_replied'), className: 'replied' };
  if (ticket.last_public_actor === 'USER' && staff > 0) return { label: tr('status.waiting_support'), className: 'waiting' };
  if (ticket.status === 'RESOLVED') return { label: tr('status.resolved'), className: 'resolved' };
  if (ticket.status === 'IN_PROGRESS') return { label: tr('status.support_working'), className: 'progress' };
  return { label: tr('status.waiting_action'), className: 'open' };
}

function renderList(data) {
  pagination = data;
  list.replaceChildren();
  if (data.items.length === 0) {
    const row = document.createElement('tr');
    const cell = text('td', tr('empty.filtered'));
    cell.colSpan = 6;
    row.append(cell);
    list.append(row);
  }
  for (const ticket of data.items) {
    const row = document.createElement('tr');
    row.append(text('td', categoryText[ticket.category] || ticket.category));
    row.append(text('td', ticket.id.slice(0, 8).toUpperCase()));
    const subject = document.createElement('td');
    const button = text('button', ticket.subject, 'ticket-subject-button');
    button.type = 'button';
    button.setAttribute('aria-label', tr('action.view_ticket', { subject: ticket.subject }));
    button.addEventListener('click', () => openTicket(ticket.id));
    subject.append(button);
    const reply = replyState(ticket);
    subject.append(text('p', reply.label, 'support-reply-state ' + reply.className));
    if (ticket.last_staff_reply) {
      subject.append(text('p', tr('reply.latest', { text: String(ticket.last_staff_reply).replace(/\s+/g, ' ').slice(0, 120) }), 'support-reply-preview'));
    }
    subject.append(text('p', tr('reply.count', { count: ticket.message_count, attachments: ticket.attachment_count }), 'ticket-row-meta'));
    if (ticket.order_no) subject.append(text('p', tr('order.label', { order: ticket.order_no })));
    row.append(subject);
    const status = document.createElement('td');
    status.append(text('span', statusText[ticket.status] || ticket.status, 'support-status ' + ticket.status.toLowerCase()));
    row.append(status, text('td', date(ticket.created_at)));
    const action = document.createElement('td');
    const view = text('button', tr('action.view'));
    view.type = 'button';
    view.setAttribute('aria-label', tr('action.view_detail', { ticket: ticket.id.slice(0, 8).toUpperCase() }));
    view.addEventListener('click', () => openTicket(ticket.id));
    action.append(view);
    row.append(action);
    list.append(row);
  }
  document.querySelector('#ticket-page-summary').textContent =
    tr('pager.summary', { page: data.page, count: data.total });
}
async function load() {
  setBusy(true);
  try {
    const params = new URLSearchParams({ page: String(page) });
    if (new URLSearchParams(location.search).get('view') === 'pending') params.set('view', 'pending');
    const query = document.querySelector('#ticket-search').value.trim();
    if (query) params.set('q', query);
    renderList(await api('/api/v2/support/tickets?' + params));
    report('');
  } catch (error) {
    report(error.message === 'authentication_required' ? tr('error.login_again') : tr('error.load'));
  } finally {
    setBusy(false);
  }
}

function renderMessages(data) {
  savedAttachmentCount = data.messages.reduce((total, item) => total + item.attachments.length, 0);
  savedAttachmentBytes = data.messages.reduce((total, item) => total + item.attachments.reduce((sum, file) => sum + Number(file.byte_size), 0), 0);
  messages.replaceChildren();
  for (const item of data.messages) {
    const article = document.createElement('article');
    article.className = 'support-message ticket-conversation-message ' + (item.author_kind === 'ADMIN' ? 'from-support' : 'from-user');
    const header = document.createElement('header');
    const avatar = text('span', item.author_kind === 'ADMIN' ? 'S' : 'U', 'ticket-avatar');
    avatar.setAttribute('aria-hidden', 'true');
    article.append(avatar);
    header.append(text('strong', item.author_kind === 'ADMIN'
      ? tr('author.support', { name: item.author_name })
      : tr('author.user', { name: item.author_name })));
    header.append(text('span', date(item.created_at) + (item.visibility === 'STAFF' ? tr('message.internal') : '')));
    article.append(header);
    article.append(SupportFormat.render(item.body,item.body_format));
    article.append(SupportFormat.attachments(item.attachments, '/api/v2/support/attachments/'));
    messages.append(article);
  }
}

async function openTicket(id) {
  if (currentTicket && currentTicket.id !== id) {
    if (pendingReply) {
      report(tr('error.pending_upload'));
      return;
    }
    if ((replyContent().text || selectedFiles.length) && !window.confirm(tr('confirm.switch'))) return;
  }
  setBusy(true);
  try {
    const data = await api('/api/v2/support/tickets/' + id);
    if (currentTicket?.id !== id) {
      resetReply();
      selectedFiles = [];
      renderSelectedFiles();
    }
    currentTicket = data.ticket;
    renderMessages(data);
    const ticket = data.ticket;
    document.querySelector('#ticket-detail-title').textContent = ticket.subject;
    const summary = document.querySelector('#ticket-detail-summary');
    summary.replaceChildren(
      text('p', tr('summary.number', { ticket: ticket.id.slice(0, 8).toUpperCase() })),
      text('p', tr('summary.category', { category: categoryText[ticket.category] || ticket.category })),
      text('p', tr('summary.subject', { subject: ticket.subject })),
      text('p', tr('summary.created', { date: date(ticket.created_at) })),
    );
    if (ticket.order_no) summary.append(text('p', tr('summary.order', { order: ticket.order_no })));
    const progress = document.querySelector('#ticket-progress');
    progress.replaceChildren();
    for (const [index, [status, label]] of Object.entries(statusText).entries()) {
      const step = text('li', (index + 1) + '  ' + label);
      if (ticket.status === status) step.setAttribute('aria-current', 'step');
      progress.append(step);
    }
    const reply = replyState(ticket);
    document.querySelector('#ticket-reply-state').textContent = ticket.status === 'CLOSED'
      ? tr('reply.closed')
      : tr('reply.can_continue', { state: reply.label });
    replyForm.hidden = ticket.status === 'CLOSED';
    closeButton.hidden = ticket.status === 'CLOSED';
    document.querySelector('.support-upload').hidden = ticket.status === 'CLOSED';
    detail.hidden = false;
    detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    report('');
  } catch (error) {
    report(tr('error.open'));
  } finally {
    setBusy(false);
  }
}

replyForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!currentTicket || busy) return;
  const content = replyContent();
  if (!pendingReply && !content.text) { report(tr('error.body_required')); return; }
  if (!validateFiles()) return;
  setBusy(true);
  try {
    if (!pendingReply) {
      const result = await api('/api/v2/support/tickets/' + currentTicket.id + '/messages', {
        body: content.body,
        body_format: content.format,
      });
      pendingReply = { ticketId: currentTicket.id, messageId: result.message_id };
    }
    while (selectedFiles.length) {
      await upload(pendingReply.ticketId, pendingReply.messageId, selectedFiles[0]);
      savedAttachmentCount++;
      savedAttachmentBytes += selectedFiles[0].size;
      selectedFiles.shift();
      renderSelectedFiles();
    }
    pendingReply = null;
    resetReply();
    await openTicket(currentTicket.id);
    await load();
    report(tr('success.reply'));
  } catch (error) {
    report(pendingReply
      ? tr('error.reply_partial')
      : tr('error.reply'));
  } finally {
    setBusy(false);
  }
});

closeButton.addEventListener('click', async () => {
  if (!currentTicket || busy) return;
  if (pendingReply) { report(tr('error.upload_before_close')); return; }
  if (!window.confirm(tr('confirm.close'))) return;
  setBusy(true);
  try {
    await api('/api/v2/support/tickets/' + currentTicket.id + '/close', {});
    await openTicket(currentTicket.id);
    await load();
    report(tr('success.closed'));
  } catch (error) {
    report(tr('error.close'));
  } finally {
    setBusy(false);
  }
});

function validateFiles() {
  if (selectedFiles.some(file => file.size === 0 || file.size > 5 * 1024 * 1024)) {
    report(tr('error.file_size'));
    return false;
  }
  if (selectedFiles.length + savedAttachmentCount > 5) {
    report(tr('error.file_count'));
    return false;
  }
  if (selectedFiles.reduce((total, file) => total + file.size, savedAttachmentBytes) > 20 * 1024 * 1024) {
    report(tr('error.file_total'));
    return false;
  }
  return true;
}

function renderSelectedFiles() {
  const target = document.querySelector('#ticket-file-list');
  target.replaceChildren();
  selectedFiles.forEach((file, index) => {
    const row = text('li', file.name + ' (' + Math.ceil(file.size / 1024) + ' KB) ');
    const remove = text('button', tr('action.remove'));
    remove.type = 'button';
    remove.setAttribute('aria-label', tr('action.remove_file', { name: file.name }));
    remove.disabled = busy;
    remove.addEventListener('click', () => {
      selectedFiles.splice(index, 1);
      renderSelectedFiles();
    });
    row.append(remove);
    target.append(row);
  });
}

fileInput.addEventListener('change', () => {
  selectedFiles.push(...Array.from(fileInput.files || []));
  fileInput.value = '';
  renderSelectedFiles();
  validateFiles();
});

document.querySelector('#ticket-refresh').addEventListener('click', load);
document.querySelector('#ticket-detail-close').addEventListener('click', () => {
  detail.hidden = true;
});
document.querySelector('#ticket-previous').addEventListener('click', async () => {
  if (page <= 1 || busy) return;
  page--;
  await load();
});
document.querySelector('#ticket-next').addEventListener('click', async () => {
  if (busy) return;
  page++;
  await load();
});

// Refresh the shared list when the feedback form creates a ticket.
document.addEventListener('support:created', () => { page = 1; load(); });
document.querySelector('#ticket-search-form').addEventListener('submit', event => {
  event.preventDefault();
  if (busy) return;
  page = 1;
  load();
});
load().then(() => {
  const id = new URLSearchParams(location.search).get('ticket');
  if (/^[a-f0-9]{32}$/.test(id || '')) openTicket(id);
});
