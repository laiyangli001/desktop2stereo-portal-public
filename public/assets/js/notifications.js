const root = document.querySelector('#notifications');
const admin = root.dataset.admin === 'true';
const locale = document.documentElement.lang || 'en';
const tr = (key, params = {}) => window.D2SI18n.tr('notifications.' + key, params);
const message = document.querySelector('#notification-message');
const list = document.querySelector('#notification-list');
const previous = document.querySelector('#notification-previous'), next = document.querySelector('#notification-next');
const filter = document.querySelector('#notification-status'), dialog = document.querySelector('#notification-retry');
let busy = false, page = 1, stack = [null], cursor = null, more = false, retry = null;
const titles = {
  ORDER_PAID: tr('event.order_paid'),
  PAYMENT_REVIEW: tr('event.payment_review'),
  REFUND_CONFIRMED: tr('event.refund_confirmed')
};
const statuses = {
  pending: tr('status.pending'),
  processing: tr('status.processing'),
  retry: tr('status.retry'),
  sent: tr('status.sent'),
  blocked: tr('status.blocked'),
  failed: tr('status.failed')
};
const paymentMethods = admin ? {} : {
  wallet: tr('transactions.wallet'),
  alipay_pc: tr('transactions.alipay'),
  wechat_native: tr('transactions.wechat'),
  paypal: 'PayPal',
  stripe: 'Stripe'
};
const deliveryErrors = {
  notification_channel_unconfigured: tr('error.channel_unconfigured'),
  notification_recipient_missing: tr('error.recipient_missing'),
  notification_expired: tr('error.expired'),
  notification_attempt_limit: tr('error.attempt_limit'),
  notification_delivery_failed: tr('error.delivery_failed')
};
const errors = {
  permission_denied: tr('error.permission_denied'),
  notification_not_found: tr('error.notification_not_found'),
  notification_retry_not_allowed: tr('error.retry_not_allowed'),
  notification_retry_confirmation_required: tr('error.retry_confirmation_required'),
  idempotency_conflict: tr('error.idempotency_conflict'),
  history_cursor_invalid: tr('error.history_cursor_invalid'),
  service_unavailable: tr('error.service_unavailable')
};
async function api(path, data) {
  const prefix = admin ? 'D2S_ADMIN_SESSION_CSRF=' : 'D2S_USER_SESSION_CSRF=';
  const cookie = document.cookie.split('; ').find(value => value.startsWith(prefix));
  const headers = { 'X-D2S-Locale': locale };
  const options = { credentials: 'same-origin', headers };
  if (data !== undefined) {
    options.method = 'POST';
    options.headers = { ...headers, 'Content-Type': 'application/json',
      'X-CSRF-Token': cookie ? decodeURIComponent(cookie.slice(prefix.length)) : '' };
    options.body = JSON.stringify(data);
  }
  const response = await fetch(path, options);
  const body = await response.json();
  if (!response.ok || !body.success) throw new Error(errors[body.error] || tr('error.generic'));
  return body.data;
}
function text(tag, value) { const node = document.createElement(tag); node.textContent = value; return node; }
function buttons() {
  previous.disabled = busy || page === 1; next.disabled = busy || !more;
  document.querySelector('#notification-refresh').disabled = busy;
  if (filter) filter.disabled = busy;
  root.setAttribute('aria-busy', String(busy));
  for (const button of list.querySelectorAll('button')) button.disabled = busy;
  if (dialog) for (const button of dialog.querySelectorAll('button')) button.disabled = busy;
}
async function task(work) {
  if (busy) return; busy = true; buttons();
  try { await work(); } catch (error) { message.textContent = error.message; }
  finally { busy = false; buttons(); }
}
async function render(position = cursor, targetPage = page) {
  const data = await api(admin ? '/api/v2/admin/notifications?page=' + targetPage + (filter.value ? '&status=' + filter.value : '') :
    '/api/v2/notifications/transactions' + (position ? '?cursor=' + encodeURIComponent(position) : ''));
  cursor = position; page = targetPage;
  stack = stack.slice(0, page - 1); stack.push(position);
  more = admin ? page * data.per_page < data.total : typeof data.next_cursor === 'string';
  next.dataset.cursor = admin ? '' : data.next_cursor || '';
  list.replaceChildren();
  if (!admin) {
    for (const item of data.payments) {
      const amount = new Intl.NumberFormat(locale, {
        style: 'currency', currency: item.currency, currencyDisplay: 'code'
      }).format(Number(item.amount) / 100);
      const row = document.createElement('tr');
      row.append(text('td', item.order_no), text('td', amount),
        text('td', paymentMethods[item.payment_method] ?? '—'),
        text('td', new Date(Number(item.paid_at) * 1000).toLocaleString(locale)));
      list.append(row);
    }
    if (!data.payments.length) {
      const row = document.createElement('tr'), cell = text('td', tr('transactions.empty'));
      cell.colSpan = 4; row.append(cell); list.append(row);
    }
    document.querySelector('#notification-page').textContent = tr('transactions.page', { page });
    return;
  }
  for (const item of data.deliveries) {
    const card = document.createElement('article'); card.className = 'portal-card';
    card.append(text('h2', titles[item.event_type] || tr('event.unknown')));
    card.append(text('p', tr('label.order', { order: item.order_no || item.order_id })));
    card.append(text('p', new Date(Number(item.created_at) * 1000).toLocaleString(locale)));
    card.append(text('p', (statuses[item.status] || statuses.failed) + ' · ' +
      tr('label.attempts', { count: Number(item.total_attempts) })));
    if (item.last_error) card.append(text('p', deliveryErrors[item.last_error] || tr('error.unknown_delivery')));
    if (['pending', 'retry'].includes(item.status)) {
      card.append(text('p', tr('label.next_attempt', {
        date: new Date(Number(item.available_at) * 1000).toLocaleString(locale)
      })));
    }
    if (['blocked', 'failed'].includes(item.status)) {
      const button = text('button', tr('retry.button')); button.type = 'button';
      button.setAttribute('aria-label', tr('retry.button_aria', { id: item.id }));
      button.addEventListener('click', () => {
        if (busy) return;
        retry = { id: item.id, key: crypto.randomUUID() };
        document.querySelector('#notification-retry-summary').textContent =
          tr('retry.summary', { order: item.order_no || item.order_id });
        document.querySelector('#notification-reason').value = ''; dialog.showModal();
      }); card.append(button);
    }
    list.append(card);
  }
  if (!list.childElementCount) list.append(text('p', tr('empty')));
  document.querySelector('#notification-page').textContent = tr('page.admin', { page, count: Number(data.total) });
}
next.addEventListener('click', () => task(() => render(admin ? null : next.dataset.cursor, page + 1)));
previous.addEventListener('click', () => task(() => render(admin ? null : stack[page - 2], page - 1)));
document.querySelector('#notification-refresh').addEventListener('click', () => task(() => render(null, 1)));
filter?.addEventListener('change', () => task(() => render(null, 1)));
document.querySelector('#notification-retry-form')?.addEventListener('submit', event => {
  event.preventDefault();
  task(async () => {
    if (!retry) return;
    await api('/api/v2/admin/notifications/' + retry.id + '/retry', {
      reason: document.querySelector('#notification-reason').value.trim(),
      idempotency_key: retry.key, confirm_retry: true
    });
    dialog.close(); message.textContent = tr('retry.success'); await render();
  });
});
document.querySelector('#notification-cancel')?.addEventListener('click', () => { if (!busy) dialog.close(); });
dialog?.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
task(() => render());
