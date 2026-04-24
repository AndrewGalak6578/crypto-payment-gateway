# Crypto Payment Gateway MVP - One Pager

## What this product is
Crypto Payment Gateway is an MVP for issuing USD-denominated invoices, accepting crypto payments, and settling merchant funds through either on-chain forwarding or internal balance crediting.

It is designed as an operational backend plus role-based interfaces:
- merchant API for system-to-system invoice operations
- merchant portal for day-to-day merchant operations
- admin portal for platform operations and controls
- hosted invoice page for end-customer payment

## Business flow this system covers
The system closes the invoice-to-settlement loop for merchants that want to bill in USD and receive crypto settlement:
1. Merchant creates invoice.
2. Gateway allocates a payment address and tracks incoming payments.
3. Invoice status advances by chain evidence.
4. Merchant net amount is settled:
- forwarded to configured destination wallet, or
- credited to internal merchant balance if wallet is missing.
5. Merchant receives signed webhooks for lifecycle events.

## Roles and interaction surfaces
- Merchant (API + Portal): invoice creation, invoice tracking, wallets, balances, API keys, webhook settings, webhook delivery history.
- Admin (Admin Portal): merchant management, merchant users and roles, wallet governance, invoice operations, webhook delivery operations.
- Customer (Hosted Invoice): public invoice page with payment instructions and status polling endpoint.

## Supported assets and networks on current `main`
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
- internal merchant balance fallback (`merchant_balances`)
6. webhook delivery:
- lifecycle events are enqueued
- async sender performs signed HTTP delivery
- retries are handled via persisted delivery attempts

## Technical highlights
- Laravel 12 backend + Vue 3 portals + Docker Sail workflow
- queue-driven monitoring, forwarding, and webhook delivery
- persisted webhook deliveries with retry/backoff behavior
- multi-asset model with `asset_key` + `network_key`
- chain-family abstraction: UTXO + native EVM + ERC-20
- separate admin and merchant operational portals
- wallet management and settlement routing
- EVM address allocation through derivation strategy
- ERC-20 gas sponsorship flow to unblock token payout from deposit addresses

## Why this matters for fintech / banking-style systems
- Reliability: asynchronous jobs isolate long-running settlement work from API response path.
- State machine discipline: explicit invoice transitions reduce ambiguous payment state.
- Auditability: invoice, forwarding, and webhook attempts are persisted and inspectable.
- Retries: webhook and deferred payout paths are designed for eventual completion.
- Operational visibility: admin and merchant UIs expose status, balances, and delivery history.
- Role separation: merchant vs admin responsibilities are enforced by separate auth and capability layers.

## Current MVP scope
- End-to-end invoice lifecycle from creation to settlement + webhook delivery.
- Merchant API, Merchant Portal, Admin Portal, Hosted Invoice flow.
- Multi-asset support with UTXO and local EVM/ERC-20 paths.
- Internal balance fallback when forwarding destination is not configured.

## Known constraints / next steps
- EVM paths are implemented for `evm_local` and should be treated as MVP/local-first integration until environment-specific custody/signing setup is finalized.
- Test webhook endpoints still exist in API routes and should be environment-controlled.
- Legacy `coin` compatibility still exists alongside `asset_key/network_key`; backfill discipline remains important.
