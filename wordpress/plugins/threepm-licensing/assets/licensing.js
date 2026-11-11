(() => {
  if (window.threepmLicensingLoaded) return;
  window.threepmLicensingLoaded = true;
  // Vice's AJAX navigation can reuse content when only the query changes.
  // Private request links must load their exact destination, including its ID/view.
  document.addEventListener('click', event => {
    const link = event.target.closest('.tpm-license a');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#')) return;
    const target = new URL(href, window.location.href);
    if (target.origin !== window.location.origin) return;
    event.preventDefault(); event.stopImmediatePropagation();
    window.location.assign(target.href);
  }, true);
  const updateDueLabels = root => {
    const recurring = root.closest('form').querySelector('[name="terms[payment_mode]"]').value === 'recurring';
    root.querySelectorAll('[data-due-label]').forEach((label, i) => { label.textContent = recurring && i === 0 ? 'First payment due' : 'Payment due'; });
  };
  document.addEventListener('change', event => {
    if (event.target.name === 'terms[payment_mode]') updateDueLabels(event.target.closest('form').querySelector('[data-schedule]'));
  });
  const reindex = root => root.querySelectorAll('.tpm-payment').forEach((row, i) => row.querySelectorAll('input').forEach(input => { input.name = input.name.replace(/schedule\[\d+\]/, `schedule[${i}]`); }));
  document.addEventListener('click', event => {
    const add = event.target.closest('[data-add-payment]');
    if (add) {
      const root = add.closest('fieldset').querySelector('[data-schedule]');
      if (root.children.length >= 120) return;
      const row = root.firstElementChild.cloneNode(true);
      row.querySelectorAll('input').forEach(input => {input.value='';});
      root.append(row); reindex(root); updateDueLabels(root); row.querySelector('input').focus();
    }
    const remove = event.target.closest('[data-remove-payment]');
    if (remove) { const root=remove.closest('[data-schedule]'); if(root.children.length>1){remove.closest('.tpm-payment').remove();reindex(root);updateDueLabels(root);} }
    if(event.target.closest('[data-print-licence]')) window.print();
  });
  document.addEventListener('input', event => {
    if(event.target.matches('[data-country-search]')) {
      const term=event.target.value.toLocaleLowerCase();
      event.target.closest('fieldset').querySelectorAll('[data-countries] option').forEach(option=>{option.hidden=!option.selected&&!option.textContent.toLocaleLowerCase().includes(term);});
    }
    if(event.target.name?.endsWith('[amount]')) {
      const fieldset=event.target.closest('fieldset');
      let cents=0;
      fieldset.querySelectorAll('[name$="[amount]"]').forEach(input=>{if(/^(0|[1-9]\d{0,7})(\.\d{1,2})?$/.test(input.value)){const [whole,frac='']=input.value.split('.');cents+=Number(whole)*100+Number(frac.padEnd(2,'0'));}});
      fieldset.querySelector('[data-allocation]').textContent=`Draft total: $${(cents/100).toFixed(2)} USD · ${(cents*.15).toFixed(2)} $3PM Coins in the Spend Wallet · ${(cents*.01).toFixed(2)} $3PM Coins in the HODL Wallet · $${(cents*.002).toFixed(2)} USD to 3PM (20%). Transaction charges are additional and unquoted.`;
    }
  });
})();
