# Hosted checkout metadata

Hosted checkout links may use invoice metadata and merchant checkout settings to control payer return URLs, branding, asset selection, and post-payment behavior.

## Merchant-level checkout settings

Merchant Portal v2 exposes checkout defaults under `/merchant/settings`.

Current configurable fields:

- `checkout_display_name`: checkout header/merchant display name.
- `checkout_support_email`: support email shown when enabled.
- `checkout_brand_color`: checkout accent color.
- `checkout_expires_minutes`: default invoice expiration for portal-created payments.
- `checkout_allowed_assets`: list of asset keys the payer may choose from.
- `checkout_success_url`: default success redirect.
- `checkout_cancel_url`: default cancel/close redirect.
- `checkout_auto_redirect`: whether paid checkout should redirect automatically.
- `checkout_redirect_delay_seconds`: auto redirect delay.
- `checkout_show_invoice_id`: whether the hosted checkout should expose invoice ID.
- `checkout_show_support_email`: whether support email should be shown.
- `checkout_partial_payment_policy`: `allow_top_up`, `support_required`, or `expire_on_partial`.
- `checkout_confirmation_display`: `simple` or `show_confirmations`.
- `checkout_min_amount_usd` / `checkout_max_amount_usd`: portal-created invoice amount bounds.

Settings are stored on the `merchants` table and are applied by Merchant Portal invoice creation and hosted checkout view models.

## Asset selection

If an invoice is created without a fixed asset, the hosted checkout starts in an asset-selection state.

The payer can choose only assets allowed by merchant settings and platform asset policy:

```json
{
  "checkout_allowed_assets": ["btc", "ltc", "dash"]
}
```

If the allowed asset list is empty, all supported checkout assets that remain enabled by platform and merchant policy may be shown.

After the payer selects an asset, the backend creates or resolves the payment instructions, and the checkout switches to the payment state with QR/address/amount.

## Redirect format

Per-invoice redirects can be passed inside the invoice `metadata` object:

```json
{
  "amount": "10.00",
  "currency": "USD",
  "metadata": {
    "redirects": {
      "success_url": "https://merchant.example/orders/INV-20481/success",
      "return_url": "https://merchant.example/orders/INV-20481",
      "cancel_url": "https://merchant.example/orders/INV-20481/cancel"
    }
  }
}
```

Supported keys:

- `success_url`: used after the invoice is paid. The hosted checkout shows `Return to merchant` and starts the automatic success redirect countdown.
- `return_url`: fallback return link when `success_url` is not present. Also used by the close button if `cancel_url` is not present.
- `cancel_url`: used by the close button before payment completion.
- `complete_url`: legacy alias for `success_url`.

Merchant-level `checkout_success_url` and `checkout_cancel_url` are used as defaults when invoice metadata does not provide explicit redirect URLs.

## Safety rules

Redirect URLs are accepted only when they are safe:

- URL must use `https`.
- URL must include a host.
- URL must not include username or password credentials.
- URL length must be 2048 characters or less.
- Use merchant-controlled domains only.

Unsafe values are ignored by the hosted checkout. Do not pass `http`, `javascript:`, `file:`, localhost, or credentialed URLs.

## Expired invoices

When an invoice expires, the hosted checkout removes payment actions from the active state:

- no asset selection
- no QR code
- no wallet address
- no copy buttons
- no open-wallet action

If a partial payment was received before expiration, the page shows the received amount and invoice ID so the payer can contact the merchant.

## Partial payments

When a payer sends less than the required amount, the checkout moves to a partial/underpaid state.

Expected behavior:

- the same address remains visible while the invoice is still payable
- the amount field changes from total amount to remaining amount
- copy amount copies the remaining amount
- the UI clearly tells the payer to top up the same address
- if the invoice expires, active payment actions are removed

Merchant setting `checkout_partial_payment_policy` controls how the checkout should message or handle partial payments.

## Paid invoices

When payment is complete:

- polling stops
- QR code, address, copy amount, and open wallet actions are hidden
- `Return to merchant` is shown when a safe return URL exists
- automatic redirect starts only when `checkout_auto_redirect` is enabled
