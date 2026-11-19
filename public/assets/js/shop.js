const tr = (key, params = {}) => globalThis.D2SI18n.tr(key, params);
const locale = document.documentElement.lang;

const shell = document.querySelector('[data-commerce-page]');
const mode = shell.dataset.commercePage;
const product = new URLSearchParams(window.location.search).get('product') || 'desktop2stereo';
const notice = document.querySelector('#commerce-message');
let busy = false;
let quote = null;
let order = null;
let key = null;
let catalog = null;
let rechargeCatalog = null;
let owned = false;
let selectedMethod = null;
const methodNames = {wallet: tr('commerce.method.wallet'), alipay_pc: tr('commerce.method.alipay'), wechat_native: tr('commerce.method.wechat'), paypal: 'PayPal', stripe: 'Stripe'};
let cashierOrder = null;
let pollTimer = null;
let historyCursors = [null];
let historyIndex = 0;
let nextHistoryCursor = null;
const errors = {
  purchases_closed: tr('commerce.errors.purchases_closed'), price_unavailable: tr('commerce.errors.price_unavailable'),
  quote_expired: tr('commerce.errors.quote_expired'), quote_changed: tr('commerce.errors.quote_changed'),
  quote_already_used: tr('commerce.errors.quote_already_used'), pending_order_exists: tr('commerce.errors.pending_order_exists'),
  wallet_insufficient: tr('commerce.errors.wallet_insufficient'), order_expired: tr('commerce.errors.order_expired'),
  order_not_payable: tr('commerce.errors.order_not_payable'),
  formal_license_already_owned: tr('commerce.errors.formal_license_already_owned'),
  account_purchase_blocked: tr('commerce.errors.account_purchase_blocked'),
  license_disabled: tr('commerce.errors.license_disabled'),
  idempotency_conflict: tr('commerce.errors.idempotency_conflict'),
  csrf_invalid: tr('commerce.errors.csrf_invalid'), permission_denied: tr('commerce.errors.permission_denied'),
  service_unavailable: tr('commerce.errors.service_unavailable'),
  history_cursor_invalid: tr('commerce.errors.history_cursor_invalid'),
  payment_method_unavailable: tr('commerce.errors.payment_method_unavailable'),
  payment_checkout_expiring: tr('commerce.errors.payment_checkout_expiring'),
  alipay_checkout_unavailable: tr('commerce.errors.alipay_checkout_unavailable'),
  external_close_required: tr('commerce.errors.external_close_required'),
  wechat_checkout_busy: tr('commerce.errors.wechat_checkout_busy'),
  wechat_provider_unknown: tr('commerce.errors.wechat_provider_unknown'),
  wechat_checkout_unavailable: tr('commerce.errors.wechat_checkout_unavailable'),
  wechat_code_expired: tr('commerce.errors.wechat_code_expired')
  ,wallet_recharge_closed: tr('commerce.errors.wallet_recharge_closed')
  ,wallet_recharge_amount_invalid: tr('commerce.errors.wallet_recharge_amount_invalid')
  ,wallet_recharge_input_invalid: tr('commerce.errors.wallet_recharge_input_invalid')
  ,wallet_balance_limit: tr('commerce.errors.wallet_balance_limit')
  ,wallet_recharge_refund_insufficient: tr('commerce.errors.wallet_recharge_refund_insufficient')
  ,order_number_clock_rollback: tr('commerce.errors.order_number_clock_rollback')
};
function money(amount, currency) {
  return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(Number(amount) / 100);
}
function csrf() {
  const prefix = 'D2S_USER_SESSION_CSRF=';
  const cookie = document.cookie.split('; ').find(value => value.startsWith(prefix));
  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : '';
}
async function request(path, data) {
  const response = await fetch(path, {
    method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(), 'X-D2S-Locale': document.documentElement.lang },
    ...(data === undefined ? {} : { body: JSON.stringify(data) })
  });
  const result = await response.json();
  if (!response.ok || !result.success) throw new Error(errors[result.error] || tr('commerce.errors.operation_failed'));
  return result.data;
}
async function perform(work) {
  if (busy) return;
  busy = true;
  shell.setAttribute('aria-busy', 'true');
  const buttons = [...shell.querySelectorAll('button')].map(button => [button, button.disabled]);
  for (const [button] of buttons) button.disabled = true;
  try {
    await work();
  } catch (error) {
    notice.textContent = error.message;
  } finally {
    for (const [button, disabled] of buttons) button.disabled = disabled;
    busy = false;
    shell.removeAttribute('aria-busy');
    if (mode === 'shop') updateChoice();
    if (mode === 'wallet') updateRechargeChoice();
    updateHistoryButtons();
  }
}
function history(items) {
  const list = document.querySelector('#commerce-history');
  list.replaceChildren();
  if (!items.length) items = [tr('commerce.history.empty')];
  for (const text of items) {
    const item = document.createElement('li');
    item.textContent = text;
    list.append(item);
  }
}
const states = { pending: tr('commerce.state.pending'), paid: tr('commerce.state.paid'), cancelled: tr('commerce.state.cancelled'), refunded: tr('commerce.state.refunded'), partially_refunded: tr('commerce.state.partially_refunded'), refund_pending: tr('commerce.state.refund_pending'), payment_review: tr('commerce.state.payment_review') };
function updateHistoryButtons() {
  if (mode !== 'wallet') return;
  document.querySelector('#history-previous').disabled = busy || historyIndex === 0;
  document.querySelector('#history-next').disabled = busy || !nextHistoryCursor;
  document.querySelector('#history-refresh').disabled = busy;
}
function showHistory(data) {
  document.querySelector('#history-message').textContent = '';
  const items = mode === 'wallet' ? data.entries : data.orders;
  if (mode === 'wallet') {
    document.querySelector('#wallet-balances').textContent = Object.entries(data.balances).map(([currency, amount]) => money(amount, currency)).join(' / ');
    history(items.map(entry => tr('commerce.history.entry', {amount:money(entry.amount, entry.currency),
      event:entry.event_type === 'PURCHASE' ? tr('commerce.history.purchase') : entry.event_type === 'REFUND' ? tr('commerce.history.refund') : tr('commerce.history.other'),
      balance:money(entry.balance_after, entry.currency), id:entry.id, date:new Date(Number(entry.created_at)*1000).toLocaleString(locale)})));
  } else {
    history(items.map(item => money(item.amount, item.currency) + ' · ' + (states[item.status] || item.status) + ' · ' + (item.order_no || item.id) + ' · ' + new Date(Number(item.created_at) * 1000).toLocaleString(locale)));
  }
  nextHistoryCursor = typeof data.next_cursor === 'string' ? data.next_cursor : null;
  document.querySelector('#history-page').textContent = tr('commerce.history.page', {page:historyIndex+1, count:items.length, last:nextHistoryCursor?'no':'yes'});
  updateHistoryButtons();
}
async function loadHistory(index, cursor) {
  const endpoint = mode === 'wallet' ? '/api/v2/wallet' : '/api/v2/orders';
  let data;
  try { data = await request(endpoint + (cursor ? '?cursor=' + encodeURIComponent(cursor) : '')); }
  catch (error) { document.querySelector('#history-message').textContent = error.message; return; }
  historyIndex = index;
  historyCursors = historyCursors.slice(0, index);
  historyCursors.push(cursor);
  showHistory(data);
}
async function refresh() {
  historyCursors = [null]; historyIndex = 0;
  if (mode === 'wallet') {
    const wallet = await request('/api/v2/wallet');
    document.querySelector('#wallet-balances').textContent = Object.entries(wallet.balances).map(([currency, amount]) => money(amount, currency)).join(' / ');
    showHistory(wallet);
    rechargeCatalog = await request('/api/v2/wallet/recharge');
    document.querySelector('#wallet-recharge').hidden = !rechargeCatalog.enabled;
    const pending = rechargeCatalog.pending_order || null;
    if (pending) {
      order = pending;
      selectedMethod = pending.payment_method;
      checkout();
    } else {
      order = null;
      if (quote && quote.product === 'wallet_recharge') {
        quote = null;
        document.querySelector('#checkout').hidden = true;
      }
    }
    renderRechargeOptions();
    updateRechargeChoice();
    return;
  }
  catalog = await request('/api/v2/shop/catalog?product=' + encodeURIComponent(product));
  owned = catalog.formal_owned;
  if (catalog.presentation) {
    document.querySelector('#product-title').textContent = catalog.presentation.title;
    document.querySelector('#product-description').textContent = catalog.presentation.description;
    for (const selector of ['#product-title', '#product-description']) {
      document.querySelector(selector).lang = catalog.presentation.content_locale;
    }
  } else {
    document.querySelector('#product-title').textContent = catalog.product;
    document.querySelector('#product-description').textContent = '';
  }
  const data = await request('/api/v2/orders?product=' + encodeURIComponent(product));
  const pending = data.pending_order === undefined ? data.orders.find(item => item.status === 'pending') : data.pending_order;
  order = pending || null;
  const select = document.querySelector('#currency');
  const previous = select.value;
  select.replaceChildren();
  for (const price of catalog.prices) {
    const option = document.createElement('option');
    option.value = price.currency;
    option.textContent = price.currency === 'CNY' ? tr('commerce.currency.cny') : tr('commerce.currency.usd');
    select.append(option);
  }
  if ([...select.options].some(option => option.value === previous)) select.value = previous;
  if (order) { select.value = order.currency; selectedMethod = order.payment_method; checkout(); }
  if (owned) notice.textContent = tr('commerce.errors.formal_license_already_owned');
  else if (!catalog.purchases_enabled) notice.textContent = tr('commerce.errors.purchases_closed');
  updateChoice();
}
function updateChoice() {
  const select = document.querySelector('#currency');
  const selected = catalog?.prices.find(price => price.currency === select.value);
  document.querySelector('#product-price').textContent = selected ? money(selected.amount, selected.currency) : tr('commerce.price.unavailable');
  document.querySelector('#prepare-order').disabled = busy || owned || !catalog?.purchases_enabled || !selected || !!order;
  select.disabled = busy || !!order || !catalog?.prices.length;
  paymentOptions();
}
function renderRechargeOptions() {
  const fieldset = document.querySelector('#recharge-options');
  if (!fieldset || !rechargeCatalog) return;
  const methods = order ? [order.payment_method] : rechargeCatalog.payment_methods;
  if (!methods.includes(selectedMethod)) selectedMethod = methods[0] || null;
  if (fieldset.dataset.methods !== methods.join(',')) {
    fieldset.querySelectorAll('label').forEach(label => label.remove());
    fieldset.dataset.methods = methods.join(',');
    for (const method of methods) {
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'radio'; input.name = 'recharge-method'; input.value = method;
      input.addEventListener('change', () => {
        selectedMethod = method;
        updateRechargeChoice();
        if (quote && !order) checkout();
      });
      label.append(input);
      if (method === 'alipay_pc') {
        const logo = document.createElement('img');
        logo.src = '/assets/img/alipay-logo-square.png'; logo.alt = '';
        label.append(logo);
      }
      label.append(document.createTextNode(method === 'alipay_pc' ? tr('commerce.method.alipay') : tr('commerce.method.wechat')));
      fieldset.append(label);
    }
  }
  for (const input of fieldset.querySelectorAll('input')) {
    input.checked = input.value === selectedMethod;
    input.disabled = busy || !!order || !rechargeCatalog.enabled;
  }
}
function updateRechargeChoice() {
  const status = document.querySelector('#recharge-status');
  const button = document.querySelector('#prepare-recharge');
  if (!status || !button) return;
  const amount = Number(document.querySelector('#recharge-amount').value);
  const validAmount = Number.isFinite(amount) && amount >= 1 && amount <= 10000;
  if (!rechargeCatalog?.enabled) status.textContent = tr('commerce.errors.wallet_recharge_closed');
  else if (!rechargeCatalog.payment_methods.length) status.textContent = tr('commerce.recharge.no_methods');
  else status.textContent = tr('commerce.recharge.description');
  button.disabled = busy || !!order || !rechargeCatalog?.enabled || !rechargeCatalog.payment_methods.length || !selectedMethod || !validAmount;
  renderRechargeOptions();
}
function paymentOptions() {
  const fieldset = document.querySelector('#payment-options');
  const currency = document.querySelector('#currency').value;
  const methods = order ? [order.payment_method] : (catalog?.payment_methods || ['wallet']).filter(method => {
    if (['alipay_pc', 'wechat_native'].includes(method)) return currency === 'CNY' && Number(catalog?.prices.find(price => price.currency === currency)?.amount) <= 10000000000;
    if (['paypal', 'stripe'].includes(method)) return catalog.payment_configurations.some(item => item.provider === method && item.currencies.includes(currency));
    return method === 'wallet';
  });
  if (!methods.includes(selectedMethod)) selectedMethod = methods[0] || 'wallet';
  if (fieldset.dataset.methods !== methods.join(',')) {
    fieldset.querySelectorAll('label').forEach(label => label.remove());
    fieldset.dataset.methods = methods.join(',');
    for (const method of methods) {
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'radio'; input.name = 'payment-method'; input.value = method;
      input.addEventListener('change', () => { selectedMethod = method; updateChoice(); if (quote && !order) checkout(); });
      label.append(input);
      if (method === 'alipay_pc') {
        const logo = document.createElement('img');
        logo.src = '/assets/img/alipay-logo-square.png'; logo.alt = '';
        label.append(logo);
      }
      label.append(document.createTextNode(methodNames[method] || method));
      fieldset.append(label);
    }
  }
  for (const input of fieldset.querySelectorAll('input')) {
    input.checked = input.value === selectedMethod;
    input.disabled = busy || !!order;
  }
}
function checkout() {
  const current = order || quote;
  const recharge = current?.product === 'wallet_recharge';
  document.querySelector('#checkout').hidden = false;
  document.querySelector('#checkout-title').textContent = recharge ? tr('commerce.checkout.recharge') : tr('commerce.checkout.purchase');
  document.querySelector('#checkout-summary').textContent = (recharge ? tr('commerce.product.recharge') : tr('commerce.product.full')) + ' · ' + money(current.amount, current.currency) +
    (order ? ' · ' + (order.order_no || order.id) + ' · ' + (states[order.status] || order.status) : tr('commerce.checkout.quote_expiry'));
  document.querySelector('#confirm-order').hidden = !!order;
  document.querySelector('#pay-order').hidden = !order || order.status !== 'pending';
  const method = order?.payment_method || selectedMethod;
  const external = method !== 'wallet';
  const international = ['paypal', 'stripe'].includes(method);
  document.querySelector('#payment-description').textContent = international ? tr('commerce.checkout.external', {provider:methodNames[method]}) :
    external ? tr('commerce.checkout.scan', {provider:method === 'alipay_pc' ? tr('commerce.provider.alipay') : tr('commerce.provider.wechat')}) : tr('commerce.checkout.wallet');
  document.querySelector('#pay-order').textContent = international ? tr('commerce.checkout.visit', {provider:methodNames[method]}) :
    external ? (method === 'alipay_pc' ? tr('commerce.checkout.alipay_qr') : tr('commerce.checkout.wechat_qr')) : tr('commerce.checkout.pay');
  document.querySelector('#cancel-order').hidden = !order || order.status !== 'pending' || external;
}
function stopCashier() {
  window.clearTimeout(pollTimer);
  cashierOrder = null;
  document.querySelector('#alipay-cashier').hidden = true;
  document.querySelector('#cashier-frame').replaceChildren();
  document.querySelector('#wechat-cashier').hidden = true;
  document.querySelector('#wechat-code').replaceChildren();
}
function showCashier(data) {
  const gateways = ['https://openapi.alipay.com/gateway.do', 'https://openapi-sandbox.dl.alipaydev.com/gateway.do'];
  const fields = ['app_id','method','format','charset','sign_type','timestamp','version','biz_content','notify_url','return_url','sign'];
  if (!gateways.includes(data.gateway) || !['0','1','3','4'].includes(data.qr_pay_mode) ||
      !Number.isInteger(data.qrcode_width) || data.qrcode_width < 75 || data.qrcode_width > 600 ||
      Object.keys(data.parameters || {}).some(name => !fields.includes(name)) ||
      fields.some(name => typeof data.parameters?.[name] !== 'string')) throw new Error(tr('commerce.errors.cashier_invalid'));
  stopCashier();
  cashierOrder = { id: order.id, expires_at: Number(order.expires_at) };
  const frame = document.createElement('iframe');
  frame.name = 'alipay-cashier-frame';
  frame.title = tr('commerce.cashier.alipay_qr');
  frame.setAttribute('sandbox', 'allow-forms allow-scripts allow-same-origin allow-popups');
  frame.referrerPolicy = 'no-referrer';
  const dimensions = { '0': [600,300], '1': [300,600], '3': [240,240], '4': [data.qrcode_width + 40, data.qrcode_width + 180] };
  [frame.width, frame.height] = dimensions[data.qr_pay_mode].map(String);
  document.querySelector('#cashier-frame').append(frame);
  document.querySelector('#alipay-cashier').hidden = false;
  const form = document.createElement('form');
  form.method = 'POST'; form.action = data.gateway; form.target = frame.name; form.hidden = true;
  for (const [name, value] of Object.entries(data.parameters)) {
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
  }
  document.body.append(form);
  form.submit();
  form.remove();
  notice.textContent = tr('commerce.cashier.scan');
  schedulePaymentCheck(5000);
}
async function showWechatCashier(data) {
  const {renderWechatCode} = await import(shell.dataset.wechatModule);
  stopCashier();
  renderWechatCode(document.querySelector('#wechat-code'), data, order);
  cashierOrder = { id: order.id, expires_at: data.expires_at };
  document.querySelector('#wechat-cashier').hidden = false;
  document.querySelector('#wechat-expiry').textContent = tr('commerce.cashier.deadline', {date:new Date(data.expires_at*1000).toLocaleString(locale)});
  document.querySelector('#wechat-cashier-title').focus();
  notice.textContent = tr('commerce.cashier.wechat_scan');
  schedulePaymentCheck(5000);
}
function schedulePaymentCheck(delay) {
  window.clearTimeout(pollTimer);
  pollTimer = window.setTimeout(() => {
    if (!cashierOrder) return;
    if (busy) { schedulePaymentCheck(1000); return; }
    perform(checkPayment);
  }, delay);
}
async function checkPayment() {
  if (!cashierOrder) return;
  const expected = cashierOrder.id;
  window.clearTimeout(pollTimer);
  try {
    const current = await request('/api/v2/orders/' + expected);
    if (!cashierOrder || cashierOrder.id !== expected) return;
    if (current.status === 'pending') {
      if (Date.now() >= cashierOrder.expires_at * 1000) {
        stopCashier(); notice.textContent = tr('commerce.cashier.expired');
      } else notice.textContent = tr('commerce.cashier.pending');
      return;
    }
    stopCashier();
    order = current;
    checkout();
    await refresh();
    notice.textContent = current.status === 'paid' ? (current.product === 'wallet_recharge' ? tr('commerce.cashier.recharged') : tr('commerce.cashier.licensed')) :
      current.status === 'payment_review' ? tr('commerce.cashier.review') : tr('commerce.cashier.updated');
  } finally {
    if (cashierOrder && Date.now() < cashierOrder.expires_at * 1000) {
      schedulePaymentCheck(document.hidden ? 15000 : 5000);
    } else if (cashierOrder) {
      stopCashier();
      notice.textContent = tr('commerce.cashier.expired');
    }
  }
}
window.addEventListener('message', event => {
  const frame = document.querySelector('#cashier-frame iframe');
  if (frame && event.source === frame.contentWindow && [window.location.origin, 'https://d2s.site', 'https://d2s.top'].includes(event.origin) &&
      event.data?.type === 'd2s-alipay-return') perform(checkPayment);
});
window.addEventListener('pagehide', () => window.clearTimeout(pollTimer));
if (mode === 'shop' || mode === 'wallet') {
  if (mode === 'wallet') {
    document.querySelector('#history-next').addEventListener('click', () => perform(() => loadHistory(historyIndex + 1, nextHistoryCursor)));
    document.querySelector('#history-previous').addEventListener('click', () => perform(() => loadHistory(historyIndex - 1, historyCursors[historyIndex - 1])));
    document.querySelector('#history-refresh').addEventListener('click', () => perform(() => loadHistory(0, null)));
  }
  if (mode === 'shop') {
    document.querySelector('#currency').addEventListener('change', () => {
      quote = null; key = null; selectedMethod = null;
      document.querySelector('#checkout').hidden = true;
      updateChoice();
    });
    document.querySelector('#prepare-order').addEventListener('click', () => perform(async () => {
      quote = await request('/api/v2/shop/quote', { product, currency: document.querySelector('#currency').value });
      key = crypto.randomUUID();
      order = null;
      notice.textContent = '';
      checkout();
      document.querySelector('#checkout-title').focus();
    }));
    document.querySelector('#confirm-order').addEventListener('click', () => perform(async () => {
      if (!quote || !key) throw new Error(tr('commerce.checkout.select_again'));
      order = await request('/api/v2/orders', { quote_id: quote.id, payment_method: selectedMethod, idempotency_key: key });
      checkout();
      await refresh();
      notice.textContent = tr('commerce.checkout.created');
    }));
    document.querySelector('#pay-order').addEventListener('click', () => perform(async () => {
      if (['paypal', 'stripe'].includes(order.payment_method)) {
        const data = await request('/api/v2/orders/' + order.id + '/checkout', {});
        const target = new URL(data.checkout_url);
        const allowed = order.payment_method === 'stripe' ? ['checkout.stripe.com'] : ['www.paypal.com', 'paypal.com', 'www.sandbox.paypal.com', 'sandbox.paypal.com'];
        if (target.protocol !== 'https:' || target.username || target.password || target.port || !allowed.includes(target.hostname)) throw new Error(tr('commerce.errors.cashier_url_invalid'));
        window.location.assign(target.href);
        return;
      }
      if (['alipay_pc', 'wechat_native'].includes(order.payment_method)) {
        const checkoutData = await request('/api/v2/orders/' + order.id + '/checkout', {});
        if (order.payment_method === 'alipay_pc') showCashier(checkoutData);
        else if (checkoutData.status === 'pending') await showWechatCashier(checkoutData);
        else {
          stopCashier(); await refresh();
          notice.textContent = checkoutData.status === 'paid' ? tr('commerce.cashier.licensed') : tr('commerce.cashier.review_order');
        }
        return;
      }
      const result = await request('/api/v2/orders/' + order.id + '/pay', {});
      order = result;
      checkout();
      await refresh();
      notice.textContent = tr('commerce.cashier.licensed');
    }));
    document.querySelector('#check-payment').addEventListener('click', () => perform(checkPayment));
    document.querySelector('#check-wechat-payment').addEventListener('click', () => perform(checkPayment));
    document.querySelector('#cancel-order').addEventListener('click', () => perform(async () => {
      await request('/api/v2/orders/' + order.id + '/cancel', {});
      order = null; quote = null; key = null;
      document.querySelector('#checkout').hidden = true;
      await refresh();
      notice.textContent = tr('commerce.checkout.cancelled');
    }));
  }
  if (mode === 'wallet') {
    const amount = document.querySelector('#recharge-amount');
    amount.addEventListener('input', updateRechargeChoice);
    document.querySelector('#recharge-form').addEventListener('submit', event => {
      event.preventDefault();
      if (busy || !rechargeCatalog?.enabled || !selectedMethod) return;
      const minor = Math.round(Number(amount.value) * 100);
      if (!Number.isSafeInteger(minor) || minor < 100 || minor > 1000000) {
        notice.textContent = tr('commerce.errors.wallet_recharge_amount_invalid');
        return;
      }
      perform(async () => {
        quote = await request('/api/v2/wallet/recharge/quote', { amount: minor });
        key = crypto.randomUUID();
        order = null;
        checkout();
        document.querySelector('#checkout-title').focus();
      });
    });
    document.querySelector('#confirm-order').addEventListener('click', () => perform(async () => {
      if (!quote || quote.product !== 'wallet_recharge' || !key || !selectedMethod) throw new Error(tr('commerce.recharge.confirm_again'));
      order = await request('/api/v2/wallet/recharges', { quote_id: quote.id, payment_method: selectedMethod, idempotency_key: key });
      checkout();
      await refresh();
      notice.textContent = tr('commerce.recharge.created');
    }));
    document.querySelector('#pay-order').addEventListener('click', () => perform(async () => {
      if (!['alipay_pc', 'wechat_native'].includes(order.payment_method)) throw new Error(tr('commerce.recharge.method_unavailable'));
      const checkoutData = await request('/api/v2/orders/' + order.id + '/checkout', {});
      if (order.payment_method === 'alipay_pc') showCashier(checkoutData);
      else if (checkoutData.status === 'pending') await showWechatCashier(checkoutData);
      else {
        stopCashier();
        await refresh();
        notice.textContent = checkoutData.status === 'paid' ? tr('commerce.cashier.recharged') : tr('commerce.cashier.review_order');
      }
    }));
    document.querySelector('#check-payment').addEventListener('click', () => perform(checkPayment));
    document.querySelector('#check-wechat-payment').addEventListener('click', () => perform(checkPayment));
  }
  perform(refresh);
}
