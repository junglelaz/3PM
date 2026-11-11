(()=>{'use strict';if(window.threepmMusicLibrary)return;window.threepmMusicLibrary=true;let dragged;
document.addEventListener('dragstart',e=>{const r=e.target.closest('[data-track-id]');if(r){dragged=r;e.dataTransfer.setData('text/plain',r.dataset.trackId);}});
document.addEventListener('dragover',e=>{const r=e.target.closest('[data-track-id]');if(dragged&&r&&r.parentElement===dragged.parentElement)e.preventDefault();});
document.addEventListener('drop',e=>{const r=e.target.closest('[data-track-id]');if(dragged&&r&&r.parentElement===dragged.parentElement){e.preventDefault();if(r!==dragged)r.parentElement.insertBefore(dragged,e.clientY>r.getBoundingClientRect().top+r.getBoundingClientRect().height/2?r.nextSibling:r);dragged=null;}});
document.addEventListener('dragend',()=>dragged=null);
document.addEventListener('click',async e=>{
 const form=e.target.closest('[data-music-editor]');
 if(form&&e.target.closest('[data-add-track]')){
  const container=form.querySelector('[data-track-fields]'),rows=container.querySelectorAll('[data-track-fields-row]');if(rows.length>=50)return;
  const row=rows[0].cloneNode(true),index=1+Math.max(...Array.from(rows,r=>Number(r.querySelector('input').name.match(/tracks\[(\d+)\]/)[1])));
  row.querySelector('legend').textContent='Track '+(index+1);row.querySelectorAll('input').forEach(input=>{input.value='';input.name=input.name.replace(/tracks\[\d+\]/,'tracks['+index+']');if(input.id)input.id=input.id+'-'+index;});
  row.querySelectorAll('label').forEach(label=>{const input=label.querySelector('input');if(input)label.htmlFor=input.id;});container.append(row);row.querySelector('input:not([type=hidden])').focus();
 }
 if(form&&e.target.closest('[data-remove-track]')){const rows=form.querySelectorAll('[data-track-fields-row]');if(rows.length>1&&confirm('Remove this track from the release when you save?'))e.target.closest('[data-track-fields-row]').remove();}
 const root=e.target.closest('[data-music-library]');if(!root)return;
 const status=root.querySelector('[data-music-status]'),star=e.target.closest('[data-track-feature]'),move=e.target.closest('[data-track-move]'),save=e.target.closest('[data-music-save]');
 if(star){const on=star.getAttribute('aria-pressed')!=='true';if(on&&root.querySelectorAll('[data-track-feature][aria-pressed=true]').length>=3){status.textContent='You can feature up to three tracks. Unstar one first.';return;}star.setAttribute('aria-pressed',String(on));star.textContent=on?'★':'☆';status.textContent='Unsaved changes.';}
 if(move){const row=move.closest('[data-track-id]'),other=move.dataset.trackMove==='-1'?row.previousElementSibling:row.nextElementSibling;if(other)row.parentElement.insertBefore(row,move.dataset.trackMove==='-1'?other:other.nextSibling);}
 if(!save)return;const data=new FormData();data.set('action','threepm_music_layout');data.set('nonce',root.dataset.nonce);
 root.querySelectorAll('[data-track-id]').forEach(row=>{data.append('order[]',row.dataset.trackId);if(row.querySelector('[data-track-feature]').getAttribute('aria-pressed')==='true')data.append('featured[]',row.dataset.trackId);});
 save.disabled=true;status.textContent='Saving…';try{const r=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',body:data});const j=await r.json();if(!j.success)throw Error(j.data?.message||'Save failed.');status.textContent=j.data.message;}catch(err){status.textContent=err.message;}finally{save.disabled=false;}
});})();
