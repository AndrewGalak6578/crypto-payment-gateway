# Merchant Portal v2

Merchant Portal v2 is the current merchant-facing SPA mounted under `/merchant`.

It is built with Vue 3, Vue Router, Pinia, Axios, and plain/scoped CSS. The UI is intentionally product-shaped: dense operational pages on desktop and separate mobile layouts where the desktop layout would not translate well.

## Routes

| Route | Purpose |
|---|---|
| `/merchant/login` | Merchant user login |
| `/merchant/register` | Merchant registration |
| `/merchant/dashboard` | Metrics, recent payments, balances, setup health |
| `/merchant/payments` | Payment workspace with filters, table/cards, detail drawer, pagination |
| `/merchant/payments/new` | Create payment link |
| `/merchant/payments/:paymentId` | Full payment detail page |
| `/merchant/developers` | API keys, webhook settings, webhook deliveries, test signal, retry/inspect |
| `/merchant/settlements` | Balances, destination wallets, wallet estimate, settlement ledger |
| `/merchant/team` | Merchant users, roles, status changes, mobile team management |
| `/merchant/team/:userId` | Teammate dossier with activity timeline |
| `/merchant/settings` | Merchant profile and checkout settings |

## Main API surface

Implemented in `resources/js/merchant-v2/services/merchantApi.js`:

- `GET /api/merchant/dashboard`
- `GET|PUT /api/merchant/settings`
- `GET|POST /api/merchant/invoices`
- `GET /api/merchant/invoices/summary`
- `GET /api/merchant/invoices/{id}`
- `POST /api/merchant/invoices/{id}/refresh`
- `GET /api/merchant/balances`
- `GET /api/merchant/settlement-entries`
- `GET|POST /api/merchant/wallets`
- `PUT|DELETE /api/merchant/wallets/{id}`
- `GET|POST|DELETE /api/merchant/api-keys`
- `GET|PUT /api/merchant/webhook-settings`
- `GET /api/merchant/webhook-deliveries`
- `POST /api/merchant/webhook-deliveries/test`
- `POST /api/merchant/webhook-deliveries/{id}/retry`
- `GET|POST /api/merchant/merchant-users`
- `GET /api/merchant/merchant-users/{id}`
- `PATCH /api/merchant/merchant-users/{id}/role`
- `PATCH /api/merchant/merchant-users/{id}/status`
- `DELETE /api/merchant/merchant-users/{id}`

## Page behavior notes

### Payments

- Desktop keeps the payment list visible and opens selected payment details in a right-side drawer.
- Selected payment, filters, page, and page size are represented in the query string so back navigation preserves context.
- Mobile uses payment cards and dedicated detail routes instead of a cramped desktop drawer.
- Payment detail can return to either Payments or Settlements depending on `route.query.from`.

### Create payment

- Uses merchant checkout settings as defaults.
- Supports payer asset selection and allowed asset restrictions.
- Does not treat client-side crypto estimates as source of truth.

### Settlements

- Shows merchant balances, approximate wallet estimate, destination wallet management, and settlement ledger entries.
- Ledger entries link back to payment details.
- Older invoice data can be backfilled with `settlements:backfill-ledger`.

### Developers

- Manages API keys and webhook configuration.
- Supports sending a test webhook signal.
- Delivery rows can be inspected inline and retried.

### Team

- Role/status/user actions are capability-gated.
- Mobile Team is a separate layout, not a squeezed desktop table.
- Teammate dossier pages show personal stats, role access, profile facts, and activity timeline grouped by section/type.

### Settings

- Controls merchant profile display and hosted checkout behavior:
  - display name
  - support email
  - brand color
  - allowed assets
  - expiration
  - success/cancel URLs
  - auto redirect
  - invoice/support visibility
  - partial payment policy
  - confirmation display
  - min/max amount

## Activity logging

Merchant activity logs are stored in `merchant_activity_logs`.

Logged areas include:

- auth: registration, login, logout
- team: create/update/disable/delete merchant users
- settings: checkout settings update
- developers: API keys, webhook settings, test signal, retry
- settlements: destination wallet changes
- payments: invoice creation and refresh

Sensitive metadata keys are scrubbed by `MerchantActivityLogger` before persistence.

The current UI exposes activity through teammate dossier pages. A global audit console is intentionally left as a future module.
