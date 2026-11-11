//! Experimental service escrow. Test-only until independent review and application integration.
//! Init requires client, artist and configured dispute authority consent to exact immutable terms.
//! SOL and classic SPL tokens only. No automatic payout, admin sweep, mint, or destination edits.
use solana_program::{account_info::AccountInfo,entrypoint,entrypoint::ProgramResult,
    program::{invoke,invoke_signed},program_error::ProgramError,pubkey::Pubkey,
    instruction::{Instruction,AccountMeta},rent::Rent,clock::Clock,sysvar::Sysvar,system_instruction,system_program};
#[cfg(not(feature="no-entrypoint"))]
entrypoint!(process_instruction);
const MAGIC:&[u8;8]=b"3PMSVC01";
const SEED:&[u8]=b"3pm-service-v1";
pub const SIZE:usize=348;
const TOKEN:Pubkey=solana_program::pubkey!("TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA");
fn err()->ProgramError{ProgramError::InvalidArgument}
fn key(data:&[u8],offset:usize)->Pubkey{Pubkey::new_from_array(data[offset..offset+32].try_into().unwrap())}
fn number(data:&[u8],offset:usize)->u64{u64::from_le_bytes(data[offset..offset+8].try_into().unwrap())}
fn signer(a:&AccountInfo)->ProgramResult{if !a.is_signer{Err(ProgramError::MissingRequiredSignature)}else{Ok(())}}
fn writable(a:&AccountInfo)->ProgramResult{if !a.is_writable{Err(ProgramError::InvalidAccountData)}else{Ok(())}}
fn verify(program:&Pubkey,escrow:&AccountInfo)->ProgramResult{
    if escrow.owner!=program||escrow.data_len()!=SIZE{return Err(ProgramError::InvalidAccountData)}
    writable(escrow)?;let d=escrow.try_borrow_data()?;
    if &d[..8]!=MAGIC{return Err(err())}
    let (expected,bump)=Pubkey::find_program_address(&[SEED,&d[44..76],&d[12..44]],program);
    if expected!=*escrow.key||bump!=d[9]{return Err(ProgramError::InvalidSeeds)}Ok(())
}
fn token_account(a:&AccountInfo,mint:&Pubkey,owner:Option<&Pubkey>,vault:bool)->ProgramResult{
    if a.owner!=&TOKEN||a.data_len()!=165{return Err(ProgramError::InvalidAccountData)}
    let d=a.try_borrow_data()?;
    if key(&d,0)!=*mint||d[108]!=1||owner.is_some_and(|k|key(&d,32)!=*k){return Err(err())}
    if vault&&(d[72..76]!=[0;4]||d[109..113]!=[0;4]||d[129..133]!=[0;4]){return Err(err())}Ok(())
}
fn transfer_token<'a>(from:&AccountInfo<'a>,mint:&AccountInfo<'a>,to:&AccountInfo<'a>,authority:&AccountInfo<'a>,token:&AccountInfo<'a>,amount:u64,decimals:u8,seeds:Option<&[&[u8]]>)->ProgramResult{
    if amount==0{return Ok(())}if from.key==to.key||token.key!=&TOKEN{return Err(err())}
    let mut data=vec![12];data.extend_from_slice(&amount.to_le_bytes());data.push(decimals);
    let ix=Instruction{program_id:TOKEN,accounts:vec![AccountMeta::new(*from.key,false),AccountMeta::new_readonly(*mint.key,false),AccountMeta::new(*to.key,false),AccountMeta::new_readonly(*authority.key,true)],data};
    let infos=[from.clone(),mint.clone(),to.clone(),authority.clone(),token.clone()];
    match seeds{Some(s)=>invoke_signed(&ix,&infos,&[s]),None=>invoke(&ix,&infos)}
}
pub fn process_instruction(program:&Pubkey,a:&[AccountInfo],data:&[u8])->ProgramResult{
    match data.first(){
        Some(0) if data.len()==171=>initialize(program,a,data),
        Some(op @ 1..=3) if data.len()==1=>change(program,a,*op),
        Some(4) if data.len()==1=>settle(program,a,0,0,&[0;32],false),
        Some(5) if data.len()==42=>settle(program,a,number(data,1),data[9],&data[10..42],true),
        Some(6) if data.len()==1=>cancel_artist(program,a),
        Some(7) if data.len()==58=>settle_costs(program,a,data),
        _=>Err(ProgramError::InvalidInstructionData)
    }
}
fn initialize(program:&Pubkey,a:&[AccountInfo],input:&[u8])->ProgramResult{
    let asset=input[1];let decimals=input[2];
    if (asset==0&&a.len()!=5)||(asset==1&&a.len()!=9)||asset>1{return Err(err())}
    let client=&a[0];let artist=&a[1];let admin=&a[2];let escrow=&a[3];let system=&a[4];
    signer(client)?;signer(artist)?;signer(admin)?;writable(client)?;writable(escrow)?;
    if client.key==artist.key||client.key==admin.key||artist.key==admin.key||system.key!=&system_program::id(){return Err(err())}
    let hash=&input[3..35];let amount=number(input,35);let spend=key(input,43);let hodl=key(input,75);let fee=key(input,107);let mint=key(input,139);
    if hash.iter().all(|b|*b==0)||amount==0||amount>1_000_000_000_000_000||spend==hodl||spend==fee||hodl==fee{return Err(err())}
    if [spend,hodl,fee].iter().any(|k|k==escrow.key||k==client.key||*k==Pubkey::default()){return Err(err())}
    if (asset==0&&(mint!=Pubkey::default()||decimals!=9))||(asset==1&&decimals!=6){return Err(err())}
    let (expected,bump)=Pubkey::find_program_address(&[SEED,client.key.as_ref(),hash],program);
    if expected!=*escrow.key{return Err(ProgramError::InvalidSeeds)}
    if escrow.owner!=&system_program::id()||!escrow.data_is_empty(){return Err(ProgramError::AccountAlreadyInitialized)}
    if asset==1{
        if *a[5].key!=mint||a[5].owner!=&TOKEN||a[5].data_len()!=82||a[8].key!=&TOKEN{return Err(err())}
        let m=a[5].try_borrow_data()?;if m[44]!=6||m[45]!=1{return Err(err())}drop(m);
        token_account(&a[6],&mint,Some(client.key),false)?;token_account(&a[7],&mint,Some(escrow.key),true)?;
        if a[6].key==a[7].key{return Err(err())}
    }
    let rent=Rent::get()?.minimum_balance(SIZE);let missing=rent.saturating_sub(escrow.lamports());let bump_bytes=[bump];let seeds:&[&[u8]]=&[SEED,client.key.as_ref(),hash,&bump_bytes];
    if missing>0{invoke(&system_instruction::transfer(client.key,escrow.key,missing),&[client.clone(),escrow.clone(),system.clone()])?;}
    invoke_signed(&system_instruction::allocate(escrow.key,SIZE as u64),&[escrow.clone(),system.clone()],&[seeds])?;
    invoke_signed(&system_instruction::assign(escrow.key,program),&[escrow.clone(),system.clone()],&[seeds])?;
    {let mut d=escrow.try_borrow_mut_data()?;d[..8].copy_from_slice(MAGIC);d[8]=1;d[9]=bump;d[10]=asset;d[11]=decimals;d[12..44].copy_from_slice(hash);
     for(offset,k)in[(44,*client.key),(76,*artist.key),(108,*admin.key),(140,spend),(172,hodl),(204,fee),(236,mint)]{d[offset..offset+32].copy_from_slice(k.as_ref());}d[268..276].copy_from_slice(&amount.to_le_bytes());if asset==1{d[316..348].copy_from_slice(a[7].key.as_ref());}}
    if asset==0{invoke(&system_instruction::transfer(client.key,escrow.key,amount),&[client.clone(),escrow.clone(),system.clone()])?;}
    else{transfer_token(&a[6],&a[5],&a[7],client,&a[8],amount,decimals,None)?;}
    Ok(())
}
fn change(program:&Pubkey,a:&[AccountInfo],op:u8)->ProgramResult{
    if a.len()!=2{return Err(err())}signer(&a[0])?;verify(program,&a[1])?;let mut d=a[1].try_borrow_mut_data()?;
    match op{
      1 if d[8]==1&&*a[0].key==key(&d,76)=>{d[8]=2;d[276..284].copy_from_slice(&Clock::get()?.unix_timestamp.to_le_bytes());},
      2 if d[8]==2&&*a[0].key==key(&d,44)=>{d[8]=1;},
      3 if (d[8]==1||d[8]==2)&&(*a[0].key==key(&d,44)||*a[0].key==key(&d,76))=>{d[8]=3;},
      _=>return Err(err())
    }Ok(())
}
pub fn allocation(amount:u64,refund:u64,reason:u8)->Result<[u64;4],ProgramError>{
    if amount==0||amount>1_000_000_000_000_000||reason>2{return Err(err())}
    let fee=if reason==2{0}else{amount/100*15+(amount%100)*15/100};
    if refund>amount-fee||(reason==0&&refund!=0)||(reason==2&&refund!=amount){return Err(err())}
    let remaining=amount-fee-refund;let hodl=remaining/17;Ok([refund,remaining-hodl,hodl,fee])
}
// Reimbursed costs go to the immutable platform destination; the receipt hash binds
// the itemized off-chain evidence. The authority must verify receipts before signing.
pub fn allocation_with_costs(amount:u64,refund:u64,reason:u8,receiving:u64,refunding:u64)->Result<[u64;4],ProgramError>{
    let mut parts=allocation(amount,refund,reason)?;
    if refund==0&&refunding!=0{return Err(err())}
    let costs=receiving.checked_add(refunding).ok_or(err())?;
    let available=if refund>0{parts[0]}else{parts[3]};
    if costs>available{return Err(err())}
    if refund>0{parts[0]-=costs;parts[3]=parts[3].checked_add(costs).ok_or(err())?;}
    Ok(parts)
}
fn settle_costs(program:&Pubkey,a:&[AccountInfo],data:&[u8])->ProgramResult{
    if a.len()<2{return Err(err())}signer(&a[0])?;verify(program,&a[1])?;
    let d=a[1].try_borrow_data()?;let reason=data[9];
    if *a[0].key!=key(&d,108)||data[10..42].iter().all(|b|*b==0){return Err(err())}
    let base=if d[10]==0{6}else{9};
    if reason==0{
        if d[8]!=2||a.len()!=base+1||*a[base].key!=key(&d,44){return Err(err())}signer(&a[base])?;
    }else if d[8]!=3||a.len()!=base{return Err(err())}
    drop(d);
    pay_costs(program,&a[..base],number(data,1),reason,&data[10..42],number(data,42),number(data,50))
}
fn cancel_artist(program:&Pubkey,a:&[AccountInfo])->ProgramResult{
    if a.len()<2{return Err(err())}signer(&a[0])?;verify(program,&a[1])?;
    let d=a[1].try_borrow_data()?;if *a[0].key!=key(&d,76)||![1,2].contains(&d[8]){return Err(err())}let amount=number(&d,268);drop(d);
    pay(program,a,amount,2,&[0;32])
}
fn settle(program:&Pubkey,a:&[AccountInfo],refund:u64,reason:u8,resolution:&[u8],arbitrated:bool)->ProgramResult{
    if a.len()<2{return Err(err())}signer(&a[0])?;verify(program,&a[1])?;
    let d=a[1].try_borrow_data()?;
    if arbitrated{if d[8]!=3||*a[0].key!=key(&d,108)||resolution.iter().all(|b|*b==0){return Err(err())}}
    else if d[8]!=2||*a[0].key!=key(&d,44){return Err(err())}
    drop(d);pay(program,a,refund,reason,resolution)
}
fn pay(program:&Pubkey,a:&[AccountInfo],refund:u64,reason:u8,resolution:&[u8])->ProgramResult{
    pay_costs(program,a,refund,reason,resolution,0,0)
}
fn pay_costs(program:&Pubkey,a:&[AccountInfo],refund:u64,reason:u8,resolution:&[u8],receiving:u64,refunding:u64)->ProgramResult{
    verify(program,&a[1])?;let escrow=&a[1];let d=escrow.try_borrow_data()?.to_vec();let asset=d[10];
    if (asset==0&&a.len()!=6)||(asset==1&&a.len()!=9){return Err(err())}
    let client=key(&d,44);let mint=key(&d,236);let amounts=allocation_with_costs(number(&d,268),refund,reason,receiving,refunding)?;
    for(i,offset)in[(3,140),(4,172),(5,204)]{if *a[i].key!=key(&d,offset){return Err(err())}}
    for i in 2..6{writable(&a[i])?;if a[i].key==escrow.key{return Err(err())}for j in 2..i{if a[i].key==a[j].key{return Err(err())}}}
    if asset==0{
        if *a[2].key!=client||escrow.lamports()<Rent::get()?.minimum_balance(SIZE).checked_add(number(&d,268)).ok_or(err())?{return Err(err())}
        for(i,amount)in amounts.iter().enumerate(){let target=&a[i+2];let new=target.lamports().checked_add(*amount).ok_or(err())?;**escrow.try_borrow_mut_lamports()?-=amount;**target.try_borrow_mut_lamports()?=new;}
    }else{
        if *a[6].key!=mint||a[8].key!=&TOKEN||*a[7].key!=key(&d,316){return Err(err())}
        token_account(&a[7],&mint,Some(escrow.key),true)?;
        token_account(&a[2],&mint,Some(&client),false)?;
        for destination in &a[3..6]{token_account(destination,&mint,None,false)?;}
        let bump=[d[9]];let seeds:&[&[u8]]=&[SEED,&d[44..76],&d[12..44],&bump];
        for(i,amount)in amounts.iter().enumerate(){transfer_token(&a[7],&a[6],&a[i+2],escrow,&a[8],*amount,d[11],Some(seeds))?;}
    }
    let mut state=escrow.try_borrow_mut_data()?;state[8]=4;state[284..316].copy_from_slice(resolution);Ok(())
}
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn processing_refund_policy(){
 assert_eq!(allocation_with_costs(100_000,100_000,2,500,200).unwrap(),[99_300,0,0,700]);
 assert_eq!(allocation_with_costs(100_000,85_000,1,500,200).unwrap(),[84_300,0,0,15_700]);
 assert_eq!(allocation_with_costs(100_000,0,0,500,0).unwrap(),[0,80_000,5_000,15_000]);
 assert!(allocation_with_costs(100,100,2,100,1).is_err());
 assert!(allocation_with_costs(100,0,0,16,0).is_err());
 assert!(allocation_with_costs(100,0,0,0,1).is_err());
 assert!(allocation_with_costs(100,100,2,u64::MAX,1).is_err());
 }

 #[test]fn exact_split(){assert_eq!(allocation(100_000_000,0,0).unwrap(),[0,80_000_000,5_000_000,15_000_000]);}
 #[test]fn artist_fault_refunds_fee(){assert_eq!(allocation(100_000_000,100_000_000,2).unwrap(),[100_000_000,0,0,0]);assert!(allocation(100_000_000,85_000_000,2).is_err());}
 #[test]fn ordinary_refund_retains_fee(){assert_eq!(allocation(100_000_000,85_000_000,1).unwrap(),[85_000_000,0,0,15_000_000]);assert!(allocation(100,100,1).is_err());}
 #[test]fn rounding_conserves(){for amount in 1..10000{let v=allocation(amount,0,0).unwrap();assert_eq!(v.iter().sum::<u64>(),amount);}}
 #[test]fn malformed_instructions(){for bytes in [vec![],vec![0],vec![4,1],vec![5],vec![7]]{assert!(process_instruction(&Pubkey::new_unique(),&[],&bytes).is_err());}}
}
