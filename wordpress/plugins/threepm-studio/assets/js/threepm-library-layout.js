(()=>{'use strict';if(window.threepmLibraryLayout)return;window.threepmLibraryLayout=true;let dragged;
document.addEventListener('dragstart',e=>{const row=e.target.closest('[data-item-id]');if(row&&row.closest('.threepm-library')){dragged=row;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',row.dataset.itemId);}});
document.addEventListener('dragover',e=>{const row=e.target.closest('[data-item-id]');if(dragged&&row&&row.parentElement===dragged.parentElement)e.preventDefault();});
document.addEventListener('drop',e=>{const row=e.target.closest('[data-item-id]');if(!dragged||!row||row.parentElement!==dragged.parentElement)return;e.preventDefault();if(row!==dragged){const after=e.clientY>row.getBoundingClientRect().top+row.getBoundingClientRect().height/2;row.parentElement.insertBefore(dragged,after?row.nextSibling:row);}dragged=null;});
document.addEventListener('dragend',()=>{dragged=null;});
document.addEventListener('click',async e=>{
 const root=e.target.closest('.threepm-library');if(!root)return;
 const star=e.target.closest('[data-feature]'),move=e.target.closest('[data-move]'),save=e.target.closest('[data-save-layout]');
 if(star){const on=star.getAttribute('aria-pressed')!=='true';root.querySelectorAll('[data-feature]').forEach(b=>{b.setAttribute('aria-pressed','false');b.querySelector('svg').setAttribute('fill','none');});star.setAttribute('aria-pressed',String(on));star.querySelector('svg').setAttribute('fill',on?'currentColor':'none');}
 if(move){const row=move.closest('[data-item-id]'),other=move.dataset.move==='-1'?row.previousElementSibling:row.nextElementSibling;if(other)row.parentElement.insertBefore(row,move.dataset.move==='-1'?other:other.nextSibling);}
 if(!save)return;
 const data=new FormData();data.set('action','threepm_library_layout');data.set('nonce',root.dataset.nonce);data.set('type',root.dataset.libraryType);
 root.querySelectorAll('[data-item-id]').forEach(row=>{data.append('order[]',row.dataset.itemId);if(row.querySelector('[data-feature]').getAttribute('aria-pressed')==='true')data.append('featured[]',row.dataset.itemId);});
 const status=root.querySelector('[data-layout-status]');save.disabled=true;status.textContent='Saving…';
 try{const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',body:data});const result=await response.json();if(!result.success)throw Error(result.data?.message||'Save failed.');status.textContent=result.data.message;}catch(error){status.textContent=error.message||'Could not save. Try again.';}finally{save.disabled=false;}
});})();