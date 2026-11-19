const form = document.querySelector('#bill-form');
const channel = document.querySelector('#bill-channel');
const date = document.querySelector('#bill-date');
const period = document.querySelector('#bill-period');
const message = document.querySelector('#bill-message');
const button = form.querySelector('button');
const billTr = key => window.D2SI18n.tr(key);
const errors = {
  bill_not_available: 'bills.error.bill_not_available',
  bill_date_invalid: 'bills.error.bill_date_invalid',
  permission_denied: 'bills.error.permission_denied',
  bill_query_failed: 'bills.error.bill_query_failed',
  bill_channel_invalid: 'bills.error.bill_channel_invalid',
  bill_url_invalid: 'bills.error.bill_url_invalid',
  bill_file_invalid: 'bills.error.bill_file_invalid',
  wechat_bill_date_invalid: 'bills.error.wechat_bill_date_invalid',
  wechat_bill_metadata_invalid: 'bills.error.wechat_bill_metadata_invalid',
  bill_download_failed: 'bills.error.bill_download_failed',
  bill_host_unavailable: 'bills.error.bill_host_unavailable',
  service_unavailable: 'bills.error.service_unavailable',
};
function updateDateMode() {
  const monthly = period.value === 'month';
  date.type = monthly ? 'month' : 'date';
  date.min = monthly ? date.dataset.monthMin : date.dataset.dayMin;
  date.max = monthly ? date.dataset.monthMax : date.dataset.dayMax;
  date.value = date.max;
}
function updateChannel() {
  const monthlyOption = [...period.options].find(option => option.value === 'month');
  const wechat = channel.value === 'wechat';
  monthlyOption.disabled = wechat;
  if (wechat) period.value = 'day';
  updateDateMode();
}
channel.addEventListener('change', updateChannel);
period.addEventListener('change', updateDateMode);
form.addEventListener('submit', async event => {
  event.preventDefault();
  if (button.disabled || !form.reportValidity()) return;
  button.disabled = true;
  form.setAttribute('aria-busy', 'true');
  message.textContent = billTr('bills.status.loading');
  try {
    const payload = { bill_date: date.value, bill_channel: channel.value };
    const order = document.querySelector('#bill-order').value.trim();
    if (order) payload.order_id = order;
    const cookie = document.cookie.split('; ').find(value => value.startsWith('D2S_ADMIN_SESSION_CSRF='));
    const response = await fetch('/api/v2/admin/payments/bills/download', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cookie ? decodeURIComponent(cookie.slice('D2S_ADMIN_SESSION_CSRF='.length)) : '' },
      body: JSON.stringify(payload),
    });
    if (!response.ok) {
      const body = await response.json();
      throw new Error(billTr(errors[body.error] || 'bills.error.generic'));
    }
    const expectedType = channel.value === 'wechat' ? 'application/gzip' : 'application/zip';
    if (!response.headers.get('content-type')?.startsWith(expectedType)) throw new Error(billTr('bills.error.content_type'));
    const disposition = response.headers.get('content-disposition') || '';
    const filename = /filename="([^"]+)"/.exec(disposition)?.[1] ||
      (channel.value === 'wechat' ? 'wechat_trade_' : 'alipay_trade_') + payload.bill_date + (channel.value === 'wechat' ? '.csv.gz' : '.zip');
    if (!/^(?:alipay_trade_[0-9]{4}-[0-9]{2}(?:-[0-9]{2})?\.zip|wechat_trade_[0-9]{4}-[0-9]{2}-[0-9]{2}\.csv\.gz)$/.test(filename)) {
      throw new Error(billTr('bills.error.filename'));
    }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
    message.textContent = billTr('bills.status.download_started');
  } catch (error) { message.textContent = error.message; }
  finally { button.disabled = false; form.setAttribute('aria-busy', 'false'); }
});
