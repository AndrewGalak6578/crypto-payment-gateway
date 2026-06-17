# Hosted checkout metadata

Hosted invoice links may use `metadata.redirects` to control where the payer can return after checkout.

## Redirect format

Pass redirects inside the invoice `metadata` object:

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
