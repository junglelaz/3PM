(()=>{document.addEventListener('click',async event=>{
 const button=event.target.closest('[data-reserve-verify]');if(!button)return;
 const root=button.closest('[data-reserve-setup]'),row=button.closest('[data-role]'),status=root.querySelector('[data-reserve-status]');
 const request=async(action,params)=>{const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action,nonce:root.dataset.nonce,role:row.dataset.role,...params})});const result=await response.json();if(!response.ok||!result.success)throw Error(result.data?.message||'Verification failed.');return result.data;};
 button.disabled=true;try{
  const provider=window.phantom?.solana;if(!provider?.isPhantom)throw Error('Open this setup page in the browser where your Phantom extension is installed.');
  status.textContent='Connect the '+row.dataset.role+' account in Phantom.';const connected=await provider.connect();
  if(connected.publicKey.toString()!==row.dataset.wallet)throw Error('Select the Phantom account with address '+row.dataset.wallet+' and retry.');
  const challenge=await request('threepm_reserve_challenge',{});
  if(challenge.wallet!==row.dataset.wallet)throw Error('The configured address changed. Reload this page.');
  status.textContent='Review the ownership message in Phantom. Signing this message does not move funds.';
  const signed=await provider.signMessage(new TextEncoder().encode(challenge.message),'utf8');
  if(provider.publicKey?.toString()!==challenge.wallet)throw Error('The connected wallet changed. Retry with the configured account.');
  const result=await request('threepm_reserve_verify',{challenge:challenge.id,signature:btoa(String.fromCharCode(...signed.signature))});
  row.querySelector('[data-proof-status]').textContent='Verified '+result.verified_at;status.textContent=result.message;
 }catch(error){status.textContent=error.message||'Wallet verification cancelled.';}finally{button.disabled=false;}
});})();
