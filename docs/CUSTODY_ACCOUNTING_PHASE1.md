# Custody Accounting Phase 1

Phase 1 installs a gated accounting foundation. It does not change invoice routing, write
legacy balances, create payout requests, or move funds. Existing `merchant_balances` and
completed `merchant_settlement_entries` remain authoritative for current production flows.

## Data Model

- `custody_accounts` defines immutable, asset/network-specific accounts. `asset_scale` is
  copied from `AssetRegistry` at creation and cannot change.
- `custody_journal_entries` stores one immutable financial event and its SHA-256
  `canonical_payload_hash`.
- `custody_journal_postings` stores ordered positive debit/credit lines.
- `custody_account_balances` is a rebuildable projection in each account's normal-side
  representation.

Merchant `available`, `reserved`, and `held` liability accounts are credit-normal and require
the owning `merchant_id`. Platform asset, treasury, outbound, and network-expense accounts are
debit-normal; platform fee revenue is credit-normal. Migration suspense is debit-normal and
cannot pretend to be merchant-owned. Financial foreign keys use restrictive deletion.

PostgreSQL deferred constraint triggers reject a commit unless every journal entry:

- has `posted_at` and at least two postings across at least two distinct accounts;
- uses one exact `asset_key`, `network_key`, and immutable `asset_scale`;
- uses merchant liability accounts owned by the entry's merchant;
- contains only positive amounts with no precision beyond that scale;
- has exactly equal debit and credit totals;
- exactly inverts the target posting set when it is an effective reversal.

Posted entries and all their postings are append-only. The only journal update is the initial
`posted_at=null` to posted transition without any financial-payload change. Corrections are new
entries, and a partial unique index permits only one posted reversal per source entry.

## Journal Write

`CustodyJournalWriter` accepts decimal strings only and uses `Brick\Math\BigDecimal`. It:

1. resolves the registry asset/network and scale;
2. locks all referenced accounts in ascending ID order;
3. validates and canonicalizes ordered postings and immutable metadata;
4. inserts the idempotency key with PostgreSQL `ON CONFLICT DO NOTHING`;
5. returns an existing posted entry when its canonical hash is identical, or throws a domain
   conflict when the hash differs;
6. inserts postings, locks projections in ascending account-ID order, applies normal-side
   changes, increments each touched projection revision once, and records the entry ID;
7. rejects a negative controlled-account projection;
8. sets `posted_at` and commits, allowing deferred database validation to make the final
   decision.

An idempotent replay never changes a projection. No database transaction is retained across
external I/O. Phase 1 has no caller in invoice settlement or payout execution.

The hash covers event type, merchant and source references, asset/network/scale, effective time,
reason, reversal reference, ordered normalized postings, atomic amounts when supplied, and all
immutable metadata. Floats, numeric input types, exponent notation, signs, whitespace, excessive
integer digits, excessive scale, zero, and negative posting amounts are rejected.

## Operations

Verify projections without writing:

```bash
php artisan custody:verify-projections
php artisan custody:verify-projections --merchant=123 --asset=btc --json
```

The command exits non-zero when drift exists and reads from one repeatable, read-only snapshot.

Preview or explicitly apply a rebuild from posted immutable history:

```bash
php artisan custody:rebuild-projections
php artisan custody:rebuild-projections --merchant=123 --asset=btc --write
```

The default is dry-run. `--write` changes only projection rows, never journal history.

Compare legacy balances with completed internal credits:

```bash
php artisan custody:reconcile-legacy-balances
php artisan custody:reconcile-legacy-balances --merchant=123 --asset=btc --json
```

This command is strictly read-only. It excludes held/deferred entries and reports exact matches,
missing balance or credit sides, either-side amount excess, unknown assets, network ambiguity,
and negative legacy data.

## Feature Gates

All gates default to `false`:

```dotenv
CUSTODY_ACCOUNTING_ENABLED=false
CUSTODY_JOURNAL_WRITES_ENABLED=false
CUSTODY_INVOICE_ROUTING_ENABLED=false
PAYOUT_REQUESTS_ENABLED=false
PAYOUT_AUTOMATIC_REQUESTS_ENABLED=false
PAYOUT_EXECUTION_ENABLED=false
```

Enabling the first two gates only permits an explicit infrastructure caller to create accounts
and journal entries. It does not activate invoice routing. The remaining gates have no Phase 1
execution path.

## Phase 2 Boundary

Before production custody routing, operators must review legacy reconciliation output and define
an approved journal migration. Payout requests, destination versioning, treasury collection,
chain-liquidity accounting, network-fee policy, approval controls, production signing, and
broadcast reconciliation remain separate gated work. Existing held invoices are not imported,
released, or converted by Phase 1.
