import { createRefundDialog } from './refunds.js';
import { createReviewDialog } from './financial-reviews.js';

const tr = (key, params = {}) => window.D2SI18n.tr('payments_admin.' + key, params);
const locale = () => document.documentElement.lang || 'zh-Hans';

const root = document.querySelector('#payment-orders');
const list = document.querySelector('#payment-order-list');
const message = document.querySelector('#payment-message');
const previous = document.querySelector('#previous-page');
const next = document.querySelector('#next-page');
const dialog = document.querySelector('#close-confirmation');
let page = 1;
let csrf = '';
let busy = false;
const refunds = createRefundDialog(api, () => render().catch(error => { message.textContent = error.message; }));
const reviews = createReviewDialog(api, () => render().catch(error => { message.textContent = error.message; }));
const filter = document.querySelector('#order-filter');
const requestedStatus = new URLSearchParams(location.search).get('status');
if ([...filter.options].some(option => option.value === requestedStatus)) filter.value = requestedStatus;

const statuses = { pending: 'status.pending', paid: 'status.paid', cancelled: 'status.cancelled', payment_review: 'status.payment_review', refund_pending: 'status.refund_pending', partially_refunded: 'status.partially_refunded', refunded: 'status.refunded' };
const statusText = value => statuses[value] ? tr(statuses[value]) : value;
const paymentMethods = {alipay_pc: tr('channel.alipay'), wechat_native: tr('channel.wechat'),
  wallet: tr('payment.wallet'), paypal: 'PayPal', stripe: 'Stripe'};
const errors = {
  permission_denied: tr('error.permission_denied'),
  order_not_in_review: tr('error.order_not_in_review'),
  payment_review_input_invalid: tr('error.payment_review_input_invalid'),
  payment_review_confirmation_required: tr('error.payment_review_confirmation_required'),
  payment_review_refunds_pending: tr('error.payment_review_refunds_pending'),
  payment_review_receipts_unresolved: tr('error.payment_review_receipts_unresolved'),
  payment_review_cannot_fulfill: tr('error.payment_review_cannot_fulfill'),
  account_restricted_or_unverified: tr('error.account_restricted_or_unverified'),
  payment_review_platform_unconfirmed: tr('error.payment_review_platform_unconfirmed'),
  payment_review_evidence_invalid: tr('error.payment_review_evidence_invalid'),
  alipay_identity_mismatch: tr('error.alipay_identity_mismatch'),
  refund_pending: tr('error.refund_pending'),
  refund_review_terminal: tr('error.refund_review_terminal'),
  refund_review_busy: tr('error.refund_review_busy'),
  refund_already_attempted: tr('error.refund_already_attempted'),
  refund_review_mode_unchanged: tr('error.refund_review_mode_unchanged'),
  refund_review_evidence_invalid: tr('error.refund_review_evidence_invalid'),
  refund_review_input_invalid: tr('error.refund_review_input_invalid'),
  refund_review_confirmation_required: tr('error.refund_review_confirmation_required'),
  order_not_refundable: tr('error.order_not_refundable'),
  wallet_purchase_not_found: tr('error.wallet_purchase_not_found'),
  wallet_refund_history_mismatch: tr('error.wallet_refund_history_mismatch'),
  wallet_balance_limit: tr('error.wallet_balance_limit'),
  wallet_recharge_refund_insufficient: tr('error.wallet_recharge_refund_insufficient'),
  refund_amount_exceeded: tr('error.refund_amount_exceeded'),
  idempotency_conflict: tr('error.idempotency_conflict'),
  refund_input_invalid: tr('error.refund_input_invalid'),
  alipay_refund_mismatch: tr('error.alipay_refund_mismatch'),
  payment_fulfillment_review_required: tr('error.payment_fulfillment_review_required'),
  order_not_cancellable: tr('error.order_not_cancellable'),
  alipay_transport_unknown: tr('error.alipay_transport_unknown'),
  alipay_close_unknown: tr('error.alipay_close_unknown'),
  alipay_payment_mismatch: tr('error.alipay_payment_mismatch'),
  payment_attempt_not_found: tr('error.payment_attempt_not_found'),
  wechat_order_busy: tr('error.wechat_order_busy'),
  wechat_close_unknown: tr('error.wechat_close_unknown'),
  wechat_provider_unknown: tr('error.wechat_provider_unknown'),
  wechat_payment_mismatch: tr('error.wechat_payment_mismatch'),
  wechat_signature_invalid: tr('error.wechat_signature_invalid'),
  wechat_refund_mismatch: tr('error.wechat_refund_mismatch'),
  wechat_refund_conflict: tr('error.wechat_refund_conflict'),
  wechat_transport_unknown: tr('error.wechat_transport_unknown'),
  refund_lease_lost: tr('error.refund_lease_lost'),
  service_unavailable: tr('error.service_unavailable'),
};
const messageForError = code => errors[code] || tr('error.default');
async function api(path, data) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    ...(data === undefined ? {} : {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(data),
    }),
  });
  const body = await response.json();
  if (!response.ok || !body.success) throw new Error(messageForError(body.error));
  return body.data;
}
function text(tag, value) {
  const node = document.createElement(tag);
  node.textContent = value;
  return node;
}
function setBusy(value) {
  busy = value;
  root.setAttribute('aria-busy', String(value));
  filter.disabled = value;
  for (const button of list.querySelectorAll('button')) button.disabled = value;
}
async function operate(order, operation, button) {
  if (busy) return;
  if (operation === 'close') {
    document.querySelector('#close-description').textContent = tr('action.close_description', { order: order.order_no || order.id,
      channel: tr(order.payment_method === 'wechat_native' ? 'channel.wechat' : 'channel.alipay'),
      amount: new Intl.NumberFormat(locale(), { style: 'currency', currency: order.currency }).format(Number(order.amount) / 100) });
    dialog.returnValue = '';
    dialog.showModal();
    const confirmed = await new Promise((resolve) => dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true }));
    if (!confirmed) return;
  }
  setBusy(true);
  message.textContent = tr('message.checking_provider');
  try {
    const result = await api('/api/v2/admin/payments/orders/' + order.id + '/' + operation,
      operation === 'close' ? { confirm_close: true } : {});
    message.textContent = (['UNKNOWN', 'NOT_FOUND'].includes(result.provider_status) || ['unknown', 'pending'].includes(result.close_status))
      ? tr('message.provider_uncertain')
      : tr('message.reconciled', { status: statusText(result.status) });
    await render();
  } catch (error) {
    message.textContent = error.message;
    button.disabled = false;
  } finally {
    setBusy(false);
  }
}
async function render() {
  const data = await api('/api/v2/admin/payments/orders?page=' + page + (filter.value ? '&status=' + encodeURIComponent(filter.value) : ''));
  list.replaceChildren();
  if (data.orders.length === 0) list.append(text('p', tr('orders.empty')));
  for (const order of data.orders) {
    const card = document.createElement('article');
    card.className = 'portal-card';
    card.append(text('h2', statusText(order.status)));
    card.append(text('p', tr('orders.order_label', { order: order.order_no || order.id })));
    card.append(text('p', tr('orders.account_label', { user: order.user_id, email: order.email ? ' · ' + order.email : '' })));
    let product = order.product;
    if (order.product === 'wallet_recharge') product = tr('product.wallet_recharge');
    else if (order.product_title && order.product_title !== order.product) product = order.product_title + ' (' + order.product + ')';
    card.append(text('p', new Intl.NumberFormat(locale(), { style: 'currency', currency: order.currency }).format(Number(order.amount) / 100)
      + ' · ' + product + ' · ' + (paymentMethods[order.payment_method] || order.payment_method)));
    if (['paypal', 'stripe'].includes(order.payment_method)) {
      const link = text('a', window.D2SI18n.tr('operations.load.diagnostics'));
      link.href = '/admin/order-search?' + new URLSearchParams({q: order.order_no || order.id});
      card.append(link);
    }
    card.append(text('p', tr('orders.created_at', { date: new Date(Number(order.created_at) * 1000).toLocaleString(locale()) })));
    if (['alipay_pc', 'wechat_native'].includes(order.payment_method)) {
      for (const operation of ['query', 'close']) {
        if (operation === 'query' && root.dataset.canQuery !== 'true') continue;
        if (operation === 'close' && (root.dataset.canClose !== 'true' || order.status !== 'pending')) continue;
        const button = text('button', operation === 'query' ? tr(order.payment_method === 'wechat_native' ? 'action.query_wechat' : 'action.query_alipay') : tr('action.close_pending'));
        button.type = 'button';
        button.disabled = busy;
        button.setAttribute('aria-label', button.textContent + ' ' + order.id);
        button.addEventListener('click', () => operate(order, operation, button));
        card.append(button);
      }
    }
    if (['alipay_pc', 'wechat_native', 'wallet'].includes(order.payment_method) && root.dataset.canRefund === 'true') {
      const button = text('button', tr('action.refund_history'));
      button.type = 'button';
      button.disabled = busy;
      button.setAttribute('aria-label', tr('action.refund_history') + ' ' + order.id);
      button.addEventListener('click', () => { if (!busy) refunds.open(order); });
      card.append(button);
    }
    if (['alipay_pc', 'wechat_native'].includes(order.payment_method) && order.status === 'payment_review' && root.dataset.canResolve === 'true') {
      const button = text('button', tr('action.resolve_review'));
      button.type = 'button';
      button.disabled = busy;
      button.setAttribute('aria-label', tr('action.resolve_review') + ' ' + order.id);
      button.addEventListener('click', () => { if (!busy) reviews.open(order); });
      card.append(button);
    }
    list.append(card);
  }
  previous.disabled = page === 1;
  next.disabled = page * data.per_page >= data.total;
  document.querySelector('#page-summary').textContent = tr('orders.page_summary', { page, total: data.total });
}
for (const [button, delta] of [[previous, -1], [next, 1]]) {
  button.addEventListener('click', async () => {
    if (busy) return;
    setBusy(true);
    page += delta;
    try { await render(); } catch (error) { page -= delta; message.textContent = error.message; }
    finally { setBusy(false); }
  });
}
filter.addEventListener('change', async () => {
  if (busy) return;
  setBusy(true);
  page = 1;
  try { await render(); } catch (error) { message.textContent = error.message; }
  finally { setBusy(false); }
});
try {
  const cookie = document.cookie.split('; ').find(value => value.startsWith('D2S_ADMIN_SESSION_CSRF='));
  csrf = cookie ? decodeURIComponent(cookie.slice('D2S_ADMIN_SESSION_CSRF='.length)) : '';
  await render();
} catch (error) {
  message.textContent = error.message;
}
