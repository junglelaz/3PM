(() => {
  if (window.threepmDevnetLoaded) return;
  window.threepmDevnetLoaded=true;
  const states=new WeakMap();
  const rpc='https://api.devnet.solana.com';
  const genesis='EtWTRABZaYq6iMfeYKouRu166VU2xqa1wcaWoxPkrZBG';
  const state=root=>{if(!states.has(root))states.set(root,{provider:null,wallet:null,verified:false,busy:false,proposal:null});return states.get(root);};
  const status=(root,text)=>{root.querySelector('[data-sol-status]').textContent=text;};
  function buttons(root){const s=state(root),c=JSON.parse(root.dataset.solanaConfig);for(const name of ['verify','disconnect'])root.querySelector(`[data-sol="${name}"]`).disabled=!s.wallet||s.busy;root.querySelector('[data-sol="propose"]').disabled=!s.verified||!c.program||s.busy;root.querySelector('[data-sol="acknowledge"]').disabled=!s.wallet||!c.program||s.busy;root.querySelector('[data-sol="connect"]').disabled=s.busy;}
  async function api(root,op,extra={}){const c=JSON.parse(root.dataset.solanaConfig);const body=new URLSearchParams({action:'threepm_solana_devnet',nonce:c.nonce,licence:String(c.licence),draft_hash:c.hash,op,...extra});const response=await fetch(c.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const data=await response.json();if(!data.success)throw new Error(data.data?.message||'Request rejected. Reload the page and retry.');return data.data;}
  async function connection(root){const c=JSON.parse(root.dataset.solanaConfig);if(c.rpc!==rpc||c.genesis!==genesis)throw new Error('Devnet configuration mismatch.');const conn=new solanaWeb3.Connection(rpc,'finalized');if(await conn.getGenesisHash()!==genesis)throw new Error('Network is not devnet. Transaction blocked.');return conn;}
  const hashBytes=hex=>{if(!/^[a-f0-9]{64}$/.test(hex))throw new Error('Invalid version fingerprint.');return Uint8Array.from(hex.match(/../g),x=>parseInt(x,16));};
  function receiptPda(p){return solanaWeb3.PublicKey.findProgramAddressSync([new TextEncoder().encode('3pm-devnet-v1'),new solanaWeb3.PublicKey(p.creator).toBytes(),hashBytes(p.hash)],new solanaWeb3.PublicKey(p.program))[0];}
  async function submit(root,kind){
    const s=state(root),c=JSON.parse(root.dataset.solanaConfig);
    if(!root.querySelector('[data-sol-consent]').checked)throw new Error('Confirm this is a devnet-only test first.');
    if(!s.provider?.publicKey||s.provider.publicKey.toString()!==s.wallet)throw new Error('Wallet changed. Reconnect and verify again.');
    const conn=await connection(root);
    if(kind==='propose'){const client=root.querySelector('[data-sol-client]').value.trim();const key=new solanaWeb3.PublicKey(client);if(!solanaWeb3.PublicKey.isOnCurve(key.toBytes())||client===solanaWeb3.SystemProgram.programId.toBase58())throw new Error('Use a valid counterparty test wallet.');s.proposal=await api(root,'prepare',{client});}
    if(kind==='acknowledge'&&!s.proposal)s.proposal=await api(root,'proposal');
    const p=s.proposal;if(!p||p.program!==c.program)throw new Error('Create a proposal in this browser session first.');
    if(s.wallet!==(kind==='propose'?p.creator:p.client))throw new Error('Connect the designated '+(kind==='propose'?'creator':'counterparty')+' test wallet.');
    const program=new solanaWeb3.PublicKey(c.program),receipt=receiptPda(p);
    const programInfo=await conn.getAccountInfo(program,'finalized');if(!programInfo?.executable)throw new Error('The configured devnet program is not executable.');
    const hash=hashBytes(p.hash);let data,keys;
    if(kind==='propose'){data=new Uint8Array(65);data[0]=0;data.set(hash,1);data.set(new solanaWeb3.PublicKey(p.client).toBytes(),33);keys=[{pubkey:new solanaWeb3.PublicKey(p.creator),isSigner:true,isWritable:true},{pubkey:receipt,isSigner:false,isWritable:true},{pubkey:solanaWeb3.SystemProgram.programId,isSigner:false,isWritable:false}];}
    else {data=new Uint8Array(33);data[0]=1;data.set(hash,1);keys=[{pubkey:new solanaWeb3.PublicKey(p.client),isSigner:true,isWritable:false},{pubkey:receipt,isSigner:false,isWritable:true}];}
    const recent=await conn.getLatestBlockhash('finalized');
    const tx=new solanaWeb3.Transaction({feePayer:new solanaWeb3.PublicKey(s.wallet),...recent}).add(solanaWeb3.ComputeBudgetProgram.setComputeUnitLimit({units:200000}),solanaWeb3.ComputeBudgetProgram.setComputeUnitPrice({microLamports:0}),new solanaWeb3.TransactionInstruction({programId:program,keys,data}));
    const fee=await conn.getFeeForMessage(tx.compileMessage(),'finalized');if(fee.value===null)throw new Error('Fee quote unavailable.');
    const rent=kind==='propose'?await conn.getMinimumBalanceForRentExemption(105):0;
    root.querySelector('[data-sol-cost]').textContent=`Devnet test only: estimated network fee ${fee.value} lamports; account creation reserve up to ${rent} lamports. Paid by the signing test wallet. No licence payment.`;
    if(!window.confirm(`DEVNET ONLY\n${kind==='propose'?'Create':'Acknowledge'} a test receipt.\nEstimated fee: ${fee.value} lamports.\nAccount reserve up to: ${rent} lamports.\nNo legal acceptance, live payment or coverage. Continue to your wallet?`))throw new Error('Test transaction cancelled.');
    status(root,'Review the devnet test transaction in Phantom. No licence payment or legal acceptance is requested.');
    const expected=tx.serializeMessage();const signed=await s.provider.signTransaction(tx);
    if(signed.serializeMessage().some((b,i)=>b!==expected[i])||signed.serializeMessage().length!==expected.length)throw new Error('Wallet changed transaction contents. Not submitted.');
    if(await conn.getGenesisHash()!==genesis)throw new Error('Network mismatch before submission.');
    status(root,'Submitting devnet test transaction. Do not retry while its outcome is uncertain.');
    let sig;
    try{sig=await conn.sendRawTransaction(signed.serialize(),{skipPreflight:false,maxRetries:2});}catch(error){status(root,'Submission failed or uncertain. Check the devnet receipt before retrying.');throw error;}
    const output=root.querySelector('[data-sol-receipt]');output.replaceChildren();const link=document.createElement('a');link.href=`https://explorer.solana.com/tx/${encodeURIComponent(sig)}?cluster=devnet`;link.target='_blank';link.rel='noopener noreferrer';link.textContent='View devnet transaction';output.append(link);
    const confirmed=await conn.confirmTransaction({...recent,signature:sig},'finalized');if(confirmed.value.err)throw new Error('Devnet transaction failed. No coverage activated.');
    const result=await api(root,'receipt',{receipt:receipt.toBase58()});status(root,`Devnet receipt finalized. Creator acknowledged: ${result.creator_acknowledged}; counterparty acknowledged: ${result.client_acknowledged}. Payments disabled; no licensed coverage.`);
  }
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-sol]');if(!button)return;const root=button.closest('[data-solana-config]'),s=state(root);if(s.busy)return;
    s.busy=true;buttons(root);
    try{
      const op=button.dataset.sol;
      if(op==='connect'){
        const provider=window.phantom?.solana;if(!provider?.isPhantom)throw new Error('Phantom is not available in this browser. Open this page in a browser with Phantom; no secret phrase is needed.');
        status(root,'Open Phantom, unlock your test wallet and approve the connection. No payment or licence acceptance is requested.');
        const res=await provider.connect();s.provider=provider;s.wallet=res.publicKey.toString();s.verified=false;
        if(!s.events){provider.on('accountChanged',()=>{s.wallet=provider.publicKey?.toString()||null;s.verified=false;buttons(root);status(root,'Wallet changed. Verify ownership again. No licence was signed.');});provider.on('disconnect',()=>{s.wallet=null;s.verified=false;buttons(root);status(root,'Disconnected. Payments remain disabled.');});s.events=true;}
        status(root,`Connected ${s.wallet}. Not yet verified. Connection does not sign a licence or authorize payment.`);
      } else if(op==='verify'){
        const challenge=await api(root,'challenge',{wallet:s.wallet});
        status(root,'Review the wallet-ownership-only message in Phantom. This does not authorize a payment or sign a licence.');
        const result=await s.provider.signMessage(new TextEncoder().encode(challenge.message),'utf8');
        if(s.provider.publicKey?.toString()!==s.wallet)throw new Error('Wallet changed during verification.');
        const verified=await api(root,'verify',{challenge:challenge.challenge,signature:btoa(String.fromCharCode(...result.signature))});s.verified=verified.verified;
        status(root,`Wallet control verified: ${verified.wallet}. No licence signed, no payment authorized.`);
      } else if(op==='disconnect'){await s.provider?.disconnect();s.wallet=null;s.verified=false;status(root,'Disconnected. Payments remain disabled.');}
      else if(op==='status'){const result=await api(root,'status');status(root,`Solana devnet verified at slot ${result.slot}. Test program ${result.executable?'deployed and executable':'not deployed'}. Live payments disabled.`);}
      else if(op==='selftest'){const result=await api(root,'selftest');status(root,`${Object.keys(result.checks).length} signature safeguards passed. Synthetic keys only; no funds moved.`);}
      else if(op==='propose'||op==='acknowledge')await submit(root,op);
      else if(op==='receipt'){if(!s.proposal)s.proposal=await api(root,'proposal');const result=await api(root,'receipt',{receipt:receiptPda(s.proposal).toBase58()});status(root,`Finalized devnet receipt. Counterparty acknowledged: ${result.client_acknowledged}. Payments disabled; coverage inactive.`);}
    }catch(error){status(root,error?.code===4001?'Wallet request declined. No acceptance or payment recorded.':String(error.message||error));}
    finally{s.busy=false;buttons(root);}
  });
})();
