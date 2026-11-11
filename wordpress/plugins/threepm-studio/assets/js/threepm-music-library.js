(()=>{'use strict';if(window.threepmMusicLibrary)return;window.threepmMusicLibrary=true;let dragged;
let placeholder, dragParent;
function finishDrag(commit){
 if(!dragged)return;
 if(commit&&placeholder?.parentElement){placeholder.replaceWith(dragged);dragged.closest('[data-music-library]').querySelector('[data-music-status]').textContent='Unsaved changes.';document.dispatchEvent(new Event('threepm:dirty'));}
 else placeholder?.remove();
 dragged.classList.remove('threepm-drag-source');dragged=null;placeholder=null;dragParent=null;
}
document.addEventListener('dragstart',e=>{
 const row=e.target.closest('[data-track-id]');if(!row)return;
 dragged=row;dragParent=row.parentElement;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',row.dataset.trackId);
 placeholder=document.createElement('div');placeholder.className='threepm-drop-placeholder';placeholder.setAttribute('aria-hidden','true');placeholder.style.height=row.getBoundingClientRect().height+'px';row.before(placeholder);
 requestAnimationFrame(()=>{if(dragged===row)row.classList.add('threepm-drag-source');});
});
document.addEventListener('dragover',e=>{
 if(!dragged||e.target.closest('[data-music-rows]')!==dragParent)return;
 e.preventDefault();e.dataTransfer.dropEffect='move';
 const rows=Array.from(dragParent.querySelectorAll('[data-track-id]')).filter(row=>row!==dragged);
 const next=rows.find(row=>e.clientY<row.getBoundingClientRect().top+row.getBoundingClientRect().height/2);
 dragParent.insertBefore(placeholder,next||null);
});
document.addEventListener('drop',e=>{if(dragged&&e.target.closest('[data-music-rows]')===dragParent){e.preventDefault();finishDrag(true);}});
document.addEventListener('dragend',()=>finishDrag(false));
document.addEventListener('click',async e=>{
 const form=e.target.closest('[data-music-editor]');
 if(form&&e.target.closest('[data-add-track]')){
  const container=form.querySelector('[data-track-fields]'),rows=container.querySelectorAll('[data-track-fields-row]');if(rows.length>=50)return;
  const row=rows[0].cloneNode(true),index=1+Math.max(...Array.from(rows,r=>Number(r.querySelector('input').name.match(/tracks\[(\d+)\]/)[1])));
  row.querySelectorAll('[data-audio-status]').forEach(el=>{el.textContent='Upload the audio you want listeners to hear.';});row.querySelector('legend').textContent='Track '+(index+1);row.querySelectorAll('input').forEach(input=>{input.value='';input.name=input.name.replace(/tracks\[\d+\]/,'tracks['+index+']');if(input.id)input.id=input.id+'-'+index;});
  row.querySelectorAll('label').forEach(label=>{const input=label.querySelector('input');if(input)label.htmlFor=input.id;});container.append(row);row.querySelector('input:not([type=hidden])').focus();
 }
 if(form&&e.target.closest('[data-remove-track]')){const rows=form.querySelectorAll('[data-track-fields-row]');if(rows.length>1&&confirm('Remove this track from the release when you save?'))e.target.closest('[data-track-fields-row]').remove();}
 const root=e.target.closest('[data-music-library]');if(!root)return;
 const status=root.querySelector('[data-music-status]'),star=e.target.closest('[data-track-feature]'),move=e.target.closest('[data-track-move]'),save=e.target.closest('[data-music-save]');
 if(star){const on=star.getAttribute('aria-pressed')!=='true';if(on&&root.querySelectorAll('[data-track-feature][aria-pressed=true]').length>=3){status.textContent='You can feature up to three tracks. Unstar one first.';return;}star.setAttribute('aria-pressed',String(on));star.textContent=on?'★':'☆';status.textContent='Unsaved changes.';}
 if(move){const row=move.closest('[data-track-id]'),other=move.dataset.trackMove==='-1'?row.previousElementSibling:row.nextElementSibling;if(other)row.parentElement.insertBefore(row,move.dataset.trackMove==='-1'?other:other.nextSibling);}
 if(!save)return;const data=new FormData();data.set('action','threepm_music_layout');data.set('nonce',root.dataset.nonce);
 root.querySelectorAll('[data-track-id]').forEach(row=>{data.append('order[]',row.dataset.trackId);if(row.querySelector('[data-track-feature]').getAttribute('aria-pressed')==='true')data.append('featured[]',row.dataset.trackId);});
 root.querySelectorAll('[data-music-save]').forEach(b=>b.disabled=true);status.textContent='Saving…';try{const r=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',body:data});const j=await r.json();if(!j.success)throw Error(j.data?.message||'Save failed.');status.textContent=j.data.message;}catch(err){status.textContent=err.message;}finally{root.querySelectorAll('[data-music-save]').forEach(b=>b.disabled=false);}
});})();
