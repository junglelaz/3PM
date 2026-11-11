'use strict';
const {web3}=require('../../license-request-tool/solana/client.cjs');
const TOKEN=new web3.PublicKey('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA');
const meta=(pubkey,isSigner=false,isWritable=true)=>({pubkey,isSigner,isWritable});
function initialize(program,client,artist,admin,hash,amount,dest,token=null){
 if(!Buffer.isBuffer(hash)||hash.length!==32||typeof amount!=='bigint'||amount<=0n)throw Error('Invalid escrow proposal');
 const [escrow]=web3.PublicKey.findProgramAddressSync([Buffer.from('3pm-service-v1'),client.toBuffer(),hash],program);
 const data=Buffer.alloc(171);data[0]=0;data[1]=token?1:0;data[2]=token?6:9;hash.copy(data,3);data.writeBigUInt64LE(amount,35);
 data.set(dest.spend.toBytes(),43);data.set(dest.hodl.toBytes(),75);data.set(dest.fee.toBytes(),107);data.set((token?.mint||web3.SystemProgram.programId).toBytes(),139);
 const keys=[meta(client,true),meta(artist,true,false),meta(admin,true,false),meta(escrow),meta(web3.SystemProgram.programId,false,false)];
 if(token)keys.push(meta(token.mint,false,false),meta(token.source),meta(token.vault),meta(TOKEN,false,false));
 return {escrow,ix:new web3.TransactionInstruction({programId:program,keys,data})};
}
function change(program,signer,escrow,op){if(![1,2,3].includes(op))throw Error('Invalid state action');return new web3.TransactionInstruction({programId:program,keys:[meta(signer,true,false),meta(escrow)],data:Buffer.from([op])});}
function settle(program,signer,escrow,client,dest,token=null,resolution=null,cancel=false){
 let data=Buffer.from([cancel?6:4]);if(resolution){data=Buffer.alloc(42);data[0]=5;data.writeBigUInt64LE(resolution.refund,1);data[9]=resolution.reason;resolution.hash.copy(data,10);}
 const keys=[meta(signer,true,false),meta(escrow),meta(client),meta(dest.spend),meta(dest.hodl),meta(dest.fee)];if(token)keys.push(meta(token.mint,false,false),meta(token.vault),meta(TOKEN,false,false));
 return new web3.TransactionInstruction({programId:program,keys,data});
}
function settleCosts(program,admin,escrow,clientDestination,dest,token,resolution,clientSigner=null){
 const ix=settle(program,admin,escrow,clientDestination,dest,token,resolution);
 const data=Buffer.alloc(58);ix.data.copy(data);data[0]=7;
 data.writeBigUInt64LE(resolution.receivingCost,42);data.writeBigUInt64LE(resolution.refundCost,50);ix.data=data;
 if(resolution.reason===0){if(!clientSigner)throw Error('Client approval required');ix.keys.push(meta(clientSigner,true,false));}
 return ix;
}
module.exports={web3,TOKEN,meta,initialize,change,settle,settleCosts};
