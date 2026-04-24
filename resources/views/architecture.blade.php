<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settlane Architecture</title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        :root {
            --bg: #f4f1ea;
            --bg-low: #ebe7de;
            --surface: rgba(255, 255, 255, 0.68);
            --surface-strong: #fcfbf8;
            --ink: #18212b;
            --muted: #68717e;
            --line: rgba(24, 33, 43, 0.1);
            --line-strong: rgba(24, 33, 43, 0.15);
            --accent: #335884;
            --accent-soft: rgba(51, 88, 132, 0.08);
            --night: #0f1720;
            --night-soft: #17212d;
            --night-line: rgba(255, 255, 255, 0.09);
            --shadow-soft: 0 14px 32px rgba(24, 33, 43, 0.05);
            --shadow-panel: 0 22px 56px rgba(24, 33, 43, 0.08);
            --shadow-dark: 0 28px 58px rgba(15, 23, 32, 0.18);
            --display: "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Georgia, serif;
            --body: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            --mono: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--ink);
            font-family: var(--body);
            background:
                radial-gradient(circle at 12% 0%, rgba(51, 88, 132, 0.08), transparent 28%),
                radial-gradient(circle at 88% 8%, rgba(24, 33, 43, 0.05), transparent 24%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg-low) 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        code {
            font-family: var(--mono);
            font-size: 0.92em;
            padding: 2px 6px;
            border-radius: 7px;
            background: rgba(24, 33, 43, 0.05);
        }

        .page {
            max-width: 1220px;
            margin: 0 auto;
            padding: 28px 20px 56px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding-bottom: 18px;
            border-bottom: 1px solid rgba(24, 33, 43, 0.06);
        }

        .brand {
            font-family: var(--display);
            font-size: 33px;
            font-weight: 700;
            letter-spacing: -0.05em;
        }

        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 22px;
        }

        .nav a {
            color: var(--muted);
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.01em;
            transition: color 140ms ease;
        }

        .nav a:hover {
            color: var(--ink);
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.06fr) minmax(320px, 0.94fr);
            gap: 36px;
            align-items: start;
            padding: 58px 0 24px;
        }

        .eyebrow,
        .section-label {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.68);
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            box-shadow: var(--shadow-soft);
        }

        .hero h1,
        .section-title,
        .cta-title {
            margin: 0;
            font-family: var(--display);
            letter-spacing: -0.055em;
        }

        .hero h1 {
            margin-top: 18px;
            font-size: clamp(3.6rem, 8vw, 6rem);
            line-height: 0.9;
        }

        .hero-subtitle {
            max-width: 42rem;
            margin: 18px 0 0;
            font-size: clamp(1.16rem, 2.1vw, 1.55rem);
            line-height: 1.48;
            color: var(--muted);
            letter-spacing: -0.015em;
        }

        .scope-card,
        .panel {
            border: 1px solid var(--line);
            border-radius: 28px;
            background: var(--surface);
            box-shadow: var(--shadow-panel);
        }

        .scope-card {
            padding: 24px;
        }

        .scope-kicker {
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .scope-card p {
            margin: 12px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .scope-list {
            margin: 18px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .scope-list li {
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.66);
            font-size: 14px;
            line-height: 1.45;
        }

        .section {
            padding-top: 72px;
        }

        .section-head {
            display: grid;
            gap: 14px;
            max-width: 48rem;
            margin-bottom: 28px;
        }

        .section-title {
            font-size: clamp(2.1rem, 4vw, 3.5rem);
            line-height: 0.95;
        }

        .section-copy {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.72;
        }

        .surfaces {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .surface {
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.56);
            box-shadow: var(--shadow-soft);
        }

        .surface strong {
            display: block;
            margin-bottom: 8px;
            font-size: 16px;
            letter-spacing: -0.02em;
        }

        .surface p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.58;
        }

        .runtime {
            padding: 30px;
        }

        .runtime-top {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .runtime-note {
            max-width: 34rem;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .flow-grid {
            display: grid;
            grid-template-columns: repeat(8, minmax(0, 1fr));
            gap: 12px;
        }

        .stage {
            position: relative;
            min-height: 172px;
            padding: 18px 16px 20px;
            border-radius: 22px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.62);
            box-shadow: var(--shadow-soft);
        }

        .stage::after {
            content: "";
            position: absolute;
            right: -10px;
            top: 28px;
            width: 8px;
            height: 8px;
            border-top: 2px solid rgba(24, 33, 43, 0.28);
            border-right: 2px solid rgba(24, 33, 43, 0.28);
            transform: rotate(45deg);
        }

        .stage:last-child::after {
            display: none;
        }

        .stage-index {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            margin-bottom: 18px;
            border-radius: 50%;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 12px;
            font-weight: 800;
        }

        .stage h3 {
            margin: 0 0 8px;
            font-size: 15px;
            letter-spacing: -0.02em;
        }

        .stage p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.56;
        }

        .family-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .family {
            padding: 22px;
            border: 1px solid var(--line);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.6);
            box-shadow: var(--shadow-soft);
        }

        .family-tag {
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .family h3 {
            margin: 12px 0 10px;
            font-size: 20px;
            letter-spacing: -0.03em;
        }

        .family p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.62;
        }

        .split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .outcomes,
        .reliability {
            padding: 28px;
        }

        .outcome-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 20px;
        }

        .outcome {
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.62);
            box-shadow: var(--shadow-soft);
        }

        .outcome strong {
            display: block;
            margin-bottom: 8px;
            font-size: 16px;
            letter-spacing: -0.02em;
        }

        .outcome p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.58;
        }

        .reliability {
            border-color: var(--night-line);
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.08), transparent 30%),
                linear-gradient(180deg, var(--night-soft) 0%, var(--night) 100%);
            color: #f5f7fb;
            box-shadow: var(--shadow-dark);
        }

        .reliability .section-label {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.1);
            color: rgba(245, 247, 251, 0.6);
            box-shadow: none;
        }

        .reliability .section-title {
            margin-top: 14px;
            color: #f5f7fb;
        }

        .reliability .section-copy {
            color: rgba(245, 247, 251, 0.68);
        }

        .reliability-list {
            margin-top: 22px;
            display: grid;
            gap: 12px;
        }

        .reliability-item {
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.04);
        }

        .reliability-item strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }

        .reliability-item span {
            display: block;
            color: rgba(245, 247, 251, 0.64);
            font-size: 13px;
            line-height: 1.5;
        }

        .cta {
            margin-top: 88px;
            padding: 34px;
            border-radius: 32px;
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.08), transparent 30%),
                linear-gradient(180deg, var(--night-soft) 0%, var(--night) 100%);
            color: #f5f7fb;
            box-shadow: var(--shadow-dark);
        }

        .cta-wrap {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 24px;
            flex-wrap: wrap;
        }

        .cta .section-label {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.1);
            color: rgba(245, 247, 251, 0.6);
            box-shadow: none;
        }

        .cta-title {
            margin-top: 14px;
            font-size: clamp(2.2rem, 4vw, 3.4rem);
            line-height: 0.95;
        }

        .cta p {
            margin: 14px 0 0;
            max-width: 36rem;
            color: rgba(245, 247, 251, 0.7);
            font-size: 15px;
            line-height: 1.7;
        }

        .cta-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.01em;
            transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease, background-color 140ms ease;
        }

        .button:hover {
            transform: translateY(-1px);
        }

        .button.primary {
            background: #f5f7fb;
            color: var(--night);
        }

        .button.secondary {
            background: rgba(255, 255, 255, 0.06);
            color: #f5f7fb;
            border-color: rgba(255, 255, 255, 0.14);
        }

        @media (max-width: 1120px) {
            .hero,
            .split {
                grid-template-columns: 1fr;
            }

            .surfaces,
            .family-grid {
                grid-template-columns: 1fr 1fr;
            }

            .flow-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .stage:nth-child(4)::after,
            .stage:nth-child(8)::after {
                display: none;
            }
        }

        @media (max-width: 760px) {
            .page {
                padding: 22px 16px 42px;
            }

            .header,
            .cta-wrap {
                align-items: start;
                flex-direction: column;
            }

            .hero {
                grid-template-columns: 1fr;
                gap: 24px;
                padding: 42px 0 14px;
            }

            .surfaces,
            .family-grid,
            .flow-grid,
            .outcome-grid {
                grid-template-columns: 1fr;
            }

            .stage::after {
                display: none;
            }

            .runtime,
            .outcomes,
            .reliability,
            .cta,
            .scope-card {
                padding: 24px;
            }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="header">
        <a class="brand" href="{{ url('/') }}">Settlane</a>
        <nav class="nav" aria-label="Primary">
            <a href="{{ url('/') }}">Landing</a>
            <a href="{{ url('/merchant') }}">Merchant</a>
            <a href="{{ url('/admin') }}">Admin</a>
        </nav>
    </header>

    <section class="hero">
        <div class="hero-copy">
            <span class="eyebrow">Runtime architecture</span>
            <h1>Settlane Architecture</h1>
            <p class="hero-subtitle">
                Multi-asset invoice and settlement flow across merchant API, hosted invoice, chain monitoring,
                settlement, webhooks, and operational portals.
            </p>
        </div>

        <aside class="scope-card">
            <div class="scope-kicker">Current MVP scope</div>
            <p>
                Current `main` includes UTXO, local native EVM, local ERC-20 flow, settlement plumbing, gas
                sponsorship, and merchant/admin operations under one invoice lifecycle.
            </p>
            <ul class="scope-list">
                <li><strong>Assets:</strong> <code>btc</code>, <code>ltc</code>, <code>dash</code>, <code>eth_local</code>, <code>eth_usdt_local</code></li>
                <li><strong>Networks:</strong> <code>bitcoin</code>, <code>litecoin</code>, <code>dash</code>, <code>evm_local</code></li>
                <li><strong>Settlement:</strong> wallet forwarding or internal merchant balance fallback</li>
                <li><strong>Delivery:</strong> signed webhooks with persisted attempts and retry behavior</li>
            </ul>
        </aside>
    </section>

    <section class="section">
        <div class="section-head">
            <span class="section-label">System surfaces</span>
            <h2 class="section-title">Four entry points, one runtime model.</h2>
            <p class="section-copy">
                Different interfaces enter the system differently, but they all converge on the same invoice,
                settlement, and visibility pipeline.
            </p>
        </div>

        <div class="surfaces">
            <article class="surface">
                <strong>Merchant API</strong>
                <p>Programmatic invoice creation and status access for backend merchant integrations.</p>
            </article>
            <article class="surface">
                <strong>Merchant Portal</strong>
                <p>Operational interface for invoices, wallets, balances, webhook settings, and delivery history.</p>
            </article>
            <article class="surface">
                <strong>Admin Portal</strong>
                <p>Platform control surface for merchants, invoice oversight, wallets, and webhook operations.</p>
            </article>
            <article class="surface">
                <strong>Hosted Invoice</strong>
                <p>Customer-facing payment screen that sits on top of the same invoice record and lifecycle.</p>
            </article>
        </div>
    </section>

    <section class="section">
        <div class="section-head">
            <span class="section-label">Main runtime flow</span>
            <h2 class="section-title">The invoice path from request to operational visibility.</h2>
            <p class="section-copy">
                This is the core walkthrough: merchant input enters the invoice pipeline, chain state moves the
                lifecycle forward, settlement resolves the payout path, and both merchant and admin sides see the result.
            </p>
        </div>

        <div class="panel runtime">
            <div class="runtime-top">
                <div class="section-copy" style="max-width: 40rem;">
                    Merchant API requests and hosted invoice activity converge into the same invoice state machine.
                    Settlement and webhook delivery then branch from that shared runtime flow.
                </div>
                <div class="runtime-note">One lifecycle, multiple surfaces, family-specific handling underneath.</div>
            </div>

            <div class="flow-grid">
                <article class="stage">
                    <span class="stage-index">01</span>
                    <h3>Merchant/API input</h3>
                    <p>Invoice creation begins through merchant backend or portal workflow.</p>
                </article>
                <article class="stage">
                    <span class="stage-index">02</span>
                    <h3>Invoice creation</h3>
                    <p>Amount, rate snapshot, public invoice identity, and expiry are stored.</p>
                </article>
                <article class="stage">
                    <span class="stage-index">03</span>
                    <h3>Address allocation</h3>
                    <p>UTXO or EVM address strategy assigns the payment destination for the invoice.</p>
                </article>
                <article class="stage">
                    <span class="stage-index">04</span>
                    <h3>Chain monitoring</h3>
                    <p>Queue jobs poll for on-chain evidence and keep the invoice under observation.</p>
                </article>
                <article class="stage">
                    <span class="stage-index">05</span>
                    <h3>State transitions</h3>
                    <p><code>pending</code>, <code>fixated</code>, <code>paid</code>, and <code>expired</code> are applied from chain data.</p>
                </article>
                <article class="stage">
                    <span class="stage-index">06</span>
                    <h3>Settlement</h3>
                    <p>Merchant net amount resolves toward wallet forwarding or internal balance booking.</p>
                </article>
                <article class="stage">
                    <span class="stage-index">07</span>
                    <h3>Webhook delivery</h3>
                    <p>Lifecycle events are enqueued, signed, stored, and retried through delivery jobs.</p>
                </article>
                <article class="stage">
                    <span class="stage-index">08</span>
                    <h3>Merchant/Admin visibility</h3>
                    <p>Operational state becomes visible across merchant and admin portal surfaces.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="section-head">
            <span class="section-label">Chain-family layer</span>
            <h2 class="section-title">Shared invoice lifecycle, family-specific execution.</h2>
            <p class="section-copy">
                The common lifecycle stays the same across all assets. Address allocation, monitoring detail, and
                settlement behavior vary by chain family, with ERC-20 adding gas sponsorship for payout preconditions.
            </p>
        </div>

        <div class="family-grid">
            <article class="family">
                <div class="family-tag">UTXO</div>
                <h3>BTC / LTC / DASH</h3>
                <p>
                    UTXO assets follow the shared invoice lifecycle while using UTXO-specific address issuance,
                    transaction polling, confirmed totals, and on-chain forwarding semantics.
                </p>
            </article>
            <article class="family">
                <div class="family-tag">Native EVM</div>
                <h3><code>eth_local</code> on <code>evm_local</code></h3>
                <p>
                    Native EVM flow keeps the same invoice and settlement model, but switches to EVM address allocation,
                    EVM monitoring, and native transfer payout handling.
                </p>
            </article>
            <article class="family">
                <div class="family-tag">ERC-20</div>
                <h3><code>eth_usdt_local</code> on <code>evm_local</code></h3>
                <p>
                    ERC-20 uses the same core invoice lifecycle with token-specific detection and gas sponsorship before
                    token payout when native gas balance is below the required transfer precondition.
                </p>
            </article>
        </div>
    </section>

    <section class="section split">
        <div class="panel outcomes">
            <div class="section-head" style="margin-bottom: 0; max-width: none;">
                <span class="section-label">Settlement outcomes</span>
                <h2 class="section-title">Two operational payout paths.</h2>
                <p class="section-copy">
                    Once an invoice is paid, settlement resolves toward either external payout or an internal balance
                    booking depending on wallet availability and routing context.
                </p>
            </div>

            <div class="outcome-grid">
                <article class="outcome">
                    <strong>Forward to merchant wallet</strong>
                    <p>When a destination wallet exists, merchant net amount is forwarded on-chain through the family-specific payout path.</p>
                </article>
                <article class="outcome">
                    <strong>Internal merchant balance fallback</strong>
                    <p>When no destination wallet exists, payout is booked into internal merchant balance so settlement still completes operationally.</p>
                </article>
            </div>
        </div>

        <div class="panel reliability">
            <span class="section-label">Async reliability layer</span>
            <h2 class="section-title">Queue-backed delivery and visibility.</h2>
            <p class="section-copy">
                Monitoring, forwarding, and outbound notification are treated as asynchronous operational work rather
                than inline request behavior.
            </p>

            <div class="reliability-list">
                <div class="reliability-item">
                    <strong>Queue jobs</strong>
                    <span>Monitoring, settlement, and delivery are dispatched and processed independently.</span>
                </div>
                <div class="reliability-item">
                    <strong>Retries</strong>
                    <span>Failed webhook deliveries re-enter the delivery path through retry-aware job flow.</span>
                </div>
                <div class="reliability-item">
                    <strong>Webhook deliveries</strong>
                    <span>Lifecycle events are stored as explicit delivery records rather than fire-and-forget calls.</span>
                </div>
                <div class="reliability-item">
                    <strong>Persisted attempts</strong>
                    <span>Delivery attempts and outcomes remain inspectable, which supports debugging and auditability.</span>
                </div>
                <div class="reliability-item">
                    <strong>Admin visibility</strong>
                    <span>Admin portal keeps operational line of sight into invoices, deliveries, and retry behavior.</span>
                </div>
            </div>
        </div>
    </section>

    <section class="cta">
        <div class="cta-wrap">
            <div>
                <span class="section-label">Final demo navigation</span>
                <h2 class="cta-title">Continue the Settlane walkthrough.</h2>
                <p>
                    Return to the overview or move directly into merchant and admin surfaces to show the architecture in
                    operation rather than only in description.
                </p>
            </div>

            <div class="cta-actions">
                <a class="button primary" href="{{ url('/') }}">Back to landing</a>
                <a class="button secondary" href="{{ url('/merchant') }}">Open merchant portal</a>
                <a class="button secondary" href="{{ url('/admin') }}">Open admin portal</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>
