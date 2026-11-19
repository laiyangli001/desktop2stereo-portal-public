export function createRefundReviewPanel(api, changed, task) {
  const tr = (key, params = {}) => window.D2SI18n.tr('payments_admin.' + key, params);
  const locale = () => document.documentElement.lang || 'zh-Hans';
  const panel = document.querySelector('#refund-control-panel');
  if (!panel) return { available: false, reset() {}, open() {} };
  const get = id => panel.querySelector('#refund-control-' + id);
  const labels = { cancel_unsent: tr('refund_review.cancel_unsent'), pause_retry: tr('refund_review.pause_retry'), resume_retry: tr('refund_review.resume_retry') };
  const modes = { cancelled: tr('refund_review.mode.cancelled'), query_only: tr('refund_review.mode.query_only'), automatic: tr('refund_review.mode.automatic') };
  const fields = get('fields'), form = get('form'), confirmation = get('confirm'), action = get('action');
  let id, page = 1, draft = null, wechat = false, currency = 'CNY';
  const money = value => new Intl.NumberFormat(locale(), { style: 'currency', currency }).format(value / 100);
  const endpoint = () => '/api/v2/admin/payments/' + (wechat ? 'wechat-refunds/' : 'refunds/') + id + '/review';
  function resetDraft() {
    draft = null; fields.disabled = false; confirmation.hidden = true; get('check').hidden = false;
  }
  async function load() {
    const data = await api(endpoint() + '?page=' + page);
    const refund = data.refund;
    get('info').textContent = tr('refund_review.info', { channel: tr(wechat ? 'channel.wechat' : 'channel.alipay'), id,
      amount: money(refund.amount), mode: refund.cancelled ? modes.cancelled : modes[refund.retry_mode], attempts: refund.attempts });
    form.hidden = refund.cancelled || refund.confirmed;
    for (const option of action.options) {
      option.disabled = option.value === 'cancel_unsent' ? !['pending', 'unknown'].includes(refund.status) || refund.last_sent_at !== null || (wechat && (refund.status !== 'pending' || refund.attempts !== 0)) :
        option.value === 'pause_retry' ? refund.retry_mode === 'query_only' : refund.retry_mode === 'automatic';
    }
    if (!draft) action.value = [...action.options].find(option => !option.disabled)?.value || '';
    get('history').replaceChildren();
    for (const row of data.actions) {
      const li = document.createElement('li');
      li.textContent = tr('refund_review.history_entry', { date: new Date(Number(row.created_at) * 1000).toLocaleString(locale()),
        action: labels[row.action], mode: modes[row.result_mode], admin: row.operator_id, reason: row.reason });
      get('history').append(li);
    }
    if (!data.actions.length) { const li = document.createElement('li'); li.textContent = tr('refund_review.no_history'); get('history').append(li); }
    get('previous').dataset.unavailable = String(page === 1);
    get('next').dataset.unavailable = String(page * data.per_page >= data.total);
    get('page').textContent = tr('refund_review.page_summary', { page, total: data.total });
  }
  form.addEventListener('submit', event => {
    event.preventDefault(); if (draft || !form.reportValidity() || !action.value) return;
    draft = { action: action.value, reason: get('reason').value.trim(), idempotency_key: crypto.randomUUID(), confirm_review: true };
    get('summary').textContent = tr('refund_review.confirm_summary', { action: labels[draft.action], id, reason: draft.reason });
    fields.disabled = true; confirmation.hidden = false; get('check').hidden = true; get('back').focus();
  });
  get('back').addEventListener('click', () => { resetDraft(); get('reason').focus(); });
  get('submit').addEventListener('click', () => task(async () => {
    if (!draft) return;
    try {
      const result = await api(endpoint(), draft);
      get('message').textContent = tr('refund_review.completed', { mode: modes[result.result_mode] });
      resetDraft(); await load(); await changed();
    } catch (error) { get('message').textContent = error.message; }
  }));
  for (const [name, delta] of [['previous', -1], ['next', 1]]) {
    get(name).addEventListener('click', () => task(async () => {
      page += delta;
      try { await load(); } catch (error) { page -= delta; throw error; }
    }));
  }
  get('hide').addEventListener('click', () => { panel.hidden = true; resetDraft(); });
  return {
    available: true,
    reset() { panel.hidden = true; resetDraft(); },
    async open(refundId, paymentMethod = 'alipay_pc', orderCurrency = 'CNY') {
      wechat = paymentMethod === 'wechat_native'; currency = orderCurrency;
      id = refundId; page = 1; resetDraft(); get('reason').value = ''; get('message').textContent = '';
      form.hidden = true; panel.hidden = false; get('info').textContent = tr('refund_review.number', { id });
      get('history').replaceChildren(); await load(); action.focus();
    },
  };
}
