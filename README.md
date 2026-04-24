# Crypto Payment Gateway (MVP)

## Project overview
Crypto Payment Gateway is a Laravel + Vue system for crypto invoice processing with operational portals.

The repository already contains a working MVP pipeline:
- issue invoices in USD terms
- allocate per-invoice deposit addresses
- monitor chain payments asynchronously
- run settlement (wallet forwarding or internal balance fallback)
- deliver signed webhooks with retries

This is not positioned as "production-ready everywhere". It is a serious MVP with implemented end-to-end flow and explicit operational constraints.

## What this system does
At runtime, the system closes the invoice lifecycle for merchants:
1. Merchant creates an invoice through API or merchant portal.
2. Gateway snapshots rate, computes coin amount, allocates deposit address.
3. Queue worker monitors on-chain payments and moves invoice state.
4. After payment confirmation, settlement runs:
- forward merchant net to configured destination wallet, or
- credit internal merchant balance when wallet is absent.
5. State transitions are published to merchant webhook endpoint via signed delivery jobs.

## Supported flows and assets
Configured in current `main` (`config/assets.php`, `config/chains.php`):

| Asset key | Network key | Family | Type |
|---|---|---|---|
| `btc` | `bitcoin` | UTXO | Native coin |
| `ltc` | `litecoin` | UTXO | Native coin |
| `dash` | `dash` | UTXO | Native coin |
| `eth_local` | `evm_local` | EVM | Native coin |
| `eth_usdt_local` | `evm_local` | EVM | ERC-20 token |

High-level support status on `main`:
- UTXO path (BTC/LTC/DASH): invoice monitoring and forwarding path is implemented and covered by tests.
- EVM path (`evm_local`): native + ERC-20 monitoring and settlement plumbing exists, including ERC-20 gas sponsorship flow for payout preconditions.

## Merchant/admin/hosted surfaces
- Merchant API (`/api/v1/*` with bearer API key): create invoice, read invoice, refresh invoice.
- Merchant Portal (`/merchant/*`): dashboard, invoices, balances, wallets, API keys, webhook settings, webhook deliveries.
- Admin Portal (`/admin/*`): dashboard, merchants, merchant users, invoices, webhook deliveries, merchant API keys, merchant wallets.
- Hosted Invoice (`/i/{publicId}` + `/i/{publicId}/status`): customer-facing invoice payment surface.

## Architecture overview
Single Laravel backend provides API, portals, hosted flow, and queue workers.

Main pipeline components:
- `InvoiceCreator` - invoice creation, rate snapshot, address allocation, monitor scheduling
- `MonitorInvoiceJob` + `InvoiceStatusRefresher` - chain polling and state transitions
- `ForwardInvoiceJob` + `InvoiceForwarder` - settlement orchestration
- `MerchantBalanceCreditor` - fallback payout bookkeeping
- `EnqueueInvoiceWebhook` + `DeliverWebhookJob` + `WebhookDeliverySender` - signed webhook delivery with retries

Visual architecture page:
- `/architecture` (browser-friendly overview for demos)

## Core business lifecycle
Invoice status and settlement lifecycle:
1. `create` -> invoice created with `pending`
2. `pending` -> payment detected on allocated address
3. `fixated` -> first valid payment seen before expiry
4. `paid` -> confirmed amount reaches paid threshold
5. settlement stage:
- on-chain forwarding to merchant wallet (`forward_status` progresses), or
- internal balance credit fallback
6. webhook stage:
- events persisted as delivery records
- async sender attempts delivery
- failed deliveries retried by policy

## Current MVP scope
Included now:
- end-to-end invoice processing loop
- async monitoring/settlement/webhook jobs
- role-separated merchant and admin operational surfaces
- hosted customer invoice flow
- multi-asset routing via `asset_key` + `network_key`
- UTXO + local EVM native/ERC-20 support model

Not claimed here:
- universal production custody/signing integration for all EVM environments
- fully hardened production operations for every deployment context out of the box

## Tech stack
- Backend: PHP 8.2, Laravel 12, Eloquent ORM, queued jobs
- Frontend: Vue 3, Vite, portal SPA entrypoints
- Infra/dev: Docker Sail, PostgreSQL/Redis (compose), optional local chain nodes
- Testing: PHPUnit (Feature/Unit/Integration)

## Local development
Fast local bootstrap:

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --force
php artisan db:seed
php artisan db:seed --class=AdminUserSeeder
```

Run full local app stack (recommended for active development):

```bash
composer dev
```

Or run parts separately:

```bash
npm run dev
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
```

One-command project bootstrap is also available:

```bash
composer setup
```

## Verification/testing
Main commands:

```bash
composer test
composer test:fast
composer test:integration
composer test:all
npm run build
```

Practical verification note:
- Queue worker must be running to observe monitoring, forwarding, and webhook progression in real flows.

## Demo flow
Quick demo sequence (career fair friendly):
1. Admin creates merchant and merchant user.
2. Merchant configures destination wallet and webhook URL/secret.
3. Merchant creates invoice (API or portal test-invoice page).
4. Customer opens hosted invoice page and pays.
5. Operator observes status progression: `pending -> fixated -> paid`.
6. Operator observes settlement outcome: forwarded on-chain or internal balance fallback.
7. Operator verifies webhook delivery history and invoice record in admin portal.

## Known limitations
- Test webhook receiver routes are still present in API routes and should be controlled per environment.
- Legacy `coin` compatibility remains alongside `asset_key/network_key`; data consistency depends on migration/backfill discipline.
- EVM flows are centered on configured local network path (`evm_local`) in this repository context.
- No browser E2E suite is included in-repo (current coverage is API/service/integration-centric).

## Why this project is interesting technically
- Clean state-machine-driven lifecycle for money movement events.
- Queue-first architecture for reliability and operational decoupling.
- Multi-chain abstraction with family-aware logic (UTXO vs EVM vs ERC-20).
- Role-separated operational interfaces (merchant/admin) with capability checks.
- Explicit webhook pipeline with persistence, signatures, retries, and admin visibility.
- Practical settlement resilience: wallet forwarding plus internal balance fallback.

## Related docs
- One-pager: `docs/PROJECT_ONE_PAGER.md`
- Architecture demo page: `/architecture`
- Verification checklist: `docs/MVP_VERIFICATION_CHECKLIST.md`
- Known gaps register: `docs/MVP_KNOWN_GAPS.md`
