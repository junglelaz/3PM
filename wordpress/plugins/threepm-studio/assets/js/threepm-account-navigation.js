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
 for(const [source,target] of Object.entries(window.threepmAccountEmbedded||{})){const match=new URL(source);if(url.origin===match.origin&&url.pathname.replace(/\/$/,'')===match.pathname.replace(/\/$/,'')){const dest=new URL(target);dest.search=url.search;return dest.href;}}
 if(/\/my-wallet\/statement\/?$/.test(url.pathname)&&!url.searchParams.has('_wpnonce'))url.pathname=url.pathname.replace(/my-wallet\/statement\/?$/,'wallet-transactions/');
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
 if(!a.closest(navSelector)&&!a.closest(panelSelector))return false;
 const base=document.querySelector(navSelector+' a');
 if(!base)return false;
 // WooCommerce may live beneath a subdirectory; use the server-provided account URL.
 const root=window.threepmAccountConfig?.root;
 if(!root||!url.pathname.startsWith(new URL(root).pathname))return false;
 return !/\/(customer-logout|add-payment-method|payment-methods|delete-payment-method|set-default-payment-method)(\/|$)/.test(url.pathname);
}
async function prepareWallet(doc,next){
 if(!next.querySelector('.woo-wallet-select2'))return;
 const config=doc.querySelector('#wc-endpoint-wallet-js-extra')?.textContent||'';
 const match=config.match(/var wallet_param\s*=\s*(\{[\s\S]*?\});/);if(match)window.wallet_param=JSON.parse(match[1]);
 if(window.jQuery?.fn.selectWoo)return;
 const src=[...doc.querySelectorAll('script[src]')].map(s=>s.src).find(s=>s.includes('/woocommerce/assets/js/selectWoo/'));
 if(!src||new URL(src).origin!==location.origin)throw Error('Wallet controls unavailable');
 await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=src;script.onload=resolve;script.onerror=reject;document.head.append(script);});
}
async function prepareSecurity(doc,next){
 if(!next.querySelector('#loginizer_user_choice'))return null;
 const scripts=[...next.querySelectorAll('script')];
 if(scripts.length!==1||scripts[0].src||!scripts[0].textContent.includes('function loginizer_pref_handle('))throw Error('Native security initialization required');
 const code=scripts[0].textContent;scripts[0].remove();
 if(!window.jQuery?.fn.qrcode){
  const src=doc.querySelector('#jquery-qrcode-js')?.src;
  if(!src||new URL(src).origin!==location.origin)throw Error('Security controls unavailable');
  await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=src;script.onload=resolve;script.onerror=reject;document.head.append(script);});
 }
 return code;
}
function initWallet(panel){
 const $=window.jQuery,p=window.wallet_param;if(!$||!p||!$.fn.selectWoo)return;
 $(panel).find('.woo-wallet-select2').each(function(){if($(this).hasClass('select2-hidden-accessible'))return;$(this).selectWoo({width:'100%',minimumInputLength:3,language:{inputTooShort:()=>p.i18n.inputTooShort,searching:()=>p.i18n.searching,noResults:()=>p.i18n.no_result||p.i18n.no_resualt||'No matching user found.'},ajax:{url:p.ajax_url,dataType:'json',type:'POST',delay:250,data:params=>({action:'woo-wallet-user-search',security:p.search_user_nonce,autocomplete_field:'ID',term:params.term}),processResults:data=>({results:$.map(data,item=>({id:item.value,text:item.label}))})}});});
 $(panel).find('#woo_wallet_transfer_form').off('submit.threepm').on('submit.threepm',function(e){if(this.dataset.submitting){e.preventDefault();return;}this.dataset.submitting='1';});
}
async function navigate(url,push){
 const panel=document.querySelector(panelSelector);
 if(!panel){location.assign(url);return;}
 if(controller)controller.abort();
 controller=new AbortController();const activeController=controller;const request=++sequence;
 panel.setAttribute('aria-busy','true');
 document.querySelectorAll('.threepm-account-loading').forEach(el=>el.remove());
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
 document.body.append(loading);
 const timeout=setTimeout(()=>activeController.abort(),15000);
 try{
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}});
  if(!response.ok)throw new Error('Navigation failed');
  const doc=new DOMParser().parseFromString(await response.text(),'text/html');
  const next=doc.querySelector(panelSelector),nextNav=doc.querySelector(navSelector);
  if(!next||!nextNav||new URL(response.url).origin!==location.origin)throw new Error('Full navigation required');
  // Script-dependent screens retain their native initialization through normal navigation.
  const securityCode=/\/security\/?$/.test(new URL(response.url).pathname)?await prepareSecurity(doc,next):null;
  const walletView=!!next.querySelector('.woo-wallet-my-wallet-container');
  if(!walletView&&next.querySelector('script,iframe'))throw new Error('Full navigation required');
  if(walletView)next.querySelectorAll('script').forEach(s=>s.remove());
  if(request!==sequence)return;
  await prepareWallet(doc,next);
  if(request!==sequence)return;
  panel.replaceChildren(...Array.from(next.childNodes));
  initWallet(panel);
  if(securityCode){const init=document.createElement('script');init.textContent=securityCode;panel.append(init);init.remove();}
  const nav=document.querySelector(navSelector);
  if(nav)nav.replaceChildren(...Array.from(nextNav.childNodes));
  document.title=doc.title;const title=document.querySelector('h1.dhrk-pagecaption'),nextTitle=doc.querySelector('h1.dhrk-pagecaption');if(title&&nextTitle)title.replaceChildren(...Array.from(nextTitle.childNodes));
  current=response.url;dirty=false;document.body.dataset.threepmAccountLoads=String(Number(document.body.dataset.threepmAccountLoads||0)+1);
  if(push)history.pushState({threepmAccount:true},'',current);
  panel.setAttribute('tabindex','-1');panel.focus({preventScroll:true});
  panel.dispatchEvent(new CustomEvent('threepm:account-loaded',{bubbles:true}));
 }catch(error){
  if(request===sequence)location.assign(url);
 }finally{
  clearTimeout(timeout);
  loading.remove();
  if(request===sequence)panel.removeAttribute('aria-busy');
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