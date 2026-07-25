# Settlane - One Pager

## What this product is
Settlane is a product-shaped MVP for issuing USD-denominated crypto invoices, accepting payments, and settling merchant funds through either on-chain forwarding or internal balance crediting.

It is designed as an operational backend plus role-based interfaces:
- merchant API for system-to-system invoice operations
- Merchant Portal v2 for day-to-day merchant operations
- admin portal for platform operations and controls
- hosted checkout page for end-customer payment

## Business flow this system covers
The system closes the invoice-to-settlement loop for merchants that want to bill in USD and receive crypto settlement:
1. Merchant creates invoice.
2. Payer either uses a fixed asset or chooses an allowed asset on the hosted checkout page.
3. Gateway allocates a payment address and tracks incoming payments.
4. Invoice status advances by chain evidence.
5. Merchant net amount is settled after merchant fee deduction:
   - forwarded to configured destination wallet, or
   - held when a merchant-owned destination wallet is missing, or credited internally only under explicit admin custodial policy.
6. Merchant receives signed webhooks for lifecycle events.
7. Merchant users can inspect payments, settlements, webhook deliveries, and teammate audit activity in the portal.

## Roles and interaction surfaces
- Merchant (API + Portal): invoice creation, payment tracking, checkout settings, per-asset settlement rules, destination wallets, balances, settlement ledger, API keys, webhook settings, webhook delivery history, team management, teammate dossiers.
- Admin (Admin Portal): merchant management, merchant users and roles, wallet governance, invoice operations, webhook delivery operations.
- Customer (Hosted Checkout): public checkout page with asset selection, payment instructions, QR/address copy actions, status polling, partial-payment guidance, confirmation state, and expired-state safety behavior.

## Supported assets and networks in the current codebase
Configured in `config/assets.php` and `config/chains.php`:
- `btc` on `bitcoin` (UTXO)
- `ltc` on `litecoin` (UTXO)
- `dash` on `dash` (UTXO)
- `eth_local` on `evm_local` (native EVM)
- `eth_usdt_local` on `evm_local` (ERC-20)

## Invoice lifecycle
Core status path:
1. `create` -> invoice issued with `pending` status
2. `pending` -> payment detected
3. `fixated` -> first valid payment seen before expiry
4. `paid` -> confirmed amount crosses paid threshold
5. settlement:
   - `forwarded` on-chain to destination wallet, or
   - explicit admin-policy internal credit (`merchant_balances`), or
   - explicit non-retryable `held` / `manual` state with a deferred policy ledger entry
6. webhook delivery:
   - lifecycle events are enqueued
   - async sender performs signed HTTP delivery
   - retries are handled via persisted delivery attempts
7. merchant activity:
   - portal actions are written to `merchant_activity_logs`
   - teammate dossier pages expose activity by actor/subject, section, and action type

## Technical highlights
- Laravel 12 backend + Vue 3 portals + Docker Sail workflow
- queue-driven monitoring, forwarding, and webhook delivery
- persisted webhook deliveries with retry/backoff behavior
- multi-asset model with `asset_key` + `network_key`
- chain-family abstraction: UTXO + native EVM + ERC-20
- separate admin portal and Merchant Portal v2
- merchant dashboard metrics, payment workspace, create-payment flow, settlement workspace, per-asset Settlement Rules, developer tools, settings, and team module
- wallet management and settlement routing
- settlement ledger entries with backfill command for older invoice forwarding summaries
- merchant activity logging with sensitive metadata scrubbing
- EVM address allocation through derivation strategy
- layered asset/settlement policy resolution with physically separate admin authority and merchant intent
- ERC-20 gas sponsorship flow to unblock token payout from deposit addresses when settlement policy allows an automatic sweep
- explicit forwarding states distinguish retryable failures from policy-held and manual settlements
- durable settlement attempts separate reservation, broadcast ambiguity, chain confirmation, and accounting completion
- automatic EVM/UTXO reconciliation verifies transaction identity before completion or retry-safe failure
- automatic EVM gas-funding reconciliation verifies source/nonce/value/receipt and serializes gas-station nonces
- transactional idempotent `invoice.forwarded` outbox with periodic pending-delivery recovery
- exact asset-scale settlement arithmetic uses decimal strings and `Brick\Math\BigDecimal`

## Why this matters for fintech / banking-style systems
- Reliability: asynchronous jobs isolate long-running settlement work from API response path.
- State machine discipline: explicit invoice transitions reduce ambiguous payment state.
- Auditability: invoice, forwarding, and webhook attempts are persisted and inspectable.
- Accountability: merchant team actions are logged and visible through teammate dossier pages.
- Retries: webhook and deferred payout paths are designed for eventual completion.
- Operational visibility: admin and merchant UIs expose status, balances, and delivery history.
- Role separation: merchant vs admin responsibilities are enforced by separate auth and capability layers.

## Current MVP scope
- End-to-end invoice lifecycle from creation to settlement + webhook delivery.
- Merchant API, Merchant Portal v2, Admin Portal, Hosted Checkout flow.
- Multi-asset support with UTXO and local EVM/ERC-20 paths.
- Missing merchant wallets produce explicit non-retryable holds instead of implicit internal credit.
- Merchant Settlement Rules expose immediate, per-invoice threshold, inherit, and Pause settlements modes with exact decimal strings and optimistic revisions.
- Merchant checkout settings for branding, redirects, allowed assets, expiration, partial-payment behavior, confirmation display, and min/max amounts.
- Team management and teammate audit dossiers.

## Known constraints / next steps
- Global admin policy UI and an authorized, audited hold-release command are not implemented; merchant Settlement Rules are available, while `held` and `manual` invoices remain terminal for automatic retry.
- `max_gas_cost` is decision/ledger metadata only and is not enforced by EVM gas estimation or sponsorship yet.
- Durable settlement attempts quarantine every error after `broadcasting`; `held`, `manual`, and `needs_reconciliation` never auto-retry while the invoice remains `paid`.
- Payout and gas-funding reconciliation jobs/commands are implemented. Admin UI and authorized held/manual or quarantined-evidence disposition remain missing; those records must never be released by editing status fields.
- EVM paths are implemented for `evm_local` and should be treated as MVP/local-first integration until environment-specific custody/signing setup is finalized.
- Test webhook endpoints still exist in API routes and should be environment-controlled.
- Legacy `coin` compatibility still exists alongside `asset_key/network_key`; settlement-ledger dry-run/backfill output must be reviewed before workers are enabled on upgraded databases.
- There is no browser E2E suite yet; portal regression coverage is a mix of PHPUnit API tests, Vite build, and manual smoke.
