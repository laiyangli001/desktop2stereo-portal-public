'use strict';

(() => {
  const form=document.querySelector('#plugin-settings-form');
  const message=document.querySelector('#plugin-message');
  if(!form)return;
  let pending=false;
  const tr=(key,parameters={})=>window.D2SI18n.tr(key,parameters);
  const errorKeys={
    permission_denied:'plugins.message.error.permission_denied',
    administrator_inactive:'plugins.message.error.session',
    csrf_invalid:'plugins.message.error.session',
    origin_denied:'plugins.message.error.session',
    module_setting_invalid:'plugins.message.error.invalid',
    module_boolean_invalid:'plugins.message.error.invalid',
    service_unavailable:'plugins.message.error.service',
  };
  function csrf(){
    const prefix='D2S_ADMIN_SESSION_CSRF=';
    const item=document.cookie.split('; ').find(value=>value.startsWith(prefix));
    return item?decodeURIComponent(item.slice(prefix.length)):'';
  }
  async function request(path,payload){
    const response=await fetch(path,{method:'POST',credentials:'same-origin',headers:{
      'Content-Type':'application/json','X-CSRF-Token':csrf(),'X-D2S-Locale':document.documentElement.lang,
    },body:JSON.stringify(payload)});
    const body=await response.json();
    if(!response.ok||!body.success)throw new Error(tr(errorKeys[body.error]||'plugins.message.error.generic'));
    return body.data;
  }
  function busy(value){
    pending=value;
    form.setAttribute('aria-busy',String(value));
    form.querySelectorAll('button,input').forEach(control=>{control.disabled=value;});
  }
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    if(pending||!form.reportValidity())return;
    const values={};
    for(const control of form.querySelectorAll('input[type="checkbox"][name]'))values[control.name]=control.checked;
    busy(true);
    try{
      const data=await request('/api/v2/admin/settings/plugins',{values});
      for(const [name,value]of Object.entries(data.values||{}))if(form.elements.namedItem(name))form.elements.namedItem(name).checked=Boolean(value);
      message.textContent=tr('plugins.message.saved');
    }catch(error){message.textContent=error.message;}
    finally{busy(false);}
  });
})();
