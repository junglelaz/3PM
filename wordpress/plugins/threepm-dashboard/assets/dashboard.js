(() => {
  if (window.threepmInsightsBound) return;
  window.threepmInsightsBound = true;
  let dragged, placeholder, originalNext, originalParent, latest=0, saveQueue=Promise.resolve(), retry;
  const status=(root,text)=>{root.querySelector('[data-insight-status]').textContent=text;};
  async function request(root,action,data){
    const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action,nonce:root.dataset.nonce,...data})});
    const result=await response.json();if(!response.ok||!result.success)throw Error('Request failed');return result.data;
  }
  function save(root){
    const order=JSON.stringify([...root.querySelectorAll('[data-metric]')].map(t=>t.dataset.metric));
    status(root,'Saving layout…');
    saveQueue=saveQueue.catch(()=>{}).then(()=>request(root,'threepm_insight_layout',{order,mode:root.dataset.mode||'artist'})).then(()=>{status(root,'Layout saved.');root.querySelector('[data-insight-retry]').hidden=true;}).catch(()=>{status(root,'Your layout could not be saved. Retry to keep it.');retry=()=>save(root);root.querySelector('[data-insight-retry]').hidden=false;});
  }
  async function refresh(root){
    const turn=++latest, period=root.querySelector('[data-insight-period]').value;
    root.setAttribute('aria-busy','true');status(root,'Updating statistics…');
    const loader=document.createElement('div');loader.className='insight-loading';loader.setAttribute('role','status');loader.innerHTML='<span aria-hidden="true"></span> Updating statistics';document.body.append(loader);
    try{
      const data=await request(root,'threepm_insights',{period,mode:root.dataset.mode||'artist'});if(turn!==latest)return;
      Object.entries(data.metrics).forEach(([key,m])=>{const tile=root.querySelector(`[data-metric="${key}"]`);if(tile){tile.querySelector('.insight-value').textContent=m.value;tile.querySelector('.insight-note').textContent=m.note;}});
      status(root,`Showing ${root.querySelector('[data-insight-period]').selectedOptions[0].textContent.toLowerCase()}.`);root.querySelector('[data-insight-retry]').hidden=true;
    }catch(e){if(turn===latest){status(root,'Statistics could not update. The previous figures are still shown.');retry=()=>refresh(root);root.querySelector('[data-insight-retry]').hidden=false;}}
    finally{loader.remove();if(turn===latest)root.removeAttribute('aria-busy');}
  }
  document.addEventListener('change',e=>{if(e.target.matches('[data-insight-period]'))refresh(e.target.closest('[data-insights]'));});
  document.addEventListener('click',e=>{
    const retryButton=e.target.closest('[data-insight-retry]');if(retryButton){retry?.();return;}
    const button=e.target.closest('[data-move]');if(!button)return;const tile=button.closest('[data-metric]');if(!tile)return;
    const root=tile.closest('[data-insights]');if(button.dataset.move==='-1'){const prev=tile.previousElementSibling;if(prev)prev.before(tile);}else{const next=tile.nextElementSibling;if(next)next.after(tile);}button.focus();save(root);
  });
  function finish(commit){if(!dragged)return;const root=dragged.closest('[data-insights]')||originalParent.closest('[data-insights]');if(commit)placeholder.replaceWith(dragged);else{placeholder?.remove();originalParent.insertBefore(dragged,originalNext?.parentNode===originalParent?originalNext:null);}dragged.classList.remove('insight-dragged');dragged=null;placeholder=null;if(commit)save(root);}
  document.addEventListener('dragstart',e=>{const tile=e.target.closest('[data-metric]');if(!tile||!tile.closest('[data-insights]'))return;dragged=tile;originalNext=tile.nextElementSibling;originalParent=tile.parentNode;placeholder=document.createElement('div');placeholder.className='insight-placeholder';placeholder.style.minHeight=tile.getBoundingClientRect().height+'px';tile.before(placeholder);e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',tile.dataset.metric);requestAnimationFrame(()=>{if(dragged===tile)tile.classList.add('insight-dragged');});});
  document.addEventListener('dragover',e=>{if(!dragged)return;const grid=e.target.closest('.insight-grid');if(grid!==originalParent)return;e.preventDefault();e.dataTransfer.dropEffect='move';const others=[...grid.querySelectorAll('[data-metric]')].filter(t=>t!==dragged);const next=others.find(t=>{const r=t.getBoundingClientRect();return e.clientY<r.top||(e.clientY<=r.bottom&&e.clientX<r.left+r.width/2);});grid.insertBefore(placeholder,next||null);});
  document.addEventListener('drop',e=>{if(dragged&&e.target.closest('.insight-grid')===originalParent){e.preventDefault();finish(true);}});
  document.addEventListener('dragend',()=>finish(false));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')finish(false);});
})();
