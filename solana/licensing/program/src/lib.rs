//! Experimental acknowledgement receipts. No licence grant, settlement, token or withdrawal instructions.
//! Instruction 0: creator proposes immutable hash + counterparty. Instruction 1: counterparty acknowledges hash.
use solana_program::{account_info::{next_account_info, AccountInfo}, entrypoint, entrypoint::ProgramResult,
    program::invoke_signed, program_error::ProgramError, pubkey::Pubkey, rent::Rent,
    system_instruction, system_program, sysvar::Sysvar};

#[cfg(not(feature = "no-entrypoint"))]
entrypoint!(process_instruction);
const MAGIC: &[u8; 8] = b"3PMDEV01";
pub const SIZE: usize = 105;
const SEED: &[u8] = b"3pm-devnet-v1";

pub fn process_instruction(program: &Pubkey, accounts: &[AccountInfo], data: &[u8]) -> ProgramResult {
    match data.first() {
        Some(0) if data.len() == 65 => propose(program, accounts, &data[1..33], &data[33..65]),
        Some(1) if data.len() == 33 => acknowledge(program, accounts, &data[1..33]),
        _ => Err(ProgramError::InvalidInstructionData), // No payment, sweep, mint or admin bypass opcode.
    }
}
fn propose(program: &Pubkey, accounts: &[AccountInfo], hash: &[u8], client: &[u8]) -> ProgramResult {
    if accounts.len() != 3 || hash.iter().all(|b| *b == 0) { return Err(ProgramError::InvalidArgument); }
    let mut iter = accounts.iter();
    let creator = next_account_info(&mut iter)?;
    let receipt = next_account_info(&mut iter)?;
    let system = next_account_info(&mut iter)?;
    if !creator.is_signer { return Err(ProgramError::MissingRequiredSignature); }
    if !creator.is_writable || !receipt.is_writable || system.key != &system_program::id() {
        return Err(ProgramError::InvalidAccountData);
    }
    let client_key = Pubkey::new_from_array(client.try_into().map_err(|_| ProgramError::InvalidArgument)?);
    if client_key == *creator.key || client_key == Pubkey::default() {
        return Err(ProgramError::InvalidArgument);
    }
    let (expected, bump) = Pubkey::find_program_address(&[SEED, creator.key.as_ref(), hash], program);
    if expected != *receipt.key { return Err(ProgramError::InvalidSeeds); }
    if receipt.owner != &system_program::id() || !receipt.data_is_empty() {
        return Err(ProgramError::AccountAlreadyInitialized);
    }
    let rent = Rent::get()?.minimum_balance(SIZE);
    let seeds: &[&[u8]] = &[SEED, creator.key.as_ref(), hash, &[bump]];
    // Accept prefunded PDAs safely: transfer only missing rent, then allocate/assign with PDA signature.
    let missing = rent.saturating_sub(receipt.lamports());
    if missing > 0 {
        invoke_signed(&system_instruction::transfer(creator.key, receipt.key, missing),
            &[creator.clone(), receipt.clone(), system.clone()], &[])?;
    }
    invoke_signed(&system_instruction::allocate(receipt.key, SIZE as u64),
        &[receipt.clone(), system.clone()], &[seeds])?;
    invoke_signed(&system_instruction::assign(receipt.key, program),
        &[receipt.clone(), system.clone()], &[seeds])?;
    let mut state = receipt.try_borrow_mut_data()?;
    state[..8].copy_from_slice(MAGIC);
    state[8..40].copy_from_slice(creator.key.as_ref());
    state[40..72].copy_from_slice(client);
    state[72..104].copy_from_slice(hash);
    state[104] = 0;
    Ok(())
}
fn acknowledge(program: &Pubkey, accounts: &[AccountInfo], hash: &[u8]) -> ProgramResult {
    if accounts.len() != 2 { return Err(ProgramError::InvalidArgument); }
    let client = &accounts[0]; let receipt = &accounts[1];
    if !client.is_signer { return Err(ProgramError::MissingRequiredSignature); }
    if !receipt.is_writable || receipt.owner != program || receipt.data_len() != SIZE {
        return Err(ProgramError::InvalidAccountData);
    }
    let mut state = receipt.try_borrow_mut_data()?;
    if &state[..8] != MAGIC || &state[40..72] != client.key.as_ref() || &state[72..104] != hash {
        return Err(ProgramError::InvalidArgument);
    }
    let creator = Pubkey::new_from_array(state[8..40].try_into().map_err(|_| ProgramError::InvalidAccountData)?);
    let (expected, _) = Pubkey::find_program_address(&[SEED, creator.as_ref(), hash], program);
    if expected != *receipt.key { return Err(ProgramError::InvalidSeeds); }
    if state[104] != 0 { return Err(ProgramError::AccountAlreadyInitialized); }
    state[104] = 1;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn unsupported_operations_cannot_pay_or_mutate() {
        let program = Pubkey::new_unique();
        for data in [vec![], vec![2], vec![0], vec![1], vec![255; 65]] {
            assert_eq!(process_instruction(&program, &[], &data), Err(ProgramError::InvalidInstructionData));
        }
    }
    #[test]
    fn valid_shape_without_accounts_is_rejected() {
        let mut proposal = vec![7u8; 65]; proposal[0] = 0;
        let mut ack = vec![7u8; 33]; ack[0] = 1;
        for data in [proposal, ack] {
            assert_eq!(process_instruction(&Pubkey::new_unique(), &[], &data), Err(ProgramError::InvalidArgument));
        }
    }
    #[test]
    fn unsigned_counterparty_cannot_acknowledge() {
        let program = Pubkey::new_unique(); let client = Pubkey::new_unique(); let receipt = Pubkey::new_unique();
        let mut client_lamports = 0; let mut receipt_lamports = 1;
        let mut empty = []; let mut data = [0u8; SIZE];
        let accounts = [
            AccountInfo::new(&client, false, false, &mut client_lamports, &mut empty, &system_program::ID, false, 0),
            AccountInfo::new(&receipt, false, true, &mut receipt_lamports, &mut data, &program, false, 0),
        ];
        assert_eq!(acknowledge(&program, &accounts, &[1u8;32]), Err(ProgramError::MissingRequiredSignature));
    }
}
