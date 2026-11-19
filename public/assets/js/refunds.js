import { createRefundReviewPanel } from './refund-reviews.js';

const tr = (key, params = {}) => window.D2SI18n.tr('payments_admin.' + key, params);
const locale = () => document.documentElement.lang || 'zh-Hans';

export function createRefundDialog(api, changed) {
  const dialog = document.querySelector('#refund-dialog');
  if (!dialog) return { open() {} };
  const find = id => dialog.querySelector('#' + id);
  const form = find('refund-form');
  const fields = find('refund-fields');
  const payment = find('refund-payment');
  const amount = find('refund-amount');
  const reason = find('refund-reason');
  const notice = find('refund-message');
  const confirmation = find('refund-confirm');
  const review = find('refund-review');
  const previous = find('refund-previous');
  const next = find('refund-next');
  let order = null;
  let page = 1;
  let draft = null;
  let busy = false;
  let data = null;
  const controls = createRefundReviewPanel(api, refresh, task);
  const money = minor => new Intl.NumberFormat(locale(), { style: 'currency', currency: order?.currency || 'CNY' }).format(Number(minor) / 100);
  const wallet = () => order?.payment_method === 'wallet';
  const wechat = () => order?.payment_method === 'wechat_native';
  const channel = () => tr(wallet() ? 'payment.wallet' : wechat() ? 'channel.wechat' : 'channel.alipay');
  const refundStatuses = { pending: 'refund.status.pending', unknown: 'refund.status.unknown', succeeded: 'refund.status.succeeded', processing: 'refund.status.processing', abnormal: 'refund.status.abnormal', closed: 'refund.status.closed', cancelled: 'refund.status.cancelled' };
  const refundStatus = value => refundStatuses[value] ? tr(refundStatuses[value]) : value;
  const endpoint = () => '/api/v2/admin/payments/orders/' + order.id + (wallet() ? '/wallet-refunds' : wechat() ? '/wechat-refunds' : '/refunds');
  function node(tag, value) {
    const element = document.createElement(tag);
    element.textContent = value;
    return element;
  }
  function updateButtons() {
    for (const button of dialog.querySelectorAll('button')) button.disabled = busy || button.dataset.unavailable === 'true';
    dialog.setAttribute('aria-busy', String(busy));
  }
  async function task(work) {
    if (busy) return;
    busy = true;
    updateButtons();
    try { await work(); } catch (error) { notice.textContent = error.message; }
    finally { busy = false; updateButtons(); }
  }
  function selectAmount() {
    const selected = payment.selectedOptions[0];
    amount.max = selected ? (Number(selected.dataset.remaining) / 100).toFixed(2) : '';
    amount.value = amount.max;
  }
  async function refresh() {
    data = await api(endpoint() + '?page=' + page);
    payment.replaceChildren();
    for (const receipt of data.payments) {
      const remaining = Number(receipt.amount) - Number(receipt.reserved_amount);
      if (remaining <= 0 || Number(receipt.has_pending)) continue;
      const option = node('option', tr(wallet() ? 'refund.payment_wallet' : (Number(receipt.fulfills_license) ? 'refund.payment_license' : 'refund.payment_other')) + ' · ' + tr('refund.available_amount', { amount: money(remaining) }) + ' · ' + receipt.id.slice(0, 8));
      option.value = receipt.id;
      option.dataset.remaining = String(remaining);
      payment.append(option);
    }
    form.hidden = payment.options.length === 0;
    if (!draft) {
      fields.disabled = false;
      confirmation.hidden = true;
      review.hidden = false;
      selectAmount();
    }
    const history = find('refund-history');
    history.replaceChildren();
    for (const refund of data.refunds) {
      const item = node('li', money(refund.amount) + ' · ' + refundStatus(refund.status) + ' · ' + refund.reason + ' · ' + tr('refund_review.number', { id: refund.id }));
      if (wechat() && refund.payer_refund !== null && refund.payer_refund !== undefined) item.append(node('span', tr('refund.payer_cash', { amount: money(refund.payer_refund) })));
      if (!['succeeded', 'cancelled'].includes(refund.status)) {
        const button = node('button', tr(refund.retry_mode === 'query_only' ? 'refund.action.query_only' : 'refund.action.process'));
        button.type = 'button';
        button.addEventListener('click', () => task(async () => {
          const result = await api('/api/v2/admin/payments/' + (wechat() ? 'wechat-refunds/' : 'refunds/') + refund.id + '/process', {});
          notice.textContent = result.status === 'succeeded' ? tr('refund.status.succeeded') : tr('refund.status.unknown');
          await refresh();
        }));
        item.append(button);
      }
      if (!wallet() && controls.available) {
        const button = node('button', tr('refund.action.review'));
        button.type = 'button';
        button.setAttribute('aria-label', tr('refund.action.review') + ' ' + refund.id);
        button.addEventListener('click', () => task(() => controls.open(refund.id, order.payment_method, order.currency)));
        item.append(button);
      }
      history.append(item);
    }
    if (!data.refunds.length) history.append(node('li', tr('refund.empty')));
    previous.dataset.unavailable = String(page === 1);
    next.dataset.unavailable = String(page * data.per_page >= data.total);
    find('refund-page').textContent = tr('refund.page_summary', { page, total: data.total });
    updateButtons();
  }
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (busy || !form.reportValidity()) return;
    const match = /^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/.exec(amount.value);
    const minor = match ? Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0')) : 0;
    if (!minor || minor > Number(payment.selectedOptions[0]?.dataset.remaining)) {
      notice.textContent = tr('refund.amount_invalid');
      return;
    }
    draft = { amount: minor, reason: reason.value.trim(), idempotency_key: crypto.randomUUID(), confirm_refund: true };
    if (!wallet()) draft.payment_id = payment.value;
    find('refund-summary').textContent = tr('refund.summary', { channel: channel(), receipt: payment.selectedOptions[0].textContent, amount: money(minor), reason: draft.reason });
    fields.disabled = true;
    review.hidden = true;
    confirmation.hidden = false;
    find('refund-submit').focus();
  });
  find('refund-back').addEventListener('click', () => {
    if (busy) return;
    draft = null;
    fields.disabled = false;
    review.hidden = false;
    confirmation.hidden = true;
    amount.focus();
  });
  find('refund-submit').addEventListener('click', () => task(async () => {
    if (!draft) return;
    await api(endpoint(), draft);
    draft = null;
    reason.value = '';
    notice.textContent = wallet() ? tr('refund.success_wallet') : tr('refund.success_pending');
    await refresh();
  }));
  for (const [button, delta] of [[previous, -1], [next, 1]]) {
    button.addEventListener('click', () => task(async () => {
      page += delta;
      try { await refresh(); } catch (error) { page -= delta; throw error; }
    }));
  }
  payment.addEventListener('change', selectAmount);
  find('refund-refresh').addEventListener('click', () => task(() => refresh()));
  find('refund-close').addEventListener('click', () => { if (!busy) dialog.close(); });
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  dialog.addEventListener('close', () => { changed(); });
  return {
    open(value) {
      if (busy) return;
      controls.reset();
      order = value;
      find('refund-title').textContent = tr('refund.title', { channel: channel() });
      reason.maxLength = wechat() ? 80 : 256;
      dialog.querySelector('label[for="refund-amount"]').textContent = tr(order.currency === 'USD' ? 'refund.amount_usd' : 'refund.amount_cny');
      dialog.querySelector('label[for="refund-payment"]').textContent = tr(wallet() ? 'refund.destination' : 'refund.payment_label');
      find('refund-channel-help').textContent = tr(wallet() ? 'refund.wallet_help' : wechat() ? 'refund.wechat_help' : 'refund.alipay_help');
      page = 1;
      draft = null;
      reason.value = '';
      notice.textContent = tr('refund.loading');
      find('refund-order').textContent = tr('orders.order_label', { order: order.order_no || order.id });
      form.hidden = true;
      dialog.showModal();
      task(async () => { await refresh(); notice.textContent = data.payments.length ? tr('refund.check_amount') : tr('refund.none_verified'); });
    },
  };
}
