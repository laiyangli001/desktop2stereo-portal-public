'use strict';

(async () => {
  const tr = (key, parameters = {}) => window.D2SI18n.tr('support.' + key, parameters);
  const root = document.querySelector('#admin-ticket-app');
  if (!root) return;
  const message = document.querySelector('#admin-ticket-message');
  const list = document.querySelector('#admin-ticket-list');
  const detail = document.querySelector('#admin-ticket-detail');
  const messages = document.querySelector('#admin-ticket-messages');
  const replyForm = document.querySelector('#admin-ticket-reply-form');
  const replyBody = document.querySelector('#admin-ticket-reply');
  const replyVisibility = document.querySelector('#admin-ticket-visibility');
  const replyStatus = document.querySelector('#admin-ticket-next-status');
  const replyDrafts = new Map();

  function keepReplyDraft() {
    if (!current || !replyForm) return;
    replyDrafts.set(current.id, {body: replyBody.value, visibility: replyVisibility.value, status: replyStatus.value});
  }

  function restoreReplyDraft(id) {
    if (!replyForm) return;
    replyForm.reset();
    const draft = replyDrafts.get(id);
    if (draft) {
      replyBody.value = draft.body;
      replyVisibility.value = draft.visibility;
      replyStatus.value = draft.status;
    }
    replyBody.dispatchEvent(new Event('input', {bubbles: true}));
  }
  // Retry only the trusted formatter asset; no ticket action starts without this dependency.
  async function obtainSupportFormat() {
    const ready = () => {
      const value = window.SupportFormat;
      return value && ['render', 'enhance', 'attachments'].every(name => typeof value[name] === 'function') ? value : null;
    };
    if (ready()) return ready();
    root.setAttribute('aria-busy', 'true');
    const originalScript = [...document.scripts].find(script => {
      if (!script.src) return false;
      const url = new URL(script.src, window.location.href);
      return url.origin === window.location.origin && url.pathname === '/assets/js/support-format.js';
    });
    if (!originalScript) throw new Error('support_formatter_unavailable');
    const url = new URL(originalScript.src);
    url.searchParams.set('_dependency_retry', '1');
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      let completed = false;
      const finish = (error) => {
        if (completed) return;
        completed = true;
        window.clearTimeout(timer);
        script.onload = script.onerror = null;
        script.remove();
        const formatter = ready();
        if (!formatter) reject(error || new Error('support_formatter_unavailable'));
        else resolve(formatter);
      };
      const timer = window.setTimeout(() => finish(new Error('support_formatter_unavailable')), 5000);
      script.onload = () => finish();
      script.onerror = () => finish(new Error('support_formatter_unavailable'));
      script.src = url.href;
      script.async = false;
      document.head.append(script);
    });
  }

  const formatter = await obtainSupportFormat().catch(() => {
    root.setAttribute('aria-busy', 'false');
    report(tr('admin.load_error'));
    for (const control of root.querySelectorAll('button,input,select,textarea')) control.disabled = true;
    const refresh = document.querySelector('#admin-ticket-refresh');
    refresh.disabled = false;
    refresh.addEventListener('click', () => window.location.reload());
    return null;
  });
  if (!formatter) return;

  let page = 1;
  let current = null;
  let busy = false;
  const statusText = { OPEN: tr('status.open'), IN_PROGRESS: tr('status.in_progress'), RESOLVED: tr('status.resolved'), CLOSED: tr('status.closed') };
  const categoryText = {
    BUG: tr('category.bug'), PURCHASE: tr('category.purchase'), LICENSE: tr('category.license'),
    DOWNLOAD: tr('category.download'), ACCOUNT: tr('category.account'), SOFTWARE: tr('category.software'),
    SUGGESTION: tr('category.suggestion'), OTHER: tr('category.other'),
  };

  function csrf() {
    const prefix = 'D2S_ADMIN_SESSION_CSRF=';
    const item = document.cookie.split('; ').find((value) => value.startsWith(prefix));
    return item ? decodeURIComponent(item.slice(prefix.length)) : '';
  }

  function report(value) { message.textContent = value; }

  function text(tag, value, className = '') {
    const node = document.createElement(tag);
    node.textContent = value;
    if (className) node.className = className;
    return node;
  }

  function date(value) { return new Date(Number(value) * 1000).toLocaleString(document.documentElement.lang); }

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

  function setBusy(value) {
    busy = value;
    root.setAttribute('aria-busy', String(value));
    document.querySelector('#admin-ticket-status').disabled = value;
    for (const button of list.querySelectorAll('button')) button.disabled = value;
    for (const control of replyForm?.querySelectorAll('button,textarea,select') || []) control.disabled = value;
  }

  function renderTickets(data) {
    list.replaceChildren();
    if (data.items.length === 0) list.append(text('p', tr('admin.empty')));
    for (const ticket of data.items) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'support-item';
      button.append(text('h3', ticket.subject));
      button.append(text('p', tr('admin.ticket_item', { ticket: ticket.id.slice(0, 8), user: ticket.username,
        category: categoryText[ticket.category] || ticket.category })));
      button.append(text('span', statusText[ticket.status] || ticket.status,
        'support-status ' + ticket.status.toLowerCase()));
      button.append(text('p', tr('admin.contact', { contact: ticket.contact_value }) +
        (ticket.order_no ? tr('admin.order_suffix', { order: ticket.order_no }) : '')));
      button.append(text('p', tr('admin.updated', { date: date(ticket.updated_at), messages: ticket.message_count,
        attachments: ticket.attachment_count })));
      button.addEventListener('click', () => openTicket(ticket.id));
      list.append(button);
    }
    document.querySelector('#admin-ticket-page').textContent = tr('admin.page_summary', { page: data.page, count: data.total });
    document.querySelector('#admin-ticket-previous').disabled = data.page <= 1;
    document.querySelector('#admin-ticket-next').disabled = data.page * data.per_page >= data.total;
  }

  async function load() {
    setBusy(true);
    try {
      const status = document.querySelector('#admin-ticket-status').value;
      renderTickets(await api('/api/v2/admin/tickets?page=' + page + (status ? '&status=' + status : '')));
      report('');
    } catch (error) {
      report(error.message === 'permission_denied' ? tr('admin.no_permission') : tr('admin.load_error'));
    } finally {
      setBusy(false);
    }
  }

  function renderMessages(items) {
    messages.replaceChildren();
    for (const item of items) {
      const article = document.createElement('article');
      article.className = 'support-message' + (item.visibility === 'STAFF' ? ' staff' : '');
      const header = document.createElement('header');
      header.append(text('strong', item.author_kind === 'ADMIN'
        ? tr('admin.author.support', { name: item.author_name })
        : tr('admin.author.user', { name: item.author_name })));
      header.append(text('span', date(item.created_at) + (item.visibility === 'STAFF' ? tr('admin.internal_only') : '')));
      article.append(header, formatter.render(item.body,item.body_format));
      article.append(formatter.attachments(item.attachments, '/api/v2/admin/tickets/attachments/'));
      messages.append(article);
    }
  }

  async function openTicket(id) {
    setBusy(true);
    try {
      const data = await api('/api/v2/admin/tickets/' + id);
      if (current?.id !== data.ticket.id) {
        keepReplyDraft();
        restoreReplyDraft(data.ticket.id);
      }
      current = data.ticket;
      renderMessages(data.messages);
      document.querySelector('#admin-ticket-detail-title').textContent = data.ticket.subject;
      document.querySelector('#admin-ticket-summary').textContent = tr('admin.summary', {
        user_id: data.ticket.user_id,
        username: data.ticket.username,
        status: statusText[data.ticket.status] || data.ticket.status,
        contact: data.ticket.contact_value,
        order: data.ticket.order_no ? tr('admin.order_suffix', { order: data.ticket.order_no }) : '',
      });
      if (replyForm) replyForm.hidden = data.ticket.status === 'CLOSED';
      detail.hidden = false;
      detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
      report('');
    } catch (error) {
      report(tr('admin.detail_error'));
    } finally {
      setBusy(false);
    }
  }

  formatter.enhance(replyBody);

  replyForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!current || busy) return;
    const id = current.id;
    const status = replyStatus.value;
    setBusy(true);
    try {
      await api('/api/v2/admin/tickets/' + id + '/reply', {
        body: replyBody.value,
        visibility: replyVisibility.value,
        ...(status ? { status } : {}),
      });
      replyDrafts.delete(id);
      restoreReplyDraft(id);
      await openTicket(id);
      await load();
      report(tr('admin.saved'));
    } catch (error) {
      report(tr('admin.submit_error'));
    } finally {
      setBusy(false);
    }
  });

  document.querySelector('#admin-ticket-refresh').addEventListener('click', () => { if (!busy) load(); });
  document.querySelector('#admin-ticket-status').addEventListener('change', () => { if (!busy) { page = 1; load(); } });
  document.querySelector('#admin-ticket-detail-close').addEventListener('click', () => {
    if (busy) return;
    keepReplyDraft();
    detail.hidden = true;
    current = null;
  });
  document.querySelector('#admin-ticket-previous').addEventListener('click', () => {
    if (page > 1 && !busy) { page--; load(); }
  });
  document.querySelector('#admin-ticket-next').addEventListener('click', () => {
    if (!busy) { page++; load(); }
  });

  load();
})();
