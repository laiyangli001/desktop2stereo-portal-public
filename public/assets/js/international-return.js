'use strict';
(() => {
  const root=document.querySelector('#international-return');
  if(!root)return;
  const output=document.querySelector('#international-result');
  const tr = key => window.D2SI18n.tr('common.' + key);
  let attempts=0;
  async function check(){
    attempts++;
    const prefix='D2S_USER_SESSION_CSRF=';
    const cookie=document.cookie.split('; ').find(item=>item.startsWith(prefix));
    try{
      const response=await fetch('/api/v2/orders/'+root.dataset.order+'/confirm',{
        method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':cookie?decodeURIComponent(cookie.slice(prefix.length)):''},body:'{}',
      });
      const result=await response.json();
      if(response.status===401||result.error==='csrf_invalid'){output.textContent=tr('payments.return.session_expired');return;}
      if(!response.ok||!result.success)throw new Error(result.error||'unavailable');
      const status=result.data.status;
      if(status==='paid'){output.textContent=tr('payments.return.paid_confirmed');return;}
      if(status==='payment_review'){output.textContent=tr('payments.return.payment_review');return;}
      if(['refunded','partially_refunded'].includes(status)){output.textContent=tr('payments.return.has_refund');return;}
      if(status==='cancelled'){output.textContent=tr('payments.return.cancelled');return;}
      if(attempts>=15){output.textContent=tr('payments.return.not_confirmed');return;}
      setTimeout(check,2000);
    }catch(error){
      output.textContent=tr('payments.return.check_unavailable');
    }
  }
  void check();
})();
