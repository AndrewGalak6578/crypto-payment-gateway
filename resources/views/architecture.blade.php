<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Architecture Overview</title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        :root {
            --bg: #f5f1e8;
            --surface: rgba(255, 252, 245, 0.78);
            --surface-strong: #fffaf0;
            --ink: #1f2430;
            --muted: #5d6575;
            --line: rgba(31, 36, 48, 0.12);
            --accent: #c4492d;
            --accent-soft: rgba(196, 73, 45, 0.12);
            --secondary: #1d6a72;
            --secondary-soft: rgba(29, 106, 114, 0.12);
            --success: #3f7d4a;
            --success-soft: rgba(63, 125, 74, 0.14);
            --warning: #8b6720;
            --warning-soft: rgba(139, 103, 32, 0.14);
            --shadow: 0 20px 60px rgba(63, 45, 20, 0.10);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(196, 73, 45, 0.16), transparent 30%),
                radial-gradient(circle at top right, rgba(29, 106, 114, 0.18), transparent 34%),
                linear-gradient(180deg, #f7f1e7 0%, #efe5d6 100%);
        }

        .page {
            max-width: 1380px;
            margin: 0 auto;
            padding: 32px 20px 56px;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 18px;
            margin-bottom: 18px;
        }

        .panel {
            background: var(--surface);
            border: 1px solid var(--line);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            box-shadow: var(--shadow);
        }

        .hero-main {
            padding: 28px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-weight: 700;
        }

        h1 {
            margin: 18px 0 12px;
            font-size: clamp(34px, 5vw, 60px);
            line-height: 0.95;
            letter-spacing: -0.04em;
        }

        .lead {
            margin: 0;
            max-width: 56rem;
            color: var(--muted);
            font-size: 17px;
            line-height: 1.65;
        }

        .hero-side {
            padding: 24px;
            display: grid;
            gap: 12px;
        }

        .metric {
            padding: 16px 18px;
            border-radius: 18px;
            background: var(--surface-strong);
            border: 1px solid var(--line);
        }

        .metric-label {
            font-size: 12px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .metric-value {
            font-size: 20px;
            font-weight: 800;
        }

        .layout {
            display: grid;
            gap: 18px;
        }

        .diagram-shell {
            padding: 22px;
            overflow: hidden;
        }

        .diagram-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .diagram-header h2,
        .notes h2 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        .diagram-header p,
        .notes p {
            margin: 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .pill {
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .pill.entry { background: var(--accent-soft); color: var(--accent); }
        .pill.logic { background: var(--secondary-soft); color: var(--secondary); }
        .pill.storage { background: var(--warning-soft); color: var(--warning); }
        .pill.async { background: var(--success-soft); color: var(--success); }

        .diagram-canvas {
            width: 100%;
            overflow-x: auto;
            border-radius: 20px;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.35), rgba(255,255,255,0.15)),
                repeating-linear-gradient(
                    90deg,
                    rgba(31, 36, 48, 0.03) 0,
                    rgba(31, 36, 48, 0.03) 1px,
                    transparent 1px,
                    transparent 100px
                ),
                repeating-linear-gradient(
                    0deg,
                    rgba(31, 36, 48, 0.03) 0,
                    rgba(31, 36, 48, 0.03) 1px,
                    transparent 1px,
                    transparent 88px
                );
            border: 1px solid var(--line);
        }

        svg {
            display: block;
            min-width: 1200px;
            width: 100%;
            height: auto;
        }

        .notes {
            padding: 24px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .note {
            padding: 18px;
            border-radius: 18px;
            background: var(--surface-strong);
            border: 1px solid var(--line);
        }

        .note h3 {
            margin: 0 0 10px;
            font-size: 16px;
        }

        .note p {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        code {
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 0.95em;
            background: rgba(31, 36, 48, 0.06);
            padding: 2px 6px;
            border-radius: 6px;
        }

        @media (max-width: 1080px) {
            .hero,
            .notes {
                grid-template-columns: 1fr;
            }

            .diagram-header {
                flex-direction: column;
            }

            .legend {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
<main class="page">
    <section class="hero">
        <article class="panel hero-main">
            <div class="eyebrow">Crypto gateway map</div>
            <h1>How one invoice moves through the system</h1>
            <p class="lead">
                This view compresses the codebase into one operational picture: entry points, domain services,
                queues, persistence, chain RPC, settlement, and webhook delivery. The actual lifecycle is:
                create invoice, allocate address, monitor chain, mark status, settle merchant funds, notify merchant.
            </p>
        </article>

        <aside class="panel hero-side">
            <div class="metric">
                <div class="metric-label">Primary stack</div>
                <div class="metric-value">Laravel 12 + Vue 3 + Queue jobs</div>
            </div>
            <div class="metric">
                <div class="metric-label">Stable payment path</div>
                <div class="metric-value">BTC / LTC / DASH via UTXO RPC</div>
            </div>
            <div class="metric">
                <div class="metric-label">Partial groundwork</div>
                <div class="metric-value">Local EVM support and derivation scaffolding</div>
            </div>
        </aside>
    </section>

    <section class="panel diagram-shell">
        <div class="diagram-header">
            <div>
                <h2>Runtime flow</h2>
                <p>
                    The largest path starts at <code>POST /api/v1/invoices</code> and ends with either an on-chain
                    forward or an internal merchant balance credit, followed by signed webhooks.
                </p>
            </div>
            <div class="legend">
                <span class="pill entry">Entry</span>
                <span class="pill logic">Logic</span>
                <span class="pill storage">Storage / external</span>
                <span class="pill async">Async jobs</span>
            </div>
        </div>

        <div class="diagram-canvas">
            <svg viewBox="0 0 1440 980" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Architecture diagram">
                <defs>
                    <marker id="arrow" markerWidth="10" markerHeight="10" refX="8" refY="5" orient="auto">
                        <path d="M 0 0 L 10 5 L 0 10 z" fill="#5d6575"/>
                    </marker>

                    <filter id="softShadow" x="-20%" y="-20%" width="140%" height="140%">
                        <feDropShadow dx="0" dy="8" stdDeviation="12" flood-color="rgba(52,33,13,0.16)"/>
                    </filter>
                </defs>

                <g font-family="Segoe UI, Tahoma, sans-serif">
                    <g fill="#c4492d" opacity="0.08">
                        <circle cx="160" cy="150" r="120"/>
                        <circle cx="1260" cy="180" r="140"/>
                    </g>

                    <g stroke="#5d6575" stroke-width="2.2" fill="none" marker-end="url(#arrow)">
                        <path d="M 230 190 C 270 190, 280 190, 330 190"/>
                        <path d="M 510 170 C 560 140, 640 120, 720 120"/>
                        <path d="M 510 210 C 560 220, 620 230, 720 250"/>
                        <path d="M 910 120 C 980 120, 1010 120, 1075 120"/>
                        <path d="M 910 250 C 980 250, 1010 250, 1075 250"/>
                        <path d="M 1255 120 C 1325 120, 1335 120, 1360 120"/>
                        <path d="M 1255 250 C 1325 250, 1335 250, 1360 250"/>

                        <path d="M 230 470 C 270 470, 280 470, 330 470"/>
                        <path d="M 510 470 C 580 470, 600 470, 720 470"/>
                        <path d="M 900 470 C 980 470, 1000 470, 1075 470"/>
                        <path d="M 900 510 C 980 545, 1020 585, 1075 640"/>
                        <path d="M 1255 470 C 1310 470, 1325 470, 1360 470"/>
                        <path d="M 1255 640 C 1310 640, 1325 640, 1360 640"/>
                        <path d="M 230 760 C 300 760, 320 760, 330 760"/>
                        <path d="M 510 760 C 570 760, 610 760, 720 760"/>
                        <path d="M 900 760 C 980 760, 1000 760, 1075 760"/>
                        <path d="M 1255 760 C 1310 760, 1320 760, 1360 760"/>

                        <path d="M 820 155 C 820 195, 820 205, 820 230"/>
                        <path d="M 1165 155 C 1165 190, 1165 205, 1165 230"/>
                        <path d="M 820 505 C 820 585, 820 650, 820 720"/>
                        <path d="M 1165 505 C 1165 565, 1165 610, 1165 720"/>
                    </g>

                    <g font-size="15" font-weight="700" fill="#1f2430">
                        <text x="30" y="54">1. Entry points</text>
                        <text x="30" y="334">2. Invoice lifecycle</text>
                        <text x="30" y="626">3. Settlement and notification</text>
                    </g>

                    <g filter="url(#softShadow)">
                        <rect x="40" y="80" width="190" height="130" rx="22" fill="#ffe8e0" stroke="#d9927d"/>
                        <rect x="330" y="110" width="180" height="120" rx="22" fill="#e0f1f4" stroke="#76aab0"/>
                        <rect x="720" y="60" width="190" height="120" rx="22" fill="#fff1d7" stroke="#c9a466"/>
                        <rect x="720" y="190" width="190" height="120" rx="22" fill="#fff1d7" stroke="#c9a466"/>
                        <rect x="1075" y="60" width="180" height="120" rx="22" fill="#dff2e0" stroke="#7ca783"/>
                        <rect x="1075" y="190" width="180" height="120" rx="22" fill="#dff2e0" stroke="#7ca783"/>
                        <rect x="1360" y="60" width="44" height="250" rx="20" fill="#fffaf0" stroke="#ccb78b"/>

                        <rect x="40" y="410" width="190" height="120" rx="22" fill="#dff2e0" stroke="#7ca783"/>
                        <rect x="330" y="410" width="180" height="120" rx="22" fill="#e0f1f4" stroke="#76aab0"/>
                        <rect x="720" y="410" width="180" height="120" rx="22" fill="#e0f1f4" stroke="#76aab0"/>
                        <rect x="1075" y="410" width="180" height="120" rx="22" fill="#fff1d7" stroke="#c9a466"/>
                        <rect x="1075" y="580" width="180" height="120" rx="22" fill="#dff2e0" stroke="#7ca783"/>
                        <rect x="1360" y="410" width="44" height="290" rx="20" fill="#fffaf0" stroke="#ccb78b"/>

                        <rect x="40" y="700" width="190" height="120" rx="22" fill="#dff2e0" stroke="#7ca783"/>
                        <rect x="330" y="700" width="180" height="120" rx="22" fill="#e0f1f4" stroke="#76aab0"/>
                        <rect x="720" y="700" width="180" height="120" rx="22" fill="#fff1d7" stroke="#c9a466"/>
                        <rect x="1075" y="700" width="180" height="120" rx="22" fill="#dff2e0" stroke="#7ca783"/>
                        <rect x="1360" y="700" width="44" height="120" rx="20" fill="#fffaf0" stroke="#ccb78b"/>
                    </g>

                    <g font-size="14" fill="#1f2430">
                        <text x="65" y="115" font-weight="800">Merchant API</text>
                        <text x="65" y="138">POST /api/v1/invoices</text>
                        <text x="65" y="160">GET /api/v1/invoices/{id}</text>
                        <text x="65" y="182">Bearer API key auth</text>

                        <text x="350" y="145" font-weight="800">InvoiceController</text>
                        <text x="350" y="168">Create invoice</text>
                        <text x="350" y="190">Read invoice</text>

                        <text x="742" y="95" font-weight="800">InvoiceCreator</text>
                        <text x="742" y="118">rate snapshot</text>
                        <text x="742" y="140">TTL</text>
                        <text x="742" y="162">public_id</text>

                        <text x="742" y="225" font-weight="800">Address allocator</text>
                        <text x="742" y="248">UTXO or EVM</text>
                        <text x="742" y="270">payment address</text>
                        <text x="742" y="292">assignment</text>

                        <text x="1097" y="96" font-weight="800">invoices</text>
                        <text x="1097" y="118">status</text>
                        <text x="1097" y="140">amounts</text>
                        <text x="1097" y="162">timestamps</text>

                        <text x="1097" y="225" font-weight="800">payment_addresses</text>
                        <text x="1097" y="247">network / asset</text>
                        <text x="1097" y="269">invoice link</text>
                        <text x="1097" y="291">derivation meta</text>

                        <text x="72" y="455" font-weight="800">MonitorInvoiceJob</text>
                        <text x="72" y="477">queue loop</text>
                        <text x="72" y="499">poll until terminal state</text>

                        <text x="350" y="455" font-weight="800">InvoiceStatusRefresher</text>
                        <text x="350" y="477">reads chain state</text>
                        <text x="350" y="499">applies transitions</text>

                        <text x="742" y="455" font-weight="800">Invoice state machine</text>
                        <text x="742" y="477">pending</text>
                        <text x="742" y="499">fixated / paid / expired</text>

                        <text x="1097" y="455" font-weight="800">Chain RPC</text>
                        <text x="1097" y="477">BTC / LTC / DASH</text>
                        <text x="1097" y="499">plus EVM scaffolding</text>

                        <text x="1097" y="625" font-weight="800">ForwardInvoiceJob</text>
                        <text x="1097" y="647">queued after payment</text>
                        <text x="1097" y="669">when net settlement remains</text>

                        <text x="72" y="745" font-weight="800">EnqueueInvoiceWebhook</text>
                        <text x="72" y="767">stores delivery row</text>
                        <text x="72" y="789">dispatches sender job</text>

                        <text x="350" y="745" font-weight="800">InvoiceForwarder</text>
                        <text x="350" y="767">resolve wallet</text>
                        <text x="350" y="789">send on-chain or credit balance</text>

                        <text x="742" y="735" font-weight="800">Settlement storage</text>
                        <text x="742" y="757">super_wallets</text>
                        <text x="742" y="779">merchant_balances</text>
                        <text x="742" y="801">forward txids</text>

                        <text x="1097" y="735" font-weight="800">DeliverWebhookJob</text>
                        <text x="1097" y="757">WebhookDeliverySender</text>
                        <text x="1097" y="779">retry with backoff</text>
                        <text x="1097" y="801">signed HTTP POST</text>

                        <text x="1372" y="90" transform="rotate(90 1372 90)" font-weight="800">storage</text>
                        <text x="1372" y="435" transform="rotate(90 1372 435)" font-weight="800">external</text>
                        <text x="1372" y="722" transform="rotate(90 1372 722)" font-weight="800">delivery</text>
                    </g>
                </g>
            </svg>
        </div>
    </section>

    <section class="panel notes">
        <article class="note">
            <h3>Three interfaces, one backend</h3>
            <p>
                The same Laravel app exposes merchant API endpoints, a hosted customer invoice page, and two Vue
                portals: merchant and admin. Operationally, the invoice pipeline is the backbone for all of them.
            </p>
        </article>
        <article class="note">
            <h3>Why the queue matters</h3>
            <p>
                Monitoring, settlement, and webhooks are asynchronous by design. If the queue worker is not running,
                invoices can still be created, but they will not progress through the full lifecycle automatically.
            </p>
        </article>
        <article class="note">
            <h3>What is still in progress</h3>
            <p>
                UTXO chains are the mature path in this repository. EVM support currently includes registry entries,
                local derivation helpers, and an RPC client, but not the full payment detection and settlement loop.
            </p>
        </article>
    </section>
</main>
</body>
</html>
