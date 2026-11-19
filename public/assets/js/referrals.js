'use strict';
(() => {
  const root = document.querySelector('#referrals');
  if (!root) return;
  const admin = root.dataset.admin === 'true';
  const locale = document.documentElement.lang;
  const tr = (key, params = {}) => globalThis.D2SI18n.tr(key, params);
  const notice = document.querySelector('#referral-message');
  const dialog = document.querySelector('#referral-dialog');
  const dialogNotice = document.querySelector('#referral-dialog-message');
  const target = document.querySelector('#referral-detail');
  const base = '/api/v2/' + (admin ? 'admin/' : '') + 'referrals';
  let requestId = crypto.randomUUID().replaceAll('-', '');
  const withdraw=document.querySelector('#referral-withdraw');
  const withdrawSubmit=withdraw?.querySelector('button[type=submit]');
  let balances=null, submitting=false;
  function showWithdrawalAmount(){
    if(!withdraw)return;
    const currency=withdraw.elements.currency.value;
    document.querySelector('#referral-amount').value=balances===null?'':money(Math.max(0,balances.get(currency)||0),currency);
    withdrawSubmit.disabled=balances===null||submitting;
  }
  withdraw?.elements.currency.addEventListener('change',showWithdrawalAmount);
  const names = {pending:tr('referrals.status.pending'), approved:tr('referrals.status.approved'), paid:tr('referrals.status.paid'), rejected:tr('referrals.status.rejected')};
  const errors = {
    permission_denied:tr('referrals.errors.permission_denied'),
    referrals_disabled:tr('referrals.disabled'),
    csrf_invalid:tr('referrals.errors.csrf_invalid'),
    session_invalid:tr('referrals.errors.session_invalid'),
    account_unavailable:tr('referrals.errors.account_unavailable'),
    amount_invalid:tr('referrals.errors.amount_invalid'),
    boolean_required:tr('referrals.errors.boolean_required'),
    referral_configuration_invalid:tr('referrals.errors.referral_configuration_invalid'),
    referral_rate_required:tr('referrals.errors.referral_rate_required'),
    idempotency_conflict:tr('referrals.errors.idempotency_conflict'),
    withdrawal_decision_invalid:tr('referrals.errors.withdrawal_decision_invalid'),
    payout_confirmation_required:tr('referrals.errors.payout_confirmation_required'),
    withdrawal_not_found:tr('referrals.errors.withdrawal_not_found'),
    service_unavailable:tr('referrals.errors.service_unavailable'),
    commission_insufficient:tr('referrals.errors.commission_insufficient'),
    withdrawal_below_minimum:tr('referrals.errors.withdrawal_below_minimum'),
    commission_refund_debt:tr('referrals.errors.commission_refund_debt'),
    withdrawal_state_conflict:tr('referrals.errors.withdrawal_state_conflict'),
  };
  function el(tag,text) { const node=document.createElement(tag); if(text!==undefined)node.textContent=String(text); return node; }
  const money=(amount,currency)=>new Intl.NumberFormat(locale,{style:'currency',currency,currencyDisplay:'code'}).format(Number(amount)/100);
  function minor(value) {
    const match=/^([0-9]+)(?:\.([0-9]{1,2}))?$/.exec(value.trim());
    if(!match)throw new Error(tr('referrals.errors.decimal'));
    const amount=Number(match[1])*100+Number((match[2]||'').padEnd(2,'0'));
    if(!Number.isSafeInteger(amount)||amount>1000000000000)throw new Error(tr('referrals.errors.range'));
    return amount;
  }
  async function api(path='',body) {
    const options={credentials:'same-origin',cache:'no-store',headers:{'X-D2S-Locale':locale}};
    if(body!==undefined){
      const prefix=(admin?'D2S_ADMIN_SESSION_CSRF':'D2S_USER_SESSION_CSRF')+'=';
      const cookie=document.cookie.split('; ').find(item=>item.startsWith(prefix));
      options.method='POST';options.headers={'Content-Type':'application/json','X-D2S-Locale':locale,'X-CSRF-Token':cookie?decodeURIComponent(cookie.slice(prefix.length)):''};
      options.body=JSON.stringify(body);
    }
    const response=await fetch(base+path,options), result=await response.json();
    if(!response.ok||!result.success)throw new Error(errors[result.error]||tr('referrals.errors.request_failed',{code:result.error||response.status}));
    return result.data;
  }
  function card(label,value) {
    const item=el('article');item.className='admin-summary-card';item.append(el('span',label),el('strong',value));return item;
  }
  async function review(row) {
    target.replaceChildren();dialogNotice.textContent='';dialog.showModal();
    try {
      const data=await api('/withdrawals/'+row.id+'/destination');
      target.append(el('p',row.username+' · '+money(row.amount,row.currency)));
      if(data.payout){
        const methods={wechat:tr('referrals.withdraw.wechat'),alipay:tr('referrals.withdraw.alipay'),paypal:tr('referrals.withdraw.paypal')};
        target.append(el('p',tr('referrals.withdraw.method')+': '+methods[data.payout.method]),
          el('p',tr('referrals.withdraw.recipient')+': '+data.payout.recipient),
          el('p',tr('referrals.withdraw.account')+': '+data.payout.account));
      }else target.append(el('p',data.destination));
      const form=el('form');form.className='operation-form';
      const select=el('select');select.name='decision';
      const choices=row.status==='pending'?['approved','rejected']:row.status==='approved'?['paid','rejected']:[];
      choices.forEach(value=>{const option=el('option',names[value]);option.value=value;select.append(option);});
      if(!choices.length)return;
      const reason=el('textarea');reason.name='reason';reason.required=true;reason.maxLength=500;
      const reference=el('input');reference.name='payout_reference';reference.maxLength=160;
      const confirm=el('input');confirm.type='checkbox';confirm.name='payout_confirmed';
      for(const [label,input] of [[tr('referrals.review.decision'),select],[tr('referrals.review.reason'),reason],[tr('referrals.review.reference'),reference]]){
        const wrapper=el('label',label);wrapper.append(input);form.append(wrapper);
      }
      const confirmation=el('label',tr('referrals.review.confirm'));
      confirmation.prepend(confirm);confirmation.className='wide';form.append(confirmation);
      const footer=el('footer'), save=el('button',tr('referrals.review.save'));save.type='submit';footer.append(save);form.append(footer);
      form.addEventListener('submit',async event=>{
        event.preventDefault();if(save.disabled)return;
        if(select.value==='paid'&&(!reference.value.trim()||!confirm.checked)){dialogNotice.textContent=tr('referrals.review.confirm_required');return;}
        save.disabled=true;
        try{await api('/withdrawals/'+row.id,{decision:select.value,reason:reason.value,payout_reference:reference.value,payout_confirmed:confirm.checked});dialog.close();await load();}
        catch(error){dialogNotice.textContent=error.message;}finally{save.disabled=false;}
      });
      target.append(form);
    }catch(error){dialogNotice.textContent=error.message;}
  }
  async function load(){
    try{
      const data=await api(), summary=document.querySelector('#referral-summary');summary.replaceChildren();
      if(!admin)summary.append(card(tr('referrals.summary.buyers'),new Intl.NumberFormat(locale).format(data.effective_referrals)));
      for(const item of data.totals||[]){
        summary.append(card(tr('referrals.summary.earned',{currency:item.currency}),money(Number(item.earned)-Number(item.reversed),item.currency)));
        if(item.pending!==undefined)summary.append(card(tr('referrals.summary.pending',{currency:item.currency}),money(item.pending,item.currency)));
      }
      if(admin){
        const form=document.querySelector('#referral-config');
        form.elements.enabled.checked=data.configuration.enabled;
        form.elements.rate.value=Number(data.configuration.rate_bps)/100;
        form.elements.holding_days.value=data.configuration.holding_days;
        form.elements.minimum.value=Number(data.configuration.minimum_withdrawal)/100;
      }else{
        document.querySelector('#referral-link').value=data.invite_url;
        balances=new Map(data.balances.map(wallet=>[wallet.currency,Number(wallet.available)]));
        showWithdrawalAmount();
        for(const wallet of data.balances){
          summary.append(card(tr('referrals.summary.available',{currency:wallet.currency}),money(Math.max(0,Number(wallet.available)),wallet.currency)));
          summary.append(card(tr('referrals.summary.reserved',{currency:wallet.currency}),money(wallet.reserved,wallet.currency)));
          if(Number(wallet.available)<0)summary.append(card(tr('referrals.summary.debt',{currency:wallet.currency}),money(-Number(wallet.available),wallet.currency)));
        }
        notice.textContent=data.configuration.enabled?'':tr('referrals.disabled');
      }
      const wrap=document.querySelector('#referral-withdrawals');wrap.replaceChildren();
      const table=el('table');table.className='operation-table';const header=el('tr');
      for(const label of [tr('referrals.table.time'),...(admin?[tr('referrals.table.user')]:[]),tr('referrals.amount'),tr('referrals.table.status'),tr('referrals.table.reason'),...(admin?[tr('referrals.table.actions')]:[])])header.append(el('th',label));
      const head=el('thead');head.append(header);table.append(head);const body=el('tbody');
      for(const row of data.withdrawals){
        const line=el('tr');
        for(const value of [new Date(Number(row.created_at)*1000).toLocaleString(locale),...(admin?[row.username]:[]),money(row.amount,row.currency),names[row.status]||row.status,[row.decision_reason,row.payout_reference].filter(Boolean).join(' · ')] )line.append(el('td',value));
        if(admin){const cell=el('td'), button=el('button',tr('referrals.table.review'));button.type='button';button.addEventListener('click',()=>void review(row));cell.append(button);line.append(cell);}
        body.append(line);
      }
      if(!data.withdrawals.length){const line=el('tr'), cell=el('td',tr('referrals.table.empty'));cell.colSpan=admin?6:4;line.append(cell);body.append(line);}
      table.append(body);const scroller=el('div');scroller.className='operation-table-wrap';scroller.append(table);wrap.append(scroller);
    }catch(error){notice.textContent=error.message;}
  }
  document.querySelector('#referral-config')?.addEventListener('submit',async event=>{
    event.preventDefault();const form=event.currentTarget, submit=form.querySelector('button[type=submit]');if(submit.disabled)return;submit.disabled=true;
    try{await api('/configuration',{enabled:form.elements.enabled.checked,rate_bps:minor(form.elements.rate.value),holding_days:Number(form.elements.holding_days.value),minimum_withdrawal:minor(form.elements.minimum.value)});notice.textContent=tr('referrals.config.saved');await load();}
    catch(error){notice.textContent=error.message;}finally{submit.disabled=false;}
  });
  withdraw?.addEventListener('submit',async event=>{
    event.preventDefault();if(withdrawSubmit.disabled)return;
    const form=event.currentTarget;
    submitting=true;showWithdrawalAmount();
    try{
      await api('/withdrawals',{request_id:requestId,currency:form.elements.currency.value,method:form.elements.method.value,
        recipient:form.elements.recipient.value,account:form.elements.account.value});
      requestId=crypto.randomUUID().replaceAll('-','');
      form.elements.recipient.value='';form.elements.account.value='';
      balances=null;
      await load();notice.textContent=tr('referrals.withdraw.requested');
    }catch(error){notice.textContent=error.message;}
    finally{submitting=false;showWithdrawalAmount();}
  });
  document.querySelector('#referral-settle')?.addEventListener('click',async event=>{
    const button=event.currentTarget;button.disabled=true;
    try{const result=await api('/settle',{});notice.textContent=tr('referrals.settled',{count:result.released});await load();}
    catch(error){notice.textContent=error.message;}finally{button.disabled=false;}
  });
  document.querySelector('#referral-copy')?.addEventListener('click',async()=>{
    try{await navigator.clipboard.writeText(document.querySelector('#referral-link').value);notice.textContent=tr('referrals.invite.copied');}
    catch{document.querySelector('#referral-link').select();notice.textContent=tr('referrals.invite.select');}
  });
  document.querySelector('#referral-close').addEventListener('click',()=>dialog.close());
  document.querySelector('#referral-refresh').addEventListener('click',()=>void load());
  void load();
})();
