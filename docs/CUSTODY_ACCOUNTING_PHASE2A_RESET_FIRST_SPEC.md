# Custody Accounting Phase 2A — Reset-First Implementation Specification

**Version:** 1.2
**Status:** Canonical design specification; implementation is not yet authorized.

## 1. Status and decision

Phase 2A uses a **reset-first** implementation path. The target environment is
disposable demo data with no real users, real funds, or external financial
obligations. A controlled `migrate:fresh --seed` is therefore an independent
operator action that establishes a clean implementation baseline; it is not a
data-migration mechanism and it is not part of application runtime.

Implementation may start only after an independent review of this specification.
Until that review outcome explicitly authorizes it, this document does not authorize
application, schema, configuration, or test changes, nor commits. Once implementation
is independently authorized, the narrowly scoped local destructive and real-node
integration-test authority in section 14 governs those operational actions.

This phase creates immutable shadow accounting history only for **new explicit
`internal_balance_only` credits** created after the one-way Phase 2A cutover.

## 2. Confirmed repository facts

- `main = origin/main = 95e7d8c` (`feat(custody): add immutable accounting journal foundation`).
- The worktree was clean when this specification was prepared.
- Custody and payout gates default to, and currently resolve to, `false`.
- The custody journal, custody accounts, and custody projections are empty.
- `CustodyJournalWriter` and Phase 1 PostgreSQL integrity constraints already exist,
  but no production invoice, balance, settlement, API, or UI flow calls the writer.
- `MerchantBalanceCreditor` currently updates `merchant_balances`, creates a
  completed `merchant_settlement_entries` internal-credit row, marks the invoice
  done, and enqueues a webhook in one database transaction.
- `InvoiceForwarder` locks invoice and merchant before calling the internal-credit
  branch.
- `merchant_settlement_entries` are not intrinsically immutable today, and
  `MerchantSettlementLedger::recordInternalCredit` currently uses `firstOrCreate`
  without exact-payload assertion.
- `custody:reconcile-legacy-balances` found 18 inferred BTC internal credits whose
  aggregate matched the BTC balance, plus 4 inferred ETH credits and an unexplained
  ETH residual of `6.860000000000000000`.
- All 22 completed internal credits were created by legacy
  `settlements:backfill-ledger`, carry `metadata.backfilled=true`, and use
  `reason=no_forward_txids_present`.
- PostgreSQL is required. The current Sail database is PostgreSQL 18.4 and exposes
  the built-in `sha256(bytea)` function; `pgcrypto.digest(bytea,text)` is absent.
- Independent baseline custody coverage passed: 36 tests and 230 assertions.

## 3. Scope

### Included

- New clean Phase 2A schema and PostgreSQL constraints.
- A one-way, immutable Phase 2A cutover marker.
- Shadow dual-write of new positive explicit internal credits.
- Exact source creation/assertion, journal source links, and parity verification.
- Hardening of the existing `settlements:backfill-ledger` command so it cannot bypass
  the cutover.
- Tests for PostgreSQL integrity, replay, concurrency, gates, and regression scope.

### Excluded

- Historical custody backfill and all historical migration tooling.
- Historical manifests, backfill run records, and quarantine tables.
- Custody invoice routing.
- Payout requests, automatic payout requests, payout execution, or payout approval.
- Treasury, deposit collection, outbound, fee-revenue, or network-fee accounting.
- Signer, chain RPC, or any external I/O in the Phase 2A credit transaction. Section
  14 separately authorizes controlled test-node integration; it does not add such I/O
  to the Phase 2A credit transaction.
- Merchant/admin API or UI custody read models.

## 4. Exact schema and migration ordering

All Phase 2A migrations must assert PostgreSQL before modifying schema. They must
also assert that `to_regprocedure('sha256(bytea)')` is present. The supported
PostgreSQL baseline for this phase uses the built-in expression below; Phase 2A must
fail migration rather than silently adding `pgcrypto` or using another hash function.

```sql
encode(sha256(convert_to(source_snapshot_canonical_text, 'UTF8')), 'hex')
```

### 4.1 Migration ordering

1. Assert PostgreSQL and the built-in `sha256(bytea)` capability.
2. Install `custody_phase2a_canonical_json_text(jsonb) RETURNS text`.
3. Alter the `custody_accounts` ownership/code constraint to add
   `internal_credit_shadow_offset`.
4. Add the partial unique index for completed internal-credit sources by invoice.
5. Create `custody_journal_source_links` and its indexes, checks, and restrictive
   foreign keys.
6. Create `custody_phase2a_cutovers` and its singleton/check constraints.
7. Install immediate immutability triggers.
8. Install deferred validation functions and constraint triggers.

The migration sequence must preserve the existing Phase 1 journal constraints and
must not alter any existing account code semantics.

### 4.2 `custody_accounts` extension

Add account code `internal_credit_shadow_offset` with these mandatory properties:

- `scope_key = 'platform'`;
- `merchant_id IS NULL`;
- `normal_side = 'debit'`;
- one account per asset/network/asset-scale under the existing platform uniqueness
  model.

It is a controlled shadow offset only. It is not `migration_suspense`, treasury,
deposit collection, fee revenue, outbound, or a payout account.

### 4.3 `custody_journal_source_links`

| Column | Type and constraint |
|---|---|
| `id` | `bigint` primary key |
| `merchant_settlement_entry_id` | `bigint NOT NULL UNIQUE`, FK to `merchant_settlement_entries(id)` with `ON DELETE RESTRICT` |
| `custody_journal_entry_id` | `bigint NOT NULL UNIQUE`, FK to `custody_journal_entries(id)` with `ON DELETE RESTRICT` |
| `asset_scale` | `smallint NOT NULL CHECK (asset_scale BETWEEN 0 AND 18)` |
| `source_kind` | `varchar(64) NOT NULL CHECK (source_kind = 'live_explicit_internal_credit')` |
| `source_version` | `smallint NOT NULL CHECK (source_version = 1)` |
| `source_snapshot_canonical_text` | `text NOT NULL` |
| `source_snapshot_hash` | `char(64) NOT NULL`, lowercase hexadecimal SHA-256 check |
| `source_snapshot_jsonb` | nullable `jsonb`, inspection-only |
| `created_at` | `timestamp with time zone NOT NULL` |

Indexes are supplied by the two unique constraints. Add all of these checks:

- `source_snapshot_canonical_text` parses as a JSON object even when
  `source_snapshot_jsonb` is null;
- the text equals the output of the installed recursive canonical-JSON function;
- the stored hash equals the built-in SHA-256 expression over the exact canonical
  text;
- if `source_snapshot_jsonb` is present, it equals
  `source_snapshot_canonical_text::jsonb`.

JSONB is never serialized as hash input. The required function is
`custody_phase2a_canonical_json_text(jsonb) RETURNS text`; it recursively sorts object
keys by UTF-8 bytewise lexicographic order, preserves array order, and emits compact
UTF-8 JSON. The link table must have checks equivalent to:

```sql
jsonb_typeof(source_snapshot_canonical_text::jsonb) = 'object'
source_snapshot_canonical_text = custody_phase2a_canonical_json_text(source_snapshot_canonical_text::jsonb)
source_snapshot_hash = encode(sha256(convert_to(source_snapshot_canonical_text, 'UTF8')), 'hex')
```

### 4.4 `custody_phase2a_cutovers`

This is a singleton, immutable, one-way marker.

| Column | Type and constraint |
|---|---|
| `phase_key` | `varchar(64)` primary key, exactly `internal_credit_shadow_v1` |
| `activated_at` | `timestamp with time zone NOT NULL` |
| `activation_reference` | `varchar(191) NOT NULL` |
| `baseline_verification_canonical_text` | `text NOT NULL`, canonical JSON object |
| `baseline_verification_hash` | `char(64) NOT NULL`, lowercase hexadecimal SHA-256 check |
| `activation_config_canonical_text` | `text NOT NULL`, canonical JSON object |
| `activation_config_fingerprint` | `char(64) NOT NULL`, lowercase hexadecimal SHA-256 check |
| `created_at` | `timestamp with time zone NOT NULL` |

No `updated_at` column exists. Both canonical-text columns must parse as JSON
objects, equal `custody_phase2a_canonical_json_text(text::jsonb)`, and have hashes
that equal the built-in SHA-256 expression over their exact canonical text. A trigger
rejects every `UPDATE` and `DELETE`.

### 4.5 Completed internal-credit source uniqueness

Before creating the index, the migration must execute a duplicate preflight grouped
by non-null `invoice_id` over rows where `type='internal_credit'` and
`status='completed'`. If any group has `COUNT(*) > 1`, it must abort with a clear
message that names the duplicate invoice IDs. The migration must not update, delete,
merge, or otherwise repair those rows.

After a clean preflight, create this exact PostgreSQL index:

```sql
CREATE UNIQUE INDEX merchant_settlement_entries_completed_internal_credit_invoice_unique
ON merchant_settlement_entries (invoice_id)
WHERE invoice_id IS NOT NULL
  AND type = 'internal_credit'
  AND status = 'completed'
```

An invoice can therefore have at most one completed internal-credit source,
regardless of whether a caller uses `invoice:<id>:internal-credit` or
`invoice:<id>:backfill:internal-credit` as its idempotency key.

## 5. Exact PostgreSQL invariants

### 5.1 Journal, link, and source coverage

For every posted `internal_credit_shadow_v1` journal entry, exactly one
`custody_journal_source_links` row must exist. For every source link, exactly one
posted `internal_credit_shadow_v1` journal entry must exist. The unique constraints
enforce at-most-one; deferred validation enforces exactly-one and correspondence.

After the `internal_credit_shadow_v1` cutover marker exists, every
`merchant_settlement_entries` row with `type='internal_credit'` and
`status='completed'` must have exactly one source link. Pre-cutover uncovered
completed sources can exist, but they prevent activation because the cutover baseline
must be zero and fully covered.

### 5.2 Source-derived event identity

For a source with ID `S`, deferred validation requires all of these values exactly:

```text
journal.idempotency_key = custody:internal-credit:merchant-settlement-entry:S:v1
journal.source_reference = merchant_settlement_entry:S
journal.effective_at = source.occurred_at
```

`source.occurred_at` must be non-null. Immutable journal metadata must contain the
same source ID, `source_kind=live_explicit_internal_credit`, `source_version=1`,
source snapshot hash, and asset scale as the source link.

### 5.3 Exact posting shape

Each shadow event has exactly two postings across exactly two accounts:

1. debit platform `internal_credit_shadow_offset`;
2. credit the linked source merchant's `merchant_available` account.

Both posting amounts are positive and exactly equal to the canonical source
`amount_coin`. No third posting is allowed.

The event merchant, source merchant, merchant liability account owner, asset key,
network key, and asset scale must all correspond. The link asset scale must equal
the journal scale and both account scales.

### 5.4 Canonical source snapshot and immutability

`source_snapshot_canonical_text` is compact UTF-8 RFC 8259 JSON with no
insignificant whitespace. It must parse as a JSON object even when the optional
`source_snapshot_jsonb` column is null. The migration installs a recursive canonical
JSON function used by a CHECK constraint: every object is normalized by ascending
lexicographic comparison of its UTF-8 key bytes, while list order is preserved. The
function recursively applies the same rule to nested objects and emits the compact
canonical text.

The exact top-level JSON object has these fields in this exact canonical order:

```json
{
  "amount_coin": "<asset-scale decimal string>",
  "amount_usd": "<scale-2 decimal string>|null",
  "asset_key": "<registry asset key>",
  "asset_scale": <integer>,
  "destination_wallet": null,
  "error_message": null,
  "fee_coin": "<asset-scale decimal string>|null",
  "id": <merchant_settlement_entry integer ID>,
  "idempotency_key": "invoice:<invoice-id>:internal-credit",
  "invoice_id": <invoice integer ID>,
  "merchant_id": <merchant integer ID>,
  "metadata": {
    "invoice_public_id": "<invoice public ID>",
    "reason": "internal_balance_only"
  },
  "network_key": "<registry network key>",
  "occurred_at": "YYYY-MM-DDTHH:mm:ss.ffffffZ",
  "settlement_attempt_id": null,
  "source_kind": "live_explicit_internal_credit",
  "source_version": 1,
  "status": "completed",
  "txid": null,
  "type": "internal_credit"
}
```

`metadata` must be exactly the shown two-key object: it requires
`invoice_public_id` equal to the persisted invoice public ID and
`reason=internal_balance_only`; extra metadata keys are not permitted for a Phase 2A
source. `destination_wallet`, `txid`, `error_message`, and `settlement_attempt_id`
must be null.

`amount_coin` is a canonical string at `asset_scale`; nullable `fee_coin`, when
present, is also a canonical string at `asset_scale`; nullable `amount_usd`, when
present, is a canonical scale-2 string. Numeric identity and scale fields are JSON
integers. `occurred_at` is re-read from the persisted database source after creation,
not regenerated from an application clock, and is formatted exactly as UTC with six
fractional digits: `YYYY-MM-DDTHH:mm:ss.ffffffZ`.

Deferred source-link validation parses canonical text to JSONB, requires the exact
top-level and metadata key sets, compares every value with the linked persisted source
and invoice, and verifies that the text, hash, journal metadata, and link values agree.
The source link hash is SHA-256 of the canonical text bytes only.

The source link is append-only: all `UPDATE` and `DELETE` operations are rejected.
Existing custody accounts remain immutable under Phase 1 rules. Once a completed
internal-credit source is linked, a trigger rejects mutation of merchant, invoice,
asset, network, type, status, all amounts, destination, txid, idempotency key,
error, metadata, and occurred time.

### 5.5 Reversal and offset prohibitions

Until a separate correction phase is independently approved:

- `internal_credit_shadow_v1` must have `reversal_of_id IS NULL`.
- No journal entry may reverse a linked `internal_credit_shadow_v1` event.
- Only `internal_credit_shadow_v1` may post to an
  `internal_credit_shadow_offset` account.

### 5.6 Deferred trigger sources and commit behavior

The following constraint triggers are `DEFERRABLE INITIALLY DEFERRED` and validate
the final outer-transaction state:

- `custody_journal_entries`: `INSERT`, `UPDATE`;
- `custody_journal_postings`: `INSERT`, `UPDATE`, `DELETE`;
- `custody_journal_source_links`: `INSERT`, `UPDATE`, `DELETE`;
- `merchant_settlement_entries`: `INSERT`, `UPDATE`;
- `custody_phase2a_cutovers`: `INSERT`.

The journal/posting/link triggers enforce Phase 2A event shape, bidirectional link
coverage, source-derived identity, account correspondence, reversal prohibition, and
offset exclusivity. The source/link/cutover triggers enforce post-cutover source
coverage. The valid live sequence `source -> journal -> link` is allowed because all
checks run at commit, not after each intermediate insert.

Immutability triggers may reject direct mutation immediately; their role is separate
from final-state constraint validation.

## 6. Decimal contracts

All source asset values use the AssetRegistry settlement scale. Persisted
`merchant_settlement_entries` casts may expose `decimal:18`; validation must parse
that stored string and apply `RoundingMode::UNNECESSARY` at the registry scale.

For example, stored BTC `0.001000000000000000` canonicalizes successfully to
`0.00100000` at scale 8. Zero-only tails beyond registry scale are allowed. Any
non-zero digit beyond the registered scale is rejected. Replay and source validation
must never use `HALF_UP`.

`amount_atomic` is derived from the resulting canonical asset amount and must exactly
match that amount at the asset scale.

`amount_usd` is nullable. Its only permitted initial calculation is scale 2 using
`RoundingMode::HALF_UP`. Once persisted, replay and validation use scale 2 with
`RoundingMode::UNNECESSARY`: zero-only tails are acceptable and non-zero digits past
scale 2 are rejected.

## 7. Cutover protocol

### 7.1 Stable advisory transaction lock

Use one fixed PostgreSQL advisory key for this phase:

```text
731247380491024681::bigint
```

Every positive internal-credit business transaction acquires
`pg_advisory_xact_lock_shared(731247380491024681::bigint)` before reading the marker.
The activation transaction acquires
`pg_advisory_xact_lock(731247380491024681::bigint)` before reading the marker or
baseline. Both locks are held until outer commit or rollback.

### 7.2 Gate state matrix

| Marker | Gate state | Positive internal-credit behavior |
|---|---|---|
| absent | shadow off | Preserve existing legacy-only behavior |
| absent | shadow on | Fail closed before mutation |
| present | accounting, journal writes, and shadow on; routing/payout off | Execute Phase 2A shadow flow |
| present | shadow off, parent gate off, or routing/payout gate on | Fail closed before mutation |

Required valid post-cutover gates are:

```dotenv
CUSTODY_ACCOUNTING_ENABLED=true
CUSTODY_JOURNAL_WRITES_ENABLED=true
CUSTODY_PHASE2A_SHADOW_INTERNAL_CREDITS_ENABLED=true
CUSTODY_INVOICE_ROUTING_ENABLED=false
PAYOUT_REQUESTS_ENABLED=false
PAYOUT_AUTOMATIC_REQUESTS_ENABLED=false
PAYOUT_EXECUTION_ENABLED=false
```

The immutable marker prevents later configuration changes from restoring legacy-only
positive credits.

### 7.3 Activation baseline, canonical hashes, and order

Activation runs at `READ COMMITTED` so the baseline is read only after obtaining the
exclusive advisory lock. Before marker insertion, each of the following queries must
return exactly `COUNT(*) = 0`:

- `merchant_balances`;
- `merchant_settlement_entries`;
- `custody_accounts`;
- `custody_journal_entries`;
- `custody_journal_postings`;
- `custody_account_balances`;
- `custody_journal_source_links`;
- `custody_phase2a_cutovers`.

The baseline verifier must also return zero completed sources, zero covered sources,
zero uncovered sources, zero source snapshot mutations, zero unexplained residuals,
and zero projection drift.

`baseline_verification_canonical_text` is compact recursively sorted canonical JSON.
It hashes a schema-versioned zero/parity report and excludes generated values, runtime
timestamps, activation time, process IDs, and environment-specific paths. Its exact
payload is:

```json
{
  "baseline_schema_version": "custody_phase2a_zero_parity_baseline_v1",
  "counts": {
    "custody_account_balances": 0,
    "custody_accounts": 0,
    "custody_journal_entries": 0,
    "custody_journal_postings": 0,
    "custody_journal_source_links": 0,
    "custody_phase2a_cutovers": 0,
    "merchant_balances": 0,
    "merchant_settlement_entries": 0
  },
  "parity": {
    "completed_internal_credit_count": 0,
    "covered_internal_credit_count": 0,
    "projection_drift_count": 0,
    "source_snapshot_mutation_count": 0,
    "uncovered_internal_credit_count": 0,
    "unexplained_legacy_residual_count": 0
  }
}
```

`baseline_verification_hash` is SHA-256 of that exact canonical text.

`activation_config_canonical_text` is compact recursively sorted canonical JSON with
exactly these seven Boolean fields and no other field:

```json
{
  "custody_accounting_enabled": true,
  "custody_invoice_routing_enabled": false,
  "custody_journal_writes_enabled": true,
  "custody_phase2a_shadow_internal_credits_enabled": true,
  "payout_automatic_requests_enabled": false,
  "payout_execution_enabled": false,
  "payout_requests_enabled": false
}
```

`activation_config_fingerprint` is SHA-256 of that exact canonical text. The same
recursive sorted-key function used for source snapshot text validates both marker
payloads.

Activation inserts the immutable marker only if the whole baseline and gate contract
are clean. If the marker already exists, activation is an idempotent read-only return:
it must not update marker timestamps, references, canonical payloads, or hashes.

The required operator rollout order is:

1. complete the section 14 database-identity preflight;
2. stop workers and scheduler;
3. deploy the migration chain with all gates off;
4. on the verified eligible disposable local database, run `migrate:fresh --seed`;
5. verify the exact zero baseline above;
6. while workers are stopped, configure valid accounting/journal/shadow gates and
   keep routing/payout gates off;
7. atomically activate cutover by taking the exclusive advisory lock, rechecking the
   exact baseline, and inserting the marker in one transaction;
8. restart config-cached workers.

## 8. Live transaction pseudocode

The following is behavioral pseudocode, not implementation code.

1. Begin one outer database transaction.
2. Lock invoice, then lock merchant.
3. If the invoice is terminal, return without changing invoice, balance, source,
   journal, link, projection, or webhook.
4. Read the existing settlement decision and calculate remaining amount.
5. If remaining amount is exactly zero, use the existing no-credit terminal path:
   create no balance movement, source, account, journal, link, or projection.
6. Acquire the shared Phase 2A advisory transaction lock.
7. Read the cutover marker only after the shared lock is held, then evaluate gates.
8. If marker is absent and shadow is off, preserve the existing legacy flow. If marker
   is absent with shadow on, or marker is present with invalid gates, fail closed
   before every legacy or custody mutation.
9. For valid post-cutover shadow flow, lock or create merchant balance.
10. Look up the completed internal-credit source by the legacy invoice source key.
    - If it existed before this business replay, the retryable invoice is an integrity
      conflict. Do not call lower-level projector replay and do not mutate balance.
    - If absent, create it through `createOrAssertExact`, persist and re-read its
      non-null `occurred_at`, and mark its origin as `created_in_current_transaction`.
    - If an insertion race reveals an already-existing source, treat it as
      pre-existing and fail closed.
11. Resolve the offset and merchant-available account natural keys deterministically,
    materialize them if absent, then lock accounts by ascending numeric account ID.
12. Invoke the guarded journal writer. Its only permitted new-event sequence is:
    1. create and lock the unposted journal row after account locks;
    2. insert the two uncommitted postings;
    3. lock projections by ascending account ID;
    4. execute the read-only Phase 2A parity guard;
    5. apply projection deltas;
    6. set `posted_at`;
    7. return the posted journal entry to the outer transaction.
13. Create one immutable source link after `posted_at` is set.
14. Increase merchant balance exactly once by canonical net source amount.
15. Mark invoice done and persist the existing forwarded webhook outbox row.
16. Commit. Any error, including deferred PostgreSQL validation, rolls back source,
    unposted journal row, uncommitted postings, link, projections, balance, invoice
    state, and webhook together.

### 8.1 Guarded writer contract

The writer must use this lock order without exception:

```text
accounts ascending ID
-> create/lock unposted journal row
-> insert uncommitted postings
-> projections ascending account ID
-> read-only Phase 2A parity guard
-> apply projection delta
-> set posted_at
```

There is no separate projection lock before `CustodyJournalWriter`. The parity guard
receives only already-locked account/projection state plus read-only aggregate inputs.
It must not write, call external I/O, acquire additional locks, reorder locks, create
accounts, rebuild projections, or modify journal/source/link/balance/invoice/webhook
state.

Before projection delta, the guard requires all of the following:

- merchant balance equals aggregate valid linked source total for this merchant and
  asset/network;
- both equal the locked merchant-available projection;
- shared offset projection equals aggregate valid linked liability for all merchants
  on that asset/network.

The source created in this transaction is unlinked at this moment and is excluded from
the pre-post covered total. Guard failure throws before delta or `posted_at`; the
unposted journal row and postings roll back with the outer transaction and require
verify/rebuild or separate remediation.

No routing, payout, treasury, signer, RPC, HTTP, or other external I/O occurs in this
transaction.

## 9. `settlements:backfill-ledger` hardening

The existing legacy command is retained only as a guarded pre-cutover compatibility
operation; it is not a Phase 2A historical migration tool.

### Write mode

Write mode must use one outer database transaction across all selected chunks. Its
first actions are:

1. acquire `pg_advisory_xact_lock_shared(731247380491024681::bigint)`;
2. read the cutover marker only after that shared lock is held;
3. if marker exists, report `post_cutover_backfill_write_prohibited`, exit non-zero,
   and perform no scan or write.

If marker is absent, the command holds the shared lock through the whole run. Cutover
cannot obtain exclusive lock between chunks. A committed pre-cutover write run leaves
financial rows and/or uncovered completed sources, so activation subsequently rejects
the dirty baseline.

Before writing `invoice:<id>:backfill:internal-credit`, the command must query by
`invoice_id` for any completed internal-credit source. If one exists, it must skip or
report conflict; it must never use its different backfill key to create a second
source for a live-credited invoice.

### Dry-run mode

Dry-run remains strictly read-only. If the marker is present, it reports that
post-cutover writes are prohibited and performs zero writes. If marker is absent, it
retains the existing pre-cutover read-only preview behavior. It does not need the
shared advisory lock because it cannot mutate financial state.

## 10. Lower-level exact replay contract and lock order

Lower-level exact projector replay is separate from whole business replay. A terminal
invoice returns unchanged; a retryable invoice with a pre-existing source or link is
an integrity conflict, not a projector replay.

For an independently invoked exact projector replay, use this lock order:

1. lock persisted settlement source;
2. resolve source-link, journal, and account IDs with non-locking reads;
3. lock custody accounts in ascending numeric ID order;
4. invoke the exact replay path of `CustodyJournalWriter`, which resolves or locks the
   journal entry only after account locks;
5. lock projections in ascending account ID order only if validation requires them;
6. validate immutable source link last.

The lower-level path uses the same accounts -> journal -> projections ordering as the
live guarded writer. An exact replay of an already posted journal event does not need
a projection lock or guard execution because it cannot apply a delta; if projection
validation is requested, it happens only after journal resolution and never before
accounts.

Exact replay requires matching source snapshot hash, journal key, source reference,
effective time, metadata, asset scale, accounts, and postings. It returns the existing
posted journal entry without modifying projections, link, merchant balance, invoice,
or webhook. Any mismatch is an integrity conflict.

## 11. Read-only Phase 2A verifier contract

The verifier runs inside one `REPEATABLE READ, READ ONLY` transaction. It neither
creates accounts nor rebuilds projections.

For each merchant/asset/network scope, it reports:

- merchant balance versus total completed internal credits;
- valid covered, uncovered, source-mutated, and link/journal-corrupt credits;
- exact source/link/journal/two-posting correspondence;
- journal merchant-available total versus valid covered source total;
- offset projection versus aggregate valid linked liability by asset/network;
- projection drift from the existing custody projection verifier;
- unexplained legacy residual (`merchant balance - all completed internal credits`)
  separately from uncovered source-credit total.

It exits non-zero for source mutation, coverage gap, journal/link mismatch, amount or
account mismatch, offset/liability mismatch, residual, or projection drift. The same
zero/parity model is used by cutover activation.

## 12. Complete test matrix

### PostgreSQL migrations and integrity

- PostgreSQL-only migration rejection on a non-PostgreSQL driver.
- Migration capability test for `to_regprocedure('sha256(bytea)')`.
- Built-in SHA-256 expression matches a fixed canonical-text SHA-256 vector.
- Link schema checks, restrictive FKs, two uniqueness constraints, asset-scale checks,
  canonical-text JSON-object parse check, recursive canonical-text check, hash check,
  and JSONB inspection check.
- Source/link/account immutability triggers.
- Deferred journal/link/source bidirectional coverage from entry, posting, link,
  source, and cutover trigger paths.
- Post-cutover direct completed source without link rejected at commit.
- Source, journal, postings, and link built in one transaction accepted at commit.
- Completed internal-credit-per-invoice unique constraint rejects different live and
  backfill idempotency keys for one invoice.
- Reversal and offset exclusivity prohibitions.

### Concurrency and cutover

- With two database connections, an open pre-cutover legacy credit holds shared lock;
  activation waits and then rejects the dirty baseline after credit commit.
- With two connections, activation holds exclusive lock; a positive credit waits,
  then sees committed marker and either shadows with valid gates or fails closed.
- Two positive credits for different invoices of the same merchant can hold the shared
  phase lock together and then serialize on existing merchant locks.
- Cutover activation rejects every non-clean baseline.
- Fresh seeded baseline has `COUNT(*) = 0` for `merchant_balances`,
  `merchant_settlement_entries`, `custody_accounts`, `custody_journal_entries`,
  `custody_journal_postings`, `custody_account_balances`,
  `custody_journal_source_links`, and `custody_phase2a_cutovers`, and permits
  marker creation.
- Marker update/delete rejection, exact marker replay without rewriting any marker field,
  and inability of a config toggle to cancel cutover.
- Baseline hash uses only the specified schema-versioned zero/parity payload and
  excludes generated/runtime timestamps.
- Activation config fingerprint accepts exactly the specified seven Boolean gates and
  rejects a missing, extra, or non-Boolean field.

### Local destructive and real-node authorization

- Before every `migrate:fresh`, record and verify the complete section 14.1
  Laravel, PostgreSQL, server-address, and Compose-ownership preflight. Repeat it
  after a PostgreSQL-container restart or recreation, a Docker Compose project/context
  change, or a Laravel configuration or database-connection change. A continuous
  confirmed session does not require a meaningless repeat before each separate SQL
  command; any mismatch must abort without a reset, migration, gate change, or cutover.
- On a verified eligible local database only, exercise fresh migration, the zero
  baseline, valid-gate cutover activation, synthetic credits, both verifiers, and a
  repeat fresh reset; routing and all payout gates remain false.
- Run the real-node integration suite exactly once through `composer test:all`, which
  already includes `test:integration`, only after the section 14.3 per-node RPC
  network preflight confirms Anvil chain ID 31337 or the named Bitcoin/Litecoin/Dash
  testnet. Report a skipped or blocked outcome when sync, wallet readiness, or
  spendable funds are inadequate; do not change production logic to make the test pass.
- Integration evidence records each node network, sync status, and before/after
  balances, plus testnet transaction IDs when applicable.

### Decimal, snapshot, and replay

- Registry-scale `UNNECESSARY` validation accepts zero tails and rejects non-zero
  excess scale for `amount_coin` and `fee_coin`.
- Exact asset atomic amount correspondence.
- `amount_usd` null acceptance; initial scale-2 `HALF_UP`; replay scale-2
  `UNNECESSARY`; rejection of non-zero excess precision.
- Newly created source proceeds without self-conflict.
- Retryable invoice with pre-existing source/link conflicts without a balance increase.
- Terminal whole-business replay returns unchanged.
- Exact lower-level projector replay leaves projections and all business rows unchanged.
- Exact ordered source snapshot fields, recursively sorted object keys, preserved list
  order, required metadata keys, persisted UTC occurred_at, and nullable required-null
  fields are covered by canonical-text and source-link tests.
- Wrong stable key, source reference, effective time, metadata source identity, hash,
  or asset scale rejects at commit or replay validation.

### Atomicity, parity, and scope exclusion

- Source, account, journal, link, deferred-constraint, and exactness failures roll
  back balance, invoice, webhook, source, journal, link, and projections together.
- Zero remaining amount creates no source, account, journal, link, or balance movement.
- Guarded writer order is accounts -> unposted journal -> postings -> projections ->
  read-only parity guard -> projection delta -> posted_at, with no prior projection lock.
- Guard failure rolls back the unposted journal row and postings with the outer transaction.
- Merchant-available drift, offset drift, and balance/covered-total mismatch block new
  posting before business mutation.
- Post-cutover shadow-off or invalid parent/scope gates fail without writes.
- Pre-cutover shadow-off preserves existing legacy behavior.
- Post-cutover `settlements:backfill-ledger` write exits non-zero with zero writes;
  dry-run stays read-only and reports prohibition.
- Pre-cutover backfill holds shared lock for its full outer transaction and blocks
  activation between chunks.
- Routing, payout request/execution, signer, RPC, treasury, deposit, fee-revenue,
  outbound, and `migration_suspense` collaborators/accounts are never invoked by the
  Phase 2A credit flow. Authorized existing real-node integration scenarios are
  governed separately by section 14.

## 13. Implementation acceptance criteria and test commands

Implementation is accepted only if all of the following are true:

- all schema constraints and deferred triggers pass their PostgreSQL feature tests;
- the full live transaction is exactly-once and fully atomic;
- post-cutover completed internal credits cannot commit uncovered;
- cutover concurrency tests pass with two separate database connections;
- existing flows remain unchanged when pre-cutover shadow is off;
- no excluded routing, payout, treasury, fee, signer, RPC, API, or UI scope appears
  in the Phase 2A credit flow; authorized pre-existing real-node integration remains
  limited to section 14.
- verifier reports a clean zero baseline and clean post-cutover synthetic credit scope.

The implementation chat must add the Phase 2A tests under `tests/Feature/Custody/` and
run these exact commands through Sail:

```bash
./vendor/bin/sail test tests/Feature/Custody/Phase2AResetFirstTest.php tests/Feature/Custody/Phase2AIntegrityConstraintsTest.php tests/Feature/Custody/Phase2ACutoverConcurrencyTest.php tests/Feature/Custody/Phase2AVerifierTest.php
./vendor/bin/sail test tests/Feature/Database/CustodyAccountingMigrationTest.php tests/Feature/Console/BackfillSettlementLedgerTest.php
./vendor/bin/sail test tests/Unit/Services/MerchantSettlementLedgerTest.php tests/Unit/Services/InvoiceForwarderTest.php
./vendor/bin/sail test tests/Feature/Custody tests/Unit/Services/Custody
./vendor/bin/sail test
# Only after section 14 database and per-node preflights:
composer test:all
./vendor/bin/pint --dirty
set -o pipefail
{ git diff --name-only --diff-filter=ACMR -- '*.php'; git diff --cached --name-only --diff-filter=ACMR -- '*.php'; git ls-files --others --exclude-standard -- '*.php'; } | sort -u | while IFS= read -r file; do ./vendor/bin/sail php -l "$file" || exit 1; done
# Check whitespace in modified tracked, staged, and untracked files. git diff --check
# alone does not inspect untracked files.
git diff --check
git diff --cached --check
git ls-files --others --exclude-standard -z | while IFS= read -r -d '' file; do
  whitespace_output=$(git diff --no-index --check -- /dev/null "$file" 2>&1 || true)
  if [ -n "$whitespace_output" ]; then
    printf '%s\n' "$whitespace_output"
    exit 1
  fi
done
git status --short
```

## 14. Local destructive and real-node integration test authorization

This section is normative. It supersedes the former absolute prohibition on local
`migrate:fresh`, local Phase 2A cutover activation, temporary accounting/journal/shadow
gate enablement, and controlled real-node integration testing. It applies only after
independent review authorizes Phase 2A implementation and only to the local Docker
Compose environment for this repository. It does not authorize application changes outside the
approved implementation scope or a commit, push, or pull request.

### 14.1 Eligible database and destructive-operation preflight

The Phase 2A executor may independently stop local workers and scheduler, enter
Sail/PostgreSQL containers, inspect Laravel runtime configuration and PostgreSQL
connection identity, apply migrations, run `migrate:fresh --seed`, activate a local
Phase 2A cutover, temporarily enable accounting/journal/shadow gates, run synthetic
`internal_balance_only` flows, and repeat `migrate:fresh` when needed for testing.

These destructive actions are authorized only for a database that simultaneously
satisfies all of the following:

- `APP_ENV` is `local` or `testing`;
- `DB_CONNECTION=pgsql`;
- `DB_HOST=pgsql`;
- `DB_DATABASE` is `laravel` or `testing`;
- the PostgreSQL container belongs to the current repository Docker Compose project;
- the connection does not point to production, staging, or any external host.

Before the first destructive command of a session and before every `migrate:fresh`,
the executor must output and verify `APP_ENV`, `DB_CONNECTION`, `DB_HOST`,
`DB_PORT`, and `DB_DATABASE`, then output and verify `current_database()`,
`current_user`, `inet_server_addr()`, and `inet_server_port()` from the live
PostgreSQL connection. The Compose project identity must also be verified from the
PostgreSQL container labels or equivalent Compose metadata.

Repeat this identity preflight after PostgreSQL container restart or recreation, a
Docker Compose project/context change, or a Laravel configuration or database-connection
change. During one continuous confirmed session, it need not be repeated before every
separate SQL command. Any mismatch, missing value, unexpected server address, or
inability to prove Compose ownership requires an immediate abort before any cleanup,
migration, reset, gate change, or cutover action.

Changes to `.env` and all secrets remain prohibited from version control.

### 14.2 Phase 2A cutover testing on an eligible local database

After implementation is authorized and applied, the executor may, on a fresh eligible
local database:

- verify the exact zero baseline from section 7.3;
- set the valid accounting/journal/shadow gate configuration;
- activate the immutable cutover marker;
- execute one or more synthetic positive `internal_balance_only` credits;
- verify source, source link, journal entry, postings, projections, legacy balance,
  the Phase 2A parity verifier, and the projection verifier;
- reset the disposable local database again to prove reproducibility.

Custody invoice routing and every payout gate must remain `false` throughout this
testing.

### 14.3 Containers, nodes, and network preflight

The executor may start and stop `anvil`, `bitcoind`, `litecoind`, and `dashd`; perform
read-only RPC inspection; inspect chain identity, sync status, wallet status, and
spendable/unconfirmed/immature balances; run the existing integration suite with
`RUN_REAL_RPC_TESTS=true`; and use node funds only as allowed by section 14.4.

Before the first transaction on each node, RPC must confirm its network identity. The
only permitted identities are Anvil chain ID `31337`, Bitcoin testnet, Litecoin
testnet, and Dash testnet. If RPC reports mainnet, an unknown network, or an unexpected
chain ID, the executor must abort before signing or broadcasting any transaction.

### 14.4 Funds and transaction rules

For Anvil, all funds are disposable. Snapshots and reverts, balance setting, local
transfers, and deployments are permitted.

For Bitcoin, Litecoin, and Dash testnet, only transfers required by existing
integration tests are permitted. Before each test transaction, verify spendable
balance, node sync, and wallet readiness. Use only the amounts and destinations in
the existing test scenarios; do not sweep, consolidate, or make arbitrary manual
transfers; and do not send funds to addresses outside test infrastructure. Do not
delete node wallets, chain data, or Docker volumes. Insufficient funds must produce
skipped or blocked test evidence, never a production-logic change.

Mainnet transactions are categorically forbidden.

### 14.5 Required implementation report

The final implementation report must include:

- the complete verified database-identity preflight before every `migrate:fresh`
  and after every event requiring identity recheck under section 14.1;
- every destructive operation performed;
- fresh-migration and cutover-activation results;
- the state of all seven gates;
- for each node: detected network, sync status, and balances before and after testing;
- every testnet transaction ID, if a transaction occurred;
- exact commands and results for unit, feature, and integration tests;
- skipped real-node tests and their reasons;
- `git diff --check` and `git status --short` results;
- confirmation that no commit or push was performed.

### 14.6 Authorization boundary

This authorization is limited to the local Docker Compose environment for this
repository.
It does not authorize a production or staging database reset, a database outside the
eligible identity in section 14.1, mainnet activity, deletion of node volumes or
wallets, changes to routing or payout scope, a commit, a push, or a pull request before
independent review.

The Phase 2A credit flow itself must not call routing, payout, treasury, signer, RPC,
or external-money-movement collaborators. Existing real-node integration tests may
exercise their pre-existing scope only under sections 14.3 and 14.4; this does not
expand Phase 2A implementation scope.

## 15. Superseded decisions

The following are explicitly excluded to prevent an incorrect implementation:

- historical custody backfill in Phase 2A;
- use of `migration_suspense` for new live credits;
- separate history and live journal idempotency keys for one settlement source;
- reversible `internal_credit_shadow_v1` entries before a dedicated correction phase;
- legacy-only positive internal credits after the immutable cutover marker exists.

## 16. Blocking unresolved decisions

None. This specification is implementation-ready after the required independent
specification review grants explicit implementation authorization.
