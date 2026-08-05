# Safety Boundary — Tranche 1

## Scope

Tranche 1 adds server-side admin authorization, live session revocation, a strict
deployment parent gate, an append-only distributed forwarding kill switch, and
linearized pre-broadcast boundaries. It does not add an HTTP switch endpoint,
automatic backlog release, payout/withdrawal execution, mainnet signing, UI
changes, decimal changes, or custody accounting Phase 1/2A behavior.

## Admin authorization

Every functional `/api/admin/*` route has an explicit
`admin.capability:<capability>` middleware. `/api/auth/admin/me` and logout
remain authentication-only. Capabilities are declared in config; a grant is
valid only when the capability is declared and explicitly listed for the
current role. There is no wildcard. Unknown and empty roles have no functional
access, and an unknown capability is denied even to `super_admin`.

| Capability | super_admin | support | analyst |
|---|:---:|:---:|:---:|
| `dashboard.read` | yes | yes | yes |
| `merchants.read` | yes | yes | yes |
| `merchants.create` | yes | no | no |
| `merchants.status.change` | yes | yes | no |
| `merchants.status.enable` | yes | no | no |
| `destination_wallets.read` | yes | yes | yes |
| `destination_wallets.write` | yes | no | no |
| `merchant_users.read` | yes | yes | yes |
| `merchant_users.create` | yes | no | no |
| `merchant_users.roles.write` | yes | no | no |
| `merchant_users.status.change` | yes | yes | no |
| `merchant_users.status.enable` | yes | no | no |
| `invoices.read` | yes | yes | yes |
| `invoices.refresh` | yes | yes | no |
| `webhook_deliveries.read` | yes | yes | yes |
| `webhook_deliveries.retry` | yes | yes | no |
| `merchant_api_keys.read` | yes | yes | yes |
| `merchant_api_keys.revoke` | yes | yes | no |

A status PATCH always requires the corresponding `*.status.change`
capability. A requested `status=active` also requires `*.status.enable`,
including an idempotent active-to-active request. Consequently support may
disable a merchant or merchant user but cannot enable either.

## Live session checks

On every `auth.admin` request the guard provider reloads the admin record.
A missing or non-active admin receives 403 on that request; the guard is logged
out and the session/token is invalidated, so the next request receives 401.
Database role changes are therefore effective on the next request.

Merchant session middleware similarly reloads both the merchant user and its
tenant. Missing or non-active user or tenant fails closed with 403 before the
controller and revokes the session. Functional `/api/merchant/*` routes and
`/api/auth/merchant/me` use this contract. Logout remains available without
the active-session middleware so a blocked user can safely terminate the
session. API-key `/api/v1` semantics are unchanged by this tranche.

## Effective forwarding gate

Forwarding is effective only when the deployment config is native PHP Boolean
`true` and the latest DB switch event is enabled.

| `config('forwarding.enabled')` | Latest DB event | Effective result |
|---|---|---|
| Boolean `false` | any readable state | disabled |
| non-Boolean, including strings/numbers/null | any | misconfigured; fail closed |
| Boolean `true` | missing | disabled; status command fails |
| Boolean `true` | `enabled=false` | disabled |
| Boolean `true` | `enabled=true` | enabled |
| any | DB read failure | unavailable; fail closed |

`config/forwarding.php` reads `FORWARDING_ENABLED` without a PHP
`(bool)` cast. Runtime code never reads `env()`. Only a native Boolean
surviving Laravel config parsing is accepted. A normal disabled result is a
controlled no-op. Invalid config and unavailable state are visible failures,
and neither may reserve or cross a broadcast boundary.

## Append-only switch

`forwarding_switch_events` is deliberately forwarding-specific:

- monotonic bigint primary key;
- non-null Boolean `enabled`;
- non-empty `actor` and `reason`, enforced by DB checks;
- non-null `timestamptz created_at`;
- no `updated_at`;
- initial migration event: disabled, actor `system:migration`, reason
  `fail_closed_initialization`;
- PostgreSQL trigger rejects UPDATE and DELETE; a state change is a new event.

The stable lock key is `731247380491024682`. Operator changes take an
exclusive transaction advisory lock. Authoritative worker checks take a shared
transaction advisory lock. Locks end at commit/rollback, and no transaction or
advisory lock is held through RPC, signing, or broadcast. The key is independent
of the custody Phase 2A key.

## Operator commands

Inspect state:

```bash
php artisan forwarding:status
```

The command prints config validity/value, latest event identity/state/actor/
reason/time, effective state, and failure reason. A valid disabled state exits
successfully. Invalid config, DB failure, or a missing event exits non-zero.

Append a state transition:

```bash
php artisan forwarding:switch disable --actor=operator:alice --reason=incident_123
php artisan forwarding:switch enable --actor=operator:alice --reason=change_456
```

Actor and reason must be non-empty. Repeating the current state is successful
and creates no duplicate event. Disable is available regardless of parent
config. Enable is rejected unless the parent config is native Boolean `true`.
There is no admin HTTP endpoint or UI switch in Tranche 1.

## Settlement transaction and RPC boundaries

Dispatch-time inspection in `InvoiceStatusRefresher` is only an optimization.
The authoritative checks are below the queue.

For a new settlement plan, one transaction:

1. locks invoice;
2. locks merchant;
3. handles any existing broadcasting/broadcasted/confirmed/
   needs-reconciliation evidence first;
4. takes the shared switch advisory lock;
5. rereads the effective gate;
6. only when enabled evaluates policy and creates a reservation.

`reserve()`/`reserveLocked()` repeat the shared gate check immediately
before inserting a new attempt, so direct callers cannot bypass the boundary.

For UTXO, EVM native, and ERC-20, the final boundary is one short transaction:

1. resolve durable attempt identity;
2. lock invoice;
3. lock merchant;
4. lock attempt;
5. take the shared switch advisory lock;
6. reread the effective gate;
7. atomically either transition `reserved -> broadcasting` or persist a
   retry-safe pre-broadcast failure.

The lock order is always invoice → merchant → attempt → advisory switch lock.
The transaction commits before `sendtoaddress`, EVM signer, or broadcast.
EVM read-only preparation may occur first, but its identity/fingerprint,
nonce, chain ID, calldata, and block context are durable before the final
boundary. The development signer is treated as broadcast-capable because it
may call `eth_sendTransaction`.

ERC-20 gas funding is a separate money movement. `InvoiceForwarder` passes the
exact reserved settlement-attempt ID, UUID, and lease-owner token into the gas
top-up flow. RPC preparation is permitted, then a short transaction locks
invoice → merchant → that exact attempt, validates ownership and identity, takes
the shared switch lock, rereads the gate, and commits a durable
`EvmGasFunding(state=broadcasting)`. The attempt must still belong to the locked
invoice and merchant, match the invoice active pointer, be an unexpired
owner-matched ERC-20 reservation, and have the same asset/network as both the
invoice and server-derived funding attributes. Funding metadata records the
server-controlled settlement-attempt ID and UUID. Only after commit may the
signer run. Missing, replaced, non-reserved, expired, foreign, or mismatched
attempts fail closed without funding/signing and without changing settlement
evidence. When the gate is disabled or invalid, only the validated exact current
reservation is closed retry-safe; no new funding row is created and the signer
is not called.

If an exclusive disable starts after a worker committed `broadcasting`, the
worker already crossed the ambiguity boundary and is not revoked. If disable
wins the advisory-lock linearization, no new attempt/funding may first enter
`broadcasting` after its commit.

## State behavior while disabled

| Record/state at observation | Behavior |
|---|---|
| invoice with no active broadcast evidence | no policy hold, credit, reservation, RPC, signer, or broadcast |
| settlement `reserved` before boundary | `failed`, `retry_safe=true`, failure reason recorded, ownership cleared, invoice remains paid with retry-safe failed forwarding state and active pointer cleared |
| settlement `broadcasting` | never made retry-safe solely due to disable; evidence reconciliation continues |
| settlement `broadcasted` | confirmation/evidence inspection continues |
| settlement `confirmed` | accounting completion and webhook outbox completion continue |
| settlement `needs_reconciliation` | evidence inspection continues; never rebroadcast |
| settlement `completed` | idempotent completion remains available |
| new gas funding before boundary | no broadcasting funding row and no signer call |
| gas funding `broadcasting`/`broadcasted`/`needs_reconciliation` | reconciliation continues; no duplicate top-up |
| gas funding `confirmed` | may dispatch the existing continuation job, but the job cannot create a new settlement while the gate is off |

A disabled settlement boundary records
`forwarding_disabled_before_broadcast`; invalid config records
`forwarding_configuration_invalid_before_broadcast`. The gas-funding
boundary uses the corresponding `*_before_gas_funding` reasons. DB
unavailability fails closed; an already reserved attempt remains eligible for
the existing reservation reaper if immediate durable cleanup cannot be
committed.

Re-enable does not release backlog automatically. A new attempt is possible
only after a later explicit existing refresh/redispatch. No bulk scheduler or
hold-release workflow is introduced.

## Reconciliation invariants

The switch does not gate `SettlementAttemptReconciler`,
`ReconcileSettlementAttemptJob`, `settlements:reconcile-attempts`,
`EvmGasFundingReconciler`, `ReconcileEvmGasFundingJob`, or
`settlements:reconcile-gas-fundings`. Identity/confirmation inspection,
confirmed accounting, and existing webhook-outbox completion remain live.
Ambiguous attempts are never rebroadcast.

## Rollout

1. Stop queue workers and scheduler.
2. Set `FORWARDING_ENABLED=false`.
3. Clear/rebuild Laravel config cache.
4. Apply migrations; the initial DB switch remains false.
5. Deploy the Tranche 1 code.
6. Start workers/scheduler with the effective gate off.
7. Run `forwarding:status`.
8. Inspect reserved, broadcasting, broadcasted, and needs-reconciliation rows.
9. Allow reconciliation to continue.
10. Set strict `FORWARDING_ENABLED=true`, rebuild config cache, and perform a coordinated worker restart.
11. Verify the DB switch is still false.
12. As the final independent action, run `forwarding:switch enable` with an explicit actor and reason.

## Rollback

1. Disable the DB switch first.
2. Stop workers and scheduler.
3. Set `FORWARDING_ENABLED=false` and rebuild config cache.
4. Preserve append-only switch history in production.
5. Never start old workers that do not know the DB switch: an already queued
   old `ForwardInvoiceJob` does not recheck the config after dispatch.
6. Run existing reconciliation commands in a controlled manner if necessary.
7. Do not roll back RBAC while the admin portal is reachable unless an external
   deny or maintenance boundary is already active.

Migration `down()` exists only for disposable testing/local rollback.
Production rollback must not delete switch audit history.
