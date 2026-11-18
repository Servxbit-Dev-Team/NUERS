# Registered business wallet operations — 18 October 2026

Open **Registered Businesses → business Actions (⋮) → Wallet**. The same wallet is available from **Wallets & Transaction Pricing → Open Wallet**. Administrators may view the business's actual balance and all ledger entries. Manual credits and withdrawals are restricted to a Super Admin independent of the business owner, with current MFA.

## Manual settlement workflow

1. Select **Manual Top-Up** or **Withdrawal** for the named business.
2. Enter PHP amount, approved payment channel, unique settlement/trace reference, settlement date, and a meaningful reason/evidence reference.
3. Review the current and projected balances. Verify the external settlement and recipient, then confirm posting.
4. The server atomically appends the wallet entry, encrypted adjustment details, audit event and owner notification intent. The history shows timestamp (PHT), reference, type, signed change, balances before/after, administrator identity, settlement details, reason, source ID and entry hash.

These are records of funds handled outside NUERS. No bank transfer, payment gateway, automatic payout or auto-top-up is connected. Merchant-requested funding still follows its existing independent approval workflow. Manual credits share that workflow's payment/settlement duplicate guards; a matching pending request must be reviewed rather than credited again.

## Controls and history

- PHP1.00–PHP1,000,000.00 per adjustment; integer centavos on the API, two-decimal PHP in the UI. Positive input only; withdrawal is posted as a negative ledger change.
- Merchant row and wallet locks serialize changes with intake fees and funding approvals. A reviewed balance/sequence must still match. An insufficient withdrawal or stale balance posts nothing.
- UUID request identity is retained for an uncertain response. An identical retry returns the original entry without posting again; changed payload conflicts. Settlement references cannot be reused for the same payment channel and adjustment direction across businesses. Top-up settlement/payment hashes also share the preexisting funding review domain.
- Financial entries and adjustment records have no edit/delete endpoint. Correct mistakes through a separately referenced compensating adjustment with evidence, preserving the original.
- Transaction history includes fee deductions, approved top-ups and withdrawals. It supports date/type filters, pagination and CSV export up to 5,000 filtered entries. Reason and settlement references are encrypted at rest. CSV escapes untrusted spreadsheet formulas.
- Merchant Wallet supports withdrawal filters and its chart subtracts withdrawals when calculating daily balances. Existing fees and idempotent source retries retain their original behavior.
- Adjustment notices are queued through existing NUERS notifications; recipient verification/preferences and existing SMTP delivery controls apply. Notices contain no payment reference or reason. Tenant portability includes owned adjustment records and their audit events, excluding request/deduplication hashes.

## Implementation evidence

- `AdminBusinessWalletController`: administrative read/export, Super Admin/MFA manual adjustment, stale-balance checks, shared settlement deduplication and atomic audit.
- `MerchantWallet::withdraw`: existing ledger append/hash-chain and balance invariant under the same merchant/wallet lock as fee charging.
- `2026_10_18_000003_create_merchant_wallet_adjustments.php`: additive record table, unique request/settlement/ledger constraints, no destructive rollback.
- `business-wallet.tsx`: review-before-post forms, no automatic financial submission, duplicate-safe retry after unknown outcome.
- `AdminBusinessWalletTest`: role/MFA/owner restrictions, duplicates, pending and approved funding collisions, overdraft, stale balances, rollback, encryption, scoped history/export/portability, merchant chart reconciliation.

No live financial operation, BIR transmission or external email is used to verify this release.
