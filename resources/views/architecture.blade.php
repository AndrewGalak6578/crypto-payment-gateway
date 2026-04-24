<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MVP Architecture</title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        :root {
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-alt: #f8f9fd;
            --ink: #172033;
            --muted: #4a5772;
            --line: #dbe2ef;
            --accent: #0f6bff;
            --accent-soft: #e9f1ff;
            --ok: #0f8b5f;
            --ok-soft: #e8f7f1;
            --warn: #8a5a00;
            --warn-soft: #fff4e3;
            --vio: #5a43c4;
            --vio-soft: #efecff;
            --shadow: 0 14px 34px rgba(23, 32, 51, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background:
                radial-gradient(circle at 8% 0%, rgba(15, 107, 255, 0.14), transparent 35%),
                radial-gradient(circle at 90% 0%, rgba(90, 67, 196, 0.10), transparent 28%),
                var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .page {
            max-width: 1220px;
            margin: 0 auto;
            padding: 28px 18px 44px;
            display: grid;
            gap: 16px;
        }

        .panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: var(--shadow);
        }

        .hero {
            padding: 24px;
            display: grid;
            gap: 14px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            color: var(--accent);
            background: var(--accent-soft);
        }

        h1 {
            margin: 0;
            font-size: clamp(26px, 4vw, 42px);
            line-height: 1.1;
            letter-spacing: -0.02em;
        }

        .lead {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
            max-width: 78ch;
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .chip {
            border-radius: 999px;
            border: 1px solid var(--line);
            padding: 7px 11px;
            font-size: 12px;
            font-weight: 600;
            background: var(--surface-alt);
            color: var(--ink);
        }

        .chip.accent { background: var(--accent-soft); color: var(--accent); border-color: #cfe0ff; }
        .chip.ok { background: var(--ok-soft); color: var(--ok); border-color: #bee8d8; }
        .chip.warn { background: var(--warn-soft); color: var(--warn); border-color: #f0d6a7; }
        .chip.vio { background: var(--vio-soft); color: var(--vio); border-color: #d8d0ff; }

        .section {
            padding: 20px;
            display: grid;
            gap: 14px;
        }

        .section h2 {
            margin: 0;
            font-size: 21px;
        }

        .section p {
            margin: 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .surface-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .surface-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--surface-alt);
            padding: 12px;
            display: grid;
            gap: 6px;
        }

        .surface-card h3 {
            margin: 0;
            font-size: 15px;
        }

        .surface-card p {
            margin: 0;
            font-size: 13px;
            line-height: 1.45;
            color: var(--muted);
        }

        .flow {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(6, minmax(170px, 1fr));
            align-items: stretch;
        }

        .step {
            position: relative;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: var(--surface-alt);
            padding: 12px;
            min-height: 140px;
            display: grid;
            gap: 8px;
            align-content: start;
        }

        .step strong {
            font-size: 13px;
            letter-spacing: 0.01em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .step h3 {
            margin: 0;
            font-size: 15px;
            line-height: 1.3;
        }

        .step p {
            margin: 0;
            font-size: 13px;
            line-height: 1.45;
            color: var(--muted);
        }

        .step code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 12px;
            background: #eef2fb;
            padding: 1px 5px;
            border-radius: 6px;
        }

        .step:after {
            content: "";
            position: absolute;
            right: -12px;
            top: 50%;
            width: 10px;
            height: 10px;
            border-top: 2px solid #8ea2c7;
            border-right: 2px solid #8ea2c7;
            transform: translateY(-50%) rotate(45deg);
            background: transparent;
        }

        .step:last-child:after {
            display: none;
        }

        .branch-wrap {
            display: grid;
            gap: 10px;
            grid-template-columns: 1fr 1fr;
        }

        .branch {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            background: var(--surface-alt);
            display: grid;
            gap: 6px;
        }

        .branch h3 {
            margin: 0;
            font-size: 15px;
        }

        .branch p {
            margin: 0;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.45;
        }

        .families {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .family {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            background: var(--surface-alt);
            display: grid;
            gap: 6px;
        }

        .family h3 {
            margin: 0;
            font-size: 15px;
        }

        .family p {
            margin: 0;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.45;
        }

        @media (max-width: 1180px) {
            .surface-grid,
            .families {
                grid-template-columns: 1fr 1fr;
            }

            .flow {
                grid-template-columns: 1fr 1fr 1fr;
            }

            .step:after {
                display: none;
            }
        }

        @media (max-width: 760px) {
            .surface-grid,
            .families,
            .flow,
            .branch-wrap {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<main class="page">
    <section class="panel hero">
        <span class="eyebrow">Architecture / current main</span>
        <h1>Crypto Payment Gateway MVP Flow</h1>
        <p class="lead">
            One backend serves merchant API, merchant/admin portals, and hosted customer invoices. The core is a
            queue-driven invoice state machine that moves funds to destination wallets (or internal balances) and
            notifies merchants through signed webhook deliveries.
        </p>
        <div class="chips">
            <span class="chip accent">Laravel 12 + Vue 3</span>
            <span class="chip ok">Queue-driven monitoring</span>
            <span class="chip vio">Multi-asset via asset_key/network_key</span>
            <span class="chip warn">UTXO + native EVM + ERC-20</span>
        </div>
    </section>

    <section class="panel section">
        <h2>Interaction surfaces</h2>
        <div class="surface-grid">
            <article class="surface-card">
                <h3>Merchant API</h3>
                <p><code>/api/v1/invoices</code> create/read/refresh with API key auth for merchant backend systems.</p>
            </article>
            <article class="surface-card">
                <h3>Merchant Portal</h3>
                <p>Invoices, wallets, balances, API keys, webhook settings and delivery inspection for merchant operators.</p>
            </article>
            <article class="surface-card">
                <h3>Admin Portal</h3>
                <p>Platform operations: merchant governance, wallet controls, invoice oversight, webhook retry operations.</p>
            </article>
            <article class="surface-card">
                <h3>Hosted Invoice</h3>
                <p>Customer-facing payment page at <code>/i/{publicId}</code> plus status polling endpoint.</p>
            </article>
        </div>
    </section>

    <section class="panel section">
        <h2>Core lifecycle map</h2>
        <p>
            Invoice path requested by merchant: <code>create</code> -> <code>pending</code> -> <code>fixated</code> ->
            <code>paid</code> -> settlement branch -> webhook delivery.
        </p>
        <div class="flow">
            <article class="step">
                <strong>Step 1</strong>
                <h3>Invoice creation</h3>
                <p><code>InvoiceController</code> + <code>InvoiceCreator</code> snapshot rate, set TTL, issue public invoice ID.</p>
            </article>
            <article class="step">
                <strong>Step 2</strong>
                <h3>Address allocation</h3>
                <p><code>PaymentAddressAllocatorManager</code> selects UTXO or EVM allocator and binds address to invoice.</p>
            </article>
            <article class="step">
                <strong>Step 3</strong>
                <h3>Monitoring loop</h3>
                <p><code>MonitorInvoiceJob</code> re-queues and calls <code>InvoiceStatusRefresher</code> until terminal state.</p>
            </article>
            <article class="step">
                <strong>Step 4</strong>
                <h3>State machine</h3>
                <p>Transition logic updates <code>pending/fixated/paid/expired</code> using confirmed chain evidence.</p>
            </article>
            <article class="step">
                <strong>Step 5</strong>
                <h3>Settlement decision</h3>
                <p><code>InvoiceForwarder</code> computes merchant net target and chooses wallet forwarding vs fallback credit.</p>
            </article>
            <article class="step">
                <strong>Step 6</strong>
                <h3>Webhook dispatch</h3>
                <p><code>EnqueueInvoiceWebhook</code> + <code>DeliverWebhookJob</code> perform signed delivery with retries.</p>
            </article>
        </div>

        <div class="branch-wrap">
            <article class="branch">
                <h3>Forwarding path</h3>
                <p>
                    If destination wallet exists: on-chain forwarding is executed.
                    UTXO uses coin RPC transfer, EVM supports native transfer and ERC-20 token payout.
                </p>
                <p>
                    ERC-20 branch includes native gas sponsorship checks/top-up before token transfer when needed.
                </p>
            </article>
            <article class="branch">
                <h3>Internal balance fallback</h3>
                <p>
                    If no destination wallet exists, <code>MerchantBalanceCreditor</code> books merchant payout into
                    internal balances so invoice settlement remains complete and auditable.
                </p>
            </article>
        </div>
    </section>

    <section class="panel section">
        <h2>Chain family distinctions (high level)</h2>
        <div class="families">
            <article class="family">
                <h3>UTXO</h3>
                <p>Networks: <code>bitcoin</code>, <code>litecoin</code>, <code>dash</code>.</p>
                <p>Detection via address transaction polling and confirmed totals from chain RPC.</p>
            </article>
            <article class="family">
                <h3>Native EVM</h3>
                <p>Network: <code>evm_local</code>, asset: <code>eth_local</code>.</p>
                <p>Detection by block/transaction scanning and settlement through signed native transfers.</p>
            </article>
            <article class="family">
                <h3>ERC-20 on EVM</h3>
                <p>Network: <code>evm_local</code>, asset: <code>eth_usdt_local</code>.</p>
                <p>Detection via ERC-20 transfer logs with gas sponsorship support before payout when required.</p>
            </article>
        </div>
    </section>
</main>
</body>
</html>
