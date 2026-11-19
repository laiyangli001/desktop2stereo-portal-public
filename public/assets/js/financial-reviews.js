export function createReviewDialog(api, changed) {
  const tr = (key, params = {}) => window.D2SI18n.tr('payments_admin.' + key, params);
  const locale = () => document.documentElement.lang || 'zh-Hans';
  const dialog = document.querySelector('#review-dialog');
  if (!dialog) return { open() {} };
  const get = id => dialog.querySelector('#review-' + id);
  const form = get('form'), fields = get('fields'), confirm = get('confirm');
  const action = get('action'), payment = get('payment'), reason = get('reason'), message = get('message');
  const statuses = { paid: 'status.paid', partially_refunded: 'status.partially_refunded', refunded: 'status.refunded', payment_review: 'status.payment_review' };
  let order, page = 1, busy = false, draft = null;
  const money = value => new Intl.NumberFormat(locale(), { style: 'currency', currency: 'CNY' }).format(value / 100);
  const channel = () => tr(order?.payment_method === 'wechat_native' ? 'channel.wechat' : 'channel.alipay');
  const status = value => statuses[value] ? tr(statuses[value]) : value;
  function item(list, value) { const li = document.createElement('li'); li.textContent = value; list.append(li); }
  function lock(value) {
    busy = value;
    dialog.setAttribute('aria-busy', String(value));
    for (const button of dialog.querySelectorAll('button')) button.disabled = value;
    fields.disabled = value || draft !== null;
    get('check').hidden = draft !== null;
  }
  function resetDraft() {
    draft = null; confirm.hidden = true; fields.disabled = false; get('check').hidden = false;
    payment.disabled = action.value !== 'retry_fulfillment';
  }
  async function load() {
    const data = await api('/api/v2/admin/payments/orders/' + order.id + '/review?page=' + page);
    get('order').textContent = tr('finance.order_summary', { channel: channel(), order: order.order_no || order.id, status: status(data.order.status) });
    form.hidden = data.order.status !== 'payment_review';
    get('receipts').replaceChildren();
    payment.replaceChildren();
    for (const receipt of data.receipts) {
      const label = tr('finance.receipt_label', { id: receipt.id.slice(0, 12), amount: money(receipt.amount), refunded: money(receipt.refunded), unresolved: receipt.unresolved });
      item(get('receipts'), label);
      const option = document.createElement('option'); option.value = receipt.id; option.textContent = label;
      option.disabled = receipt.refunded !== 0 || receipt.unresolved !== 0;
      payment.append(option);
    }
    const available = [...payment.options].find(option => !option.disabled);
    payment.value = available ? available.value : '';
    item(get('receipts'), tr(data.fulfillment ? 'finance.fulfillment_existing' : 'finance.fulfillment_missing'));
    get('history').replaceChildren();
    for (const row of data.history) {
      item(get('history'), tr('finance.history_entry', { date: new Date(Number(row.created_at) * 1000).toLocaleString(locale()),
        action: tr(row.action === 'retry_fulfillment' ? 'finance.action.retry_fulfillment' : 'finance.action.close_case'),
        status: status(row.result_status), admin: row.operator_id, reason: row.reason }));
    }
    if (!data.history.length) item(get('history'), tr('finance.no_history'));
    get('page').textContent = tr('finance.page_summary', { page, total: data.total });
    get('previous').dataset.boundary = String(page === 1);
    get('next').dataset.boundary = String(page * data.per_page >= data.total);
  }
  function boundaries() {
    get('previous').disabled = busy || get('previous').dataset.boundary === 'true';
    get('next').disabled = busy || get('next').dataset.boundary === 'true';
  }
  async function refresh(delta = 0) {
    if (busy) return;
    lock(true); page += delta; resetDraft(); fields.disabled = true;
    try { await load(); } catch (error) { page -= delta; message.textContent = error.message; }
    finally { lock(false); boundaries(); }
  }
  action.addEventListener('change', () => { payment.disabled = action.value !== 'retry_fulfillment'; });
  form.addEventListener('submit', event => {
    event.preventDefault(); if (busy || draft || !form.reportValidity()) return;
    if (action.value === 'retry_fulfillment' && !payment.value) { message.textContent = tr('finance.no_payment_available'); return; }
    draft = { action: action.value, payment_id: action.value === 'retry_fulfillment' ? payment.value : null,
      reason: reason.value.trim(), idempotency_key: crypto.randomUUID().replaceAll('-', ''), confirm_resolution: true };
    const actionLabel = draft.action === 'retry_fulfillment'
      ? tr(order.product === 'wallet_recharge' ? 'finance.action.confirm_recharge' : 'finance.action.retry_fulfillment')
      : tr('finance.action.close_case');
    get('summary').textContent = tr('finance.confirm_summary', { channel: channel(), action: actionLabel,
      order: order.order_no || order.id, reason: draft.reason });
    fields.disabled = true; confirm.hidden = false; get('check').hidden = true;
    get('back').focus();
  });
  get('back').addEventListener('click', () => { if (!busy) { resetDraft(); reason.focus(); } });
  get('submit').addEventListener('click', async () => {
    if (busy || !draft) return;
    lock(true);
    try {
      const result = await api('/api/v2/admin/payments/orders/' + order.id + '/review', draft);
      message.textContent = tr('finance.completed', { status: status(result.status) });
      resetDraft(); fields.disabled = true;
      await load(); await changed();
    } catch (error) { message.textContent = error.message; }
    finally { lock(false); boundaries(); }
  });
  for (const [id, delta] of [['previous', -1], ['next', 1], ['refresh', 0]]) get(id).addEventListener('click', () => refresh(delta));
  get('close').addEventListener('click', () => { if (!busy) dialog.close(); });
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  return { async open(value) {
    if (busy || dialog.open) return;
    order = value; page = 1; reason.value = ''; action.value = 'close_case'; message.textContent = '';
    const retry = action.querySelector('option[value="retry_fulfillment"]');
    if (retry) retry.textContent = tr(order.product === 'wallet_recharge' ? 'finance.action.confirm_recharge' : 'finance.action.retry_fulfillment');
    resetDraft(); form.hidden = true; get('receipts').replaceChildren(); get('history').replaceChildren();
    dialog.showModal(); get('close').focus(); await refresh();
  } };
}
