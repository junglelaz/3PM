(()=>{
  function update(form){
    const select=form.querySelector('select[name="terms[category]"]');if(!select)return;
    for(const group of form.querySelectorAll('[data-service-category]')){
      const active=group.dataset.serviceCategory===select.value;group.hidden=!active;
      for(const field of group.querySelectorAll('input,textarea,select'))field.disabled=!active;
    }
  }
  function scan(root){if(root.nodeType!==1&&root.nodeType!==9)return;for(const form of root.querySelectorAll('.tpm-market form'))update(form);if(root.matches?.('.tpm-market form'))update(root);}
  document.addEventListener('change',e=>{if(e.target.matches('select[name="terms[category]"]'))update(e.target.form);});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>scan(document));else scan(document);
  new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)scan(node);}).observe(document.documentElement,{subtree:true,childList:true});
})();

// Older account styling forces white inputs inline; keep artist fields on brand.
(()=>{
 const selector='.tpm-market #tpm-artist_name,.tpm-market #tpm-bio';
 const seen=new WeakSet();
 function style(field){
   for(const [property,value] of [['background-color','#0d160f'],['color','#f5f6ef'],['-webkit-text-fill-color','#f5f6ef'],['caret-color','#f5f6ef']]){
     const expected=property==='background-color'?'rgb(13, 22, 15)':'rgb(245, 246, 239)';
     if(field.style.getPropertyValue(property)!==expected||field.style.getPropertyPriority(property)!=='important')field.style.setProperty(property,value,'important');
   }
 }
 function scan(){document.querySelectorAll(selector).forEach(field=>{if(seen.has(field))return;seen.add(field);style(field);new MutationObserver(()=>style(field)).observe(field,{attributes:true,attributeFilter:['style']});});}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scan);else scan();
 new MutationObserver(scan).observe(document.documentElement,{childList:true,subtree:true});
})();
