<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $viewModel->invoice->public_id }}</title>
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @vite(['resources/css/hosted-invoice.css', 'resources/js/hosted-invoice/checkout.js'])
</head>
<body class="checkout-page">
    <main class="checkout-shell">
        <section class="checkout-card" data-checkout-card data-active-state="choose-method">
            <header class="checkout-header">
                <div class="merchant-identity">
                    <span class="merchant-icon" aria-hidden="true">✓</span>
                    <span data-merchant-display-name>{{ $viewModel->settings['display_name'] ?? 'Merchant checkout' }}</span>
                </div>

                <button class="icon-button" type="button" aria-label="Close checkout" data-close-checkout hidden>
                    ×
                </button>
            </header>

            <div class="sr-only" aria-live="polite" id="checkout-status-announcer"></div>

            <section class="checkout-state checkout-state--choose" data-checkout-state="choose-method" hidden>
                <div class="state-heading">
                    <h1 class="checkout-title">Pay <span data-fiat-amount>$0.00</span></h1>
                    <p class="checkout-subtitle">Choose payment method</p>
                </div>

                @include('hosted-invoices.partials.invoice-meta')

                <div class="payment-method-list">
                    @foreach($viewModel->assets as $asset)
                        @php
                            $assetClass = strtolower((string) ($asset['symbol'] ?? 'default'));
                            $assetClass = in_array($assetClass, ['btc', 'ltc', 'dash', 'usdt', 'eth'], true) ? $assetClass : 'default';
                        @endphp
                        <button class="payment-method-card" type="button" data-select-asset="{{ $asset['key'] }}">
                            <span class="asset-icon asset-icon--{{ $assetClass }}">{{ substr($asset['symbol'], 0, 1) }}</span>
                            <span class="method-text">
                                <span class="method-name">{{ $asset['name'] }}</span>
                                <span class="method-meta">{{ $asset['symbol'] }} • {{ $asset['network_label'] }}</span>
                            </span>
                            <span class="method-chevron" aria-hidden="true">›</span>
                        </button>
                    @endforeach
                </div>

                <p class="safety-note">For token payments, use the correct network.</p>
                <p class="checkout-small" data-support-email hidden></p>
                <p class="copy-feedback" data-asset-feedback role="status" aria-live="polite"></p>
            </section>

            <section class="checkout-state checkout-state--awaiting" data-checkout-state="awaiting-payment" hidden>
                <div class="state-heading">
                    <h1 class="checkout-title">Send exactly <span data-crypto-amount>0</span></h1>
                    <p class="checkout-subtitle">Send the exact amount to the address below.</p>
                </div>

                @include('hosted-invoices.partials.invoice-meta')

                <div class="qr-wrap">
                    <div class="qr-box" data-qr-box>Loading QR...</div>
                </div>

                @include('hosted-invoices.partials.payment-details', ['amountLabel' => 'Exact amount', 'copyKind' => 'amount'])

                <div class="action-row">
                    <button class="btn btn-secondary" type="button" data-copy-kind="address">Copy address</button>
                    <button class="btn btn-secondary" type="button" data-copy-kind="amount">Copy amount</button>
                    <a class="btn btn-primary" href="#" data-open-wallet hidden>Open wallet</a>
                </div>

                <p class="safety-note">Send only <span data-asset-symbol>this asset</span> to this address via <span data-network-label>this network</span>.</p>
                <p class="copy-feedback" data-copy-feedback role="status" aria-live="polite"></p>
            </section>

            <section class="checkout-state checkout-state--partial" data-checkout-state="partial" hidden>
                <div class="state-heading">
                    <h1 class="checkout-title">Payment is short</h1>
                    <p class="checkout-subtitle">Send the remaining amount to the same address.</p>
                </div>

                @include('hosted-invoices.partials.invoice-meta')

                <div class="payment-summary">
                    <div class="payment-summary__item">
                        <span class="payment-summary__label">Received</span>
                        <strong class="payment-summary__value" data-received-amount>0</strong>
                    </div>
                    <div class="payment-summary__item">
                        <span class="payment-summary__label">Remaining</span>
                        <strong class="payment-summary__value" data-remaining-amount>0</strong>
                    </div>
                </div>

                <div class="qr-wrap">
                    <div class="qr-box" data-qr-box>Loading QR...</div>
                </div>

                @include('hosted-invoices.partials.payment-details', ['amountLabel' => 'Amount needed', 'copyKind' => 'remaining'])

                <div class="action-row">
                    <button class="btn btn-secondary" type="button" data-copy-kind="address">Copy address</button>
                    <button class="btn btn-secondary" type="button" data-copy-kind="remaining">Copy amount</button>
                    <a class="btn btn-primary" href="#" data-open-wallet hidden>Open wallet</a>
                </div>

                <p class="safety-note">Send only <span data-asset-symbol>this asset</span> to this address via <span data-network-label>this network</span>.</p>
                <p class="copy-feedback" data-copy-feedback role="status" aria-live="polite"></p>
            </section>

            <section class="checkout-state checkout-state--confirming" data-checkout-state="confirming" hidden>
                <div class="state-heading">
                    <div class="status-icon" aria-hidden="true">…</div>
                    <h1 class="checkout-title">Payment detected</h1>
                    <p class="checkout-subtitle">Waiting for confirmation.</p>
                </div>

                <div class="received-panel">
                    <span class="received-panel__label">Received</span>
                    <strong class="received-panel__value" data-received-amount>0</strong>
                </div>

                <p class="safety-note safety-note--strong">Do not send more funds to this address.</p>

                <details class="details-panel">
                    <summary class="detail-row">Payment details</summary>
                    @include('hosted-invoices.partials.payment-details', ['amountLabel' => 'Expected amount', 'copyKind' => 'amount'])
                </details>
            </section>

            <section class="checkout-state checkout-state--complete" data-checkout-state="complete" hidden>
                <div class="success-icon" aria-hidden="true">✓</div>

                <h1 class="checkout-title">Payment complete</h1>
                <p class="checkout-subtitle">Thank you. Your payment has been received.</p>

                <div class="paid-panel">
                    <span class="paid-panel__label">Paid amount</span>
                    <strong class="paid-panel__amount" data-paid-amount>0</strong>
                </div>

                <div class="invoice-meta">
                    <span>Invoice ID <strong class="invoice-id" data-invoice-id></strong></span>
                </div>

                <a class="btn btn-primary btn-block" href="#" data-return-link hidden>Return to merchant</a>
                <p class="checkout-small" data-redirect-note hidden></p>
            </section>

            <section class="checkout-state checkout-state--expired" data-checkout-state="expired" hidden>
                <div class="expired-icon" aria-hidden="true">×</div>
                <h1 class="checkout-title">Invoice expired</h1>
                <p class="checkout-subtitle">This invoice can no longer be paid.</p>

                <div class="invoice-meta">
                    <span>Invoice ID <strong class="invoice-id" data-invoice-id></strong></span>
                </div>

                <a class="btn btn-primary btn-block" href="#" data-return-link hidden>Return to merchant</a>
            </section>

            <section class="checkout-state checkout-state--expired" data-checkout-state="expired-partial" hidden>
                <div class="expired-icon" aria-hidden="true">×</div>
                <h1 class="checkout-title">Invoice expired</h1>
                <p class="checkout-subtitle">This invoice can no longer be paid.</p>

                <div class="expired-notice" data-expired-partial-text></div>

                <div class="invoice-meta">
                    <span>Invoice ID <strong class="invoice-id" data-invoice-id></strong></span>
                </div>

                <a class="btn btn-primary btn-block" href="#" data-return-link hidden>Return to merchant</a>
            </section>
        </section>
    </main>

    <script>
        window.hostedInvoiceCheckout = {
            invoice: @json($viewModel->invoiceData),
            assets: @json($viewModel->assets),
            redirects: @json($viewModel->redirects),
            settings: @json($viewModel->settings),
            statusUrl: @json($viewModel->statusUrl),
            selectAssetUrl: @json($viewModel->selectAssetUrl),
            csrfToken: @json(csrf_token()),
        };
    </script>
</body>
</html>
