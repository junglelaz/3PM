'use strict';
const fs=require('node:fs');const vm=require('node:vm');
vm.runInThisContext(fs.readFileSync(require('node:path').join(__dirname,'../threepm-licensing/assets/vendor/solana-web3-1.98.4.min.js'),'utf8'));
const web3=global.solanaWeb3;
const RPC='https://api.devnet.solana.com';
const GENESIS='EtWTRABZaYq6iMfeYKouRu166VU2xqa1wcaWoxPkrZBG';
async function devnet(){const c=new web3.Connection(RPC,'finalized');if(await c.getGenesisHash()!==GENESIS)throw Error('Not devnet. Aborting.');return c;}
function instruction(program,creator,client,hash,ack=false){
 if(!Buffer.isBuffer(hash)||hash.length!==32)throw Error('Expected 32-byte fingerprint.');
 const [receipt]=web3.PublicKey.findProgramAddressSync([Buffer.from('3pm-devnet-v1'),creator.toBuffer(),hash],program);
 const data=ack?Buffer.concat([Buffer.from([1]),hash]):Buffer.concat([Buffer.from([0]),hash,client.toBuffer()]);
 const keys=ack?[{pubkey:client,isSigner:true,isWritable:false},{pubkey:receipt,isSigner:false,isWritable:true}]:[{pubkey:creator,isSigner:true,isWritable:true},{pubkey:receipt,isSigner:false,isWritable:true},{pubkey:web3.SystemProgram.programId,isSigner:false,isWritable:false}];
 return {receipt,ix:new web3.TransactionInstruction({programId:program,keys,data})};
}
module.exports={web3,RPC,GENESIS,devnet,instruction};
