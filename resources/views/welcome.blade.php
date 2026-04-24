<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settlane</title>
    <meta name="description" content="Settlane - Multi-asset invoice and settlement gateway.">
    <meta name="robots" content="noindex,nofollow">
    <style>
        :root {
            --bg: #f4f1ea;
            --bg-low: #ece8df;
            --surface: rgba(255, 255, 255, 0.72);
            --surface-strong: #fcfbf8;
            --ink: #18212b;
            --muted: #68717e;
            --line: rgba(24, 33, 43, 0.1);
            --line-strong: rgba(24, 33, 43, 0.15);
            --accent: #335884;
            --accent-soft: rgba(51, 88, 132, 0.08);
            --night: #0f1720;
            --night-soft: #16212d;
            --night-line: rgba(255, 255, 255, 0.09);
            --shadow-soft: 0 14px 32px rgba(24, 33, 43, 0.05);
            --shadow-panel: 0 24px 60px rgba(24, 33, 43, 0.08);
            --shadow-dark: 0 26px 56px rgba(15, 23, 32, 0.18);
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
                radial-gradient(circle at 90% 10%, rgba(24, 33, 43, 0.05), transparent 24%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg-low) 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
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
            grid-template-columns: minmax(0, 1.08fr) minmax(320px, 0.92fr);
            gap: 44px;
            align-items: center;
            padding: 62px 0 46px;
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
            font-size: clamp(4.4rem, 10vw, 7.4rem);
            line-height: 0.88;
        }

        .subtitle {
            margin: 18px 0 0;
            font-size: clamp(1.3rem, 2.5vw, 1.95rem);
            line-height: 1.12;
            letter-spacing: -0.03em;
        }

        .support {
            max-width: 35rem;
            margin: 18px 0 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.72;
        }

        .hero-actions,
        .cta-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .hero-actions {
            margin-top: 28px;
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
            background: var(--night);
            color: #f4f7fb;
            box-shadow: 0 14px 32px rgba(15, 23, 32, 0.14);
        }

        .button.secondary {
            background: rgba(255, 255, 255, 0.68);
            color: var(--ink);
            border-color: var(--line);
        }

        .preview {
            border: 1px solid var(--night-line);
            border-radius: 30px;
            padding: 28px;
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.07), transparent 32%),
                linear-gradient(180deg, var(--night-soft) 0%, var(--night) 100%);
            color: #f5f7fb;
            box-shadow: var(--shadow-dark);
        }

        .preview-top {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 16px;
            margin-bottom: 22px;
        }

        .preview-tag {
            display: inline-flex;
            align-items: center;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            color: rgba(245, 247, 251, 0.72);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .preview-top p {
            margin: 10px 0 0;
            color: rgba(245, 247, 251, 0.66);
            font-size: 14px;
            line-height: 1.6;
            max-width: 29ch;
        }

        .preview-path {
            color: rgba(245, 247, 251, 0.54);
            font-family: var(--mono);
            font-size: 12px;
            white-space: nowrap;
        }

        .preview-flow {
            position: relative;
            display: grid;
            gap: 10px;
        }

        .preview-flow::before {
            content: "";
            position: absolute;
            left: 15px;
            top: 16px;
            bottom: 16px;
            width: 1px;
            background: rgba(255, 255, 255, 0.14);
        }

        .node {
            position: relative;
            display: grid;
            grid-template-columns: 30px 1fr;
            gap: 14px;
            align-items: start;
            padding: 12px 14px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.035);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .node-index {
            position: relative;
            z-index: 1;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: rgba(255, 255, 255, 0.09);
            color: rgba(245, 247, 251, 0.78);
            font-size: 11px;
            font-weight: 800;
        }

        .node strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .node span {
            display: block;
            color: rgba(245, 247, 251, 0.64);
            font-size: 13px;
            line-height: 1.45;
        }

        .proof-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0;
            margin-top: 8px;
            border: 1px solid var(--line);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.62);
            box-shadow: var(--shadow-soft);
            overflow: hidden;
        }

        .proof-item {
            min-height: 112px;
            padding: 20px 22px;
            border-right: 1px solid var(--line);
        }

        .proof-item:last-child {
            border-right: 0;
        }

        .proof-item strong {
            display: block;
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .proof-item p {
            margin: 0;
            font-size: 16px;
            line-height: 1.45;
            letter-spacing: -0.02em;
        }

        .section {
            padding-top: 88px;
        }

        .section-head {
            display: grid;
            gap: 14px;
            max-width: 44rem;
            margin-bottom: 30px;
        }

        .section-title {
            font-size: clamp(2.25rem, 4vw, 3.7rem);
            line-height: 0.95;
        }

        .section-copy {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
        }

        .step {
            padding: 18px 16px 20px;
            border-radius: 20px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.58);
            box-shadow: var(--shadow-soft);
        }

        .step-number {
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

        .step h3 {
            margin: 0 0 8px;
            font-size: 16px;
            letter-spacing: -0.02em;
        }

        .step p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.58;
        }

        .credibility {
            display: grid;
            grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
            gap: 28px;
            padding: 36px;
            border: 1px solid var(--line);
            border-radius: 30px;
            background: rgba(255, 255, 255, 0.68);
            box-shadow: var(--shadow-panel);
        }

        .credibility-copy .section-title {
            max-width: 11ch;
        }

        .credibility-list {
            display: grid;
            gap: 0;
        }

        .credibility-item {
            display: grid;
            grid-template-columns: minmax(130px, 150px) 1fr;
            gap: 18px;
            padding: 16px 0;
            border-bottom: 1px solid var(--line);
        }

        .credibility-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .credibility-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .credibility-item p {
            margin: 0;
            font-size: 15px;
            line-height: 1.62;
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
            font-size: clamp(2.2rem, 4vw, 3.5rem);
            line-height: 0.95;
        }

        .cta p {
            margin: 14px 0 0;
            max-width: 36rem;
            color: rgba(245, 247, 251, 0.7);
            font-size: 15px;
            line-height: 1.7;
        }

        .cta .button.primary {
            background: #f5f7fb;
            color: var(--night);
        }

        .cta .button.secondary {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.14);
            color: #f5f7fb;
        }

        @media (max-width: 1080px) {
            .hero,
            .credibility {
                grid-template-columns: 1fr;
            }

            .proof-strip {
                grid-template-columns: 1fr 1fr;
            }

            .proof-item:nth-child(2) {
                border-right: 0;
            }

            .proof-item:nth-child(-n+2) {
                border-bottom: 1px solid var(--line);
            }

            .steps {
                grid-template-columns: repeat(3, minmax(0, 1fr));
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
                gap: 28px;
                padding: 42px 0 34px;
            }

            .proof-strip,
            .steps {
                grid-template-columns: 1fr;
            }

            .proof-item,
            .proof-item:nth-child(-n+2) {
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }

            .proof-item:last-child {
                border-bottom: 0;
            }

            .credibility,
            .cta,
            .preview {
                padding: 24px;
            }

            .credibility-item {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .preview-top {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="header">
        <a class="brand" href="{{ url('/') }}">Settlane</a>
        <nav class="nav" aria-label="Primary">
            <a href="{{ route('architecture') }}">Architecture</a>
            <a href="{{ url('/merchant') }}">Merchant</a>
            <a href="{{ url('/admin') }}">Admin</a>
        </nav>
    </header>

    <section class="hero">
        <div class="hero-copy">
            <span class="eyebrow">Laravel + Vue fintech infrastructure MVP</span>
            <h1>Settlane</h1>
            <p class="subtitle">Multi-asset invoice and settlement gateway</p>
            <p class="support">
                Settlane brings invoice issuance, chain monitoring, settlement routing, and webhook delivery into one
                calm operational surface for merchant and admin teams.
            </p>
            <div class="hero-actions">
                <a class="button primary" href="{{ route('architecture') }}">View architecture</a>
                <a class="button secondary" href="{{ url('/merchant') }}">Open merchant portal</a>
                <a class="button secondary" href="{{ url('/admin') }}">Open admin portal</a>
            </div>
        </div>

        <aside class="preview">
            <div class="preview-top">
                <div>
                    <span class="preview-tag">Compact architecture preview</span>
                    <p>A concise runtime path for live explanation before opening the full architecture page.</p>
                </div>
                <span class="preview-path">api -> settlement</span>
            </div>

            <div class="preview-flow">
                <div class="node">
                    <div class="node-index">01</div>
                    <div>
                        <strong>Merchant API</strong>
                        <span>Invoice request enters the system.</span>
                    </div>
                </div>
                <div class="node">
                    <div class="node-index">02</div>
                    <div>
                        <strong>Invoice</strong>
                        <span>Address, amount, and lifecycle metadata are issued.</span>
                    </div>
                </div>
                <div class="node">
                    <div class="node-index">03</div>
                    <div>
                        <strong>Monitor</strong>
                        <span>Queue jobs poll chain state and apply transitions.</span>
                    </div>
                </div>
                <div class="node">
                    <div class="node-index">04</div>
                    <div>
                        <strong>Settlement</strong>
                        <span>Forward on-chain or fall back to internal merchant balance.</span>
                    </div>
                </div>
                <div class="node">
                    <div class="node-index">05</div>
                    <div>
                        <strong>Webhooks</strong>
                        <span>Signed events persist, deliver, and retry cleanly.</span>
                    </div>
                </div>
                <div class="node">
                    <div class="node-index">06</div>
                    <div>
                        <strong>Portals</strong>
                        <span>Merchant and admin surfaces expose the operational state.</span>
                    </div>
                </div>
            </div>
        </aside>
    </section>

    <section class="proof-strip" aria-label="Proof strip">
        <article class="proof-item">
            <strong>Coverage</strong>
            <p>UTXO + native EVM + ERC-20</p>
        </article>
        <article class="proof-item">
            <strong>Lifecycle</strong>
            <p>Queue-driven monitoring and settlement</p>
        </article>
        <article class="proof-item">
            <strong>Operations</strong>
            <p>Merchant + Admin operational views</p>
        </article>
        <article class="proof-item">
            <strong>Delivery</strong>
            <p>Webhook delivery with retries</p>
        </article>
    </section>

    <section class="section">
        <div class="section-head">
            <span class="section-label">Demo flow</span>
            <h2 class="section-title">A clean live walkthrough in six steps.</h2>
            <p class="section-copy">
                Start with merchant setup, move through invoice creation and payment status, then close on the two
                portal surfaces that make the system operationally legible.
            </p>
        </div>

        <div class="steps">
            <article class="step">
                <span class="step-number">01</span>
                <h3>Create merchant</h3>
                <p>Open the admin side and establish ownership, access, and platform control.</p>
            </article>
            <article class="step">
                <span class="step-number">02</span>
                <h3>Configure wallet</h3>
                <p>Set the settlement destination that drives forwarding rather than fallback booking.</p>
            </article>
            <article class="step">
                <span class="step-number">03</span>
                <h3>Create invoice</h3>
                <p>Issue a merchant invoice with rate snapshot, address allocation, and hosted payment flow.</p>
            </article>
            <article class="step">
                <span class="step-number">04</span>
                <h3>Pay invoice</h3>
                <p>Use the hosted invoice surface to explain the customer-facing leg of the system.</p>
            </article>
            <article class="step">
                <span class="step-number">05</span>
                <h3>Observe status</h3>
                <p>Walk through pending, fixated, paid, and settlement progression as jobs update the record.</p>
            </article>
            <article class="step">
                <span class="step-number">06</span>
                <h3>Inspect portals</h3>
                <p>Finish with merchant operations first, then admin oversight and support visibility.</p>
            </article>
        </div>
    </section>

    <section class="section">
        <div class="credibility">
            <div class="credibility-copy">
                <span class="section-label">Technical credibility</span>
                <h2 class="section-title">Product surface, infrastructure concerns.</h2>
                <p class="section-copy">
                    Settlane is presented as a clean product, but the technical value remains visible: lifecycle
                    control, operational visibility, and multi-asset routing under one system boundary.
                </p>
            </div>

            <div class="credibility-list">
                <div class="credibility-item">
                    <div class="credibility-label">Laravel</div>
                    <p>API endpoints, domain services, persistence, and the invoice state machine are kept in one coherent backend.</p>
                </div>
                <div class="credibility-item">
                    <div class="credibility-label">Vue</div>
                    <p>Merchant and admin portals stay separated by operational role instead of collapsing into one overloaded dashboard.</p>
                </div>
                <div class="credibility-item">
                    <div class="credibility-label">Docker Sail</div>
                    <p>Local environment setup supports a more credible demo story than static UI alone.</p>
                </div>
                <div class="credibility-item">
                    <div class="credibility-label">Queue jobs</div>
                    <p>Monitoring, settlement, and delivery work happen off the request path, which matches real operational workflows.</p>
                </div>
                <div class="credibility-item">
                    <div class="credibility-label">Webhooks + routing</div>
                    <p>Signed deliveries, retries, and multi-asset routing keep UTXO and EVM families under the same product surface.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="cta">
        <div class="cta-wrap">
            <div>
                <span class="section-label">Final CTA</span>
                <h2 class="cta-title">Ready to demo Settlane</h2>
                <p>
                    Start at the overview, open architecture for the system picture, then move into merchant and admin
                    portals for the operational walkthrough.
                </p>
            </div>

            <div class="cta-actions">
                <a class="button primary" href="{{ route('architecture') }}">Architecture</a>
                <a class="button secondary" href="{{ url('/merchant') }}">Merchant</a>
                <a class="button secondary" href="{{ url('/admin') }}">Admin</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>
