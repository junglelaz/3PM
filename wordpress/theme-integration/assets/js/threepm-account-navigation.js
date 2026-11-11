(() => {
'use strict';
if(window.threepmAccountNavigation)return;
window.threepmAccountNavigation=true;
const panelSelector='.woocommerce-MyAccount-content';
const navSelector='.woocommerce-MyAccount-navigation';
let controller, sequence=0, dirty=false, current=location.href;
document.addEventListener('input',e=>{if(e.target.closest(panelSelector+' form'))dirty=true;});
document.addEventListener('change',e=>{if(e.target.closest(panelSelector+' form'))dirty=true;});
function accountDestination(href){
 const url=new URL(href,location.href);
 for(const [source,view] of Object.entries(window.threepmAccountConfig?.licensing||{})){
  const match=new URL(source);
  if(url.origin===match.origin&&url.pathname.replace(/\/$/,'')===match.pathname.replace(/\/$/,'')){
   const dest=new URL(window.threepmAccountConfig.licensingEndpoint);
   dest.search=url.search;
   dest.searchParams.set('licensing_view',view);
   return dest.href;
  }
 }
 return url.href;
}
function eligible(a){
 if(!a||a.target&&a.target!=='_self'||a.hasAttribute('download'))return false;
 const url=new URL(accountDestination(a.href));
 if(url.origin!==location.origin||url.hash||url.searchParams.has('_wpnonce'))return false;
 if(!a.closest(navSelector)&&!a.closest(panelSelector+' nav')&&!a.closest(panelSelector+' .tpm-license'))return false;
 const base=document.querySelector(navSelector+' a');
 if(!base)return false;
 // WooCommerce may live beneath a subdirectory; use the server-provided account URL.
 const root=window.threepmAccountConfig?.root;
 if(!root||!url.pathname.startsWith(new URL(root).pathname))return false;
 return !/\/(customer-logout|add-payment-method|payment-methods|delete-payment-method|set-default-payment-method)(\/|$)/.test(url.pathname);
}
async function navigate(url,push){
 const panel=document.querySelector(panelSelector);
 if(!panel){location.assign(url);return;}
 if(controller)controller.abort();
 controller=new AbortController();const activeController=controller;const request=++sequence;
 panel.setAttribute('aria-busy','true');
 panel.querySelectorAll(':scope > .threepm-account-loading').forEach(el=>el.remove());
 const loading=document.createElement('div');
 loading.className='threepm-account-loading';
 loading.setAttribute('role','status');
 loading.setAttribute('aria-live','polite');
 const spinner=document.createElement('span');
 spinner.className='threepm-account-spinner';
 spinner.setAttribute('aria-hidden','true');
 const label=document.createElement('span');
 label.textContent='Loading…';
 loading.append(spinner,label);
 panel.prepend(loading);
 const timeout=setTimeout(()=>activeController.abort(),15000);
 try{
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}});
  if(!response.ok)throw new Error('Navigation failed');
  const doc=new DOMParser().parseFromString(await response.text(),'text/html');
  const next=doc.querySelector(panelSelector),nextNav=doc.querySelector(navSelector);
  if(!next||!nextNav||new URL(response.url).origin!==location.origin)throw new Error('Full navigation required');
  // Script-dependent screens retain their native initialization through normal navigation.
  if(next.querySelector('script,iframe'))throw new Error('Full navigation required');
  if(request!==sequence)return;
  panel.replaceChildren(...Array.from(next.childNodes));
  const nav=document.querySelector(navSelector);
  if(nav)nav.replaceChildren(...Array.from(nextNav.childNodes));
  document.title=doc.title;
  current=response.url;dirty=false;
  if(push)history.pushState({threepmAccount:true},'',current);
  panel.setAttribute('tabindex','-1');panel.focus({preventScroll:true});
  panel.dispatchEvent(new CustomEvent('threepm:account-loaded',{bubbles:true}));
 }catch(error){
  if(request===sequence)location.assign(url);
 }finally{
  clearTimeout(timeout);
  if(request===sequence){panel.removeAttribute('aria-busy');loading.remove();}
 }
}
window.addEventListener('click',e=>{
 if(e.button!==0||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;
 const a=e.target.closest('a[href]');
 if(!eligible(a))return;
 e.preventDefault();e.stopImmediatePropagation();
 if(dirty&&!window.confirm('Leave this section without saving your changes?'))return;
 navigate(accountDestination(a.href),true);
},true);
window.addEventListener('popstate',()=>{
 if(!document.querySelector(panelSelector))return;
 const root=window.threepmAccountConfig?.root;
 if(!root||!location.pathname.startsWith(new URL(root).pathname)){location.reload();return;}
 if(dirty&&!window.confirm('Leave this section without saving your changes?')){history.pushState({threepmAccount:true},'',current);return;}
 navigate(location.href,false);
});
})();