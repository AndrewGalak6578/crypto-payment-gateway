<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->public_id }}</title>
    <meta name="robots" content="noindex,nofollow">

    @vite('resources/js/app.js')

    @php
        $assetKey = strtolower((string) ($invoice->asset_key ?? $invoice->coin ?? ''));
        $networkKey = strtolower((string) ($invoice->network_key ?? ''));
        $coinUpper = strtoupper((string) ($invoice->coin ?? ''));

        $networkLabels = [
            'bitcoin' => 'Bitcoin',
            'litecoin' => 'Litecoin',
            'dash' => 'Dash',
            'evm_local' => 'Local EVM',
            'ethereum' => 'Ethereum',
            'bsc' => 'BNB Smart Chain',
            'polygon' => 'Polygon',
            'arbitrum' => 'Arbitrum',
            'optimism' => 'Optimism',
            'base' => 'Base',
        ];

        $assetLabels = [
            'btc' => 'Bitcoin',
            'ltc' => 'Litecoin',
            'dash' => 'Dash',
            'eth_local' => 'Ether (Local)',
            'eth_usdt_local' => 'Tether USD (ERC-20 Local)',
            'eth' => 'Ether',
            'usdt' => 'Tether USD',
            'usdc' => 'USD Coin',
        ];

        $isEvm = str_contains($networkKey, 'evm')
            || in_array($networkKey, ['ethereum', 'bsc', 'polygon', 'arbitrum', 'optimism', 'base'], true);

        $nativeEvmAssetKeys = ['eth', 'eth_local', 'matic', 'bnb', 'avax'];
        $isNativeEvm = $isEvm && in_array($assetKey, $nativeEvmAssetKeys, true);
        $isErc20 = $isEvm && ! $isNativeEvm;
        $paymentUriUsable = ! $isEvm;

        $assetLabel = $assetLabels[$assetKey] ?? strtoupper($assetKey ?: $coinUpper);
        $networkLabel = $networkLabels[$networkKey] ?? ($networkKey !== '' ? strtoupper($networkKey) : 'Unknown network');

        $instructionTitle = match (true) {
            $isErc20 => 'ERC-20 token payment',
            $isNativeEvm => 'Native EVM payment',
            default => 'UTXO payment',
        };

        $instructionBody = match (true) {
            $isErc20 => 'Send exactly the token amount shown above to this deposit address on the selected network. Ensure you send the correct token contract and network to avoid loss of funds.',
            $isNativeEvm => 'Send native coin only (for example ETH) on the selected EVM network to this address. Do not send ERC-20 tokens unless this invoice explicitly requests a token asset.',
            default => 'Send the requested coin amount to the address. The wallet URI and QR below can be used directly in most UTXO wallets.',
        };

        $initialQrPayload = $paymentUriUsable ? $paymentUri : (string) $invoice->pay_address;
    @endphp

    <style>
        :root {
            --bg: #0b1220;
            --card: #131c30;
            --card-2: #1b2640;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --line: rgba(148, 163, 184, 0.25);
            --accent: #38bdf8;
            --accent-2: #22d3ee;
            --ok: #22c55e;
            --warn: #f59e0b;
            --danger: #ef4444;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 15% -10%, rgba(56, 189, 248, 0.16), transparent 45%),
                radial-gradient(circle at 85% 0%, rgba(34, 211, 238, 0.12), transparent 40%),
                linear-gradient(180deg, #070d18 0%, var(--bg) 100%);
            padding: 24px;
        }

        .shell {
            max-width: 980px;
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }

        .card {
            background: linear-gradient(180deg, rgba(19, 28, 48, 0.98), rgba(19, 28, 48, 0.92));
            border: 1px solid var(--line);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 20px 45px rgba(2, 6, 23, 0.55);
        }

        .header {
            padding: 24px;
            border-bottom: 1px solid var(--line);
            display: grid;
            gap: 14px;
        }

        .row {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .title h1 {
            margin: 0;
            font-size: clamp(24px, 3vw, 34px);
            line-height: 1.1;
            color: #f8fafc;
        }

        .id {
            margin-top: 8px;
            font-size: 13px;
            color: var(--muted);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }

        .status-badge {
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.05em;
            border: 1px solid transparent;
        }

        .status-badge.pending { color: #fef3c7; background: rgba(245, 158, 11, 0.14); border-color: rgba(245, 158, 11, 0.3); }
        .status-badge.fixated { color: #bae6fd; background: rgba(56, 189, 248, 0.14); border-color: rgba(56, 189, 248, 0.35); }
        .status-badge.paid { color: #dcfce7; background: rgba(34, 197, 94, 0.14); border-color: rgba(34, 197, 94, 0.35); }
        .status-badge.expired { color: #fee2e2; background: rgba(239, 68, 68, 0.14); border-color: rgba(239, 68, 68, 0.35); }

        .amount-box {
            display: grid;
            gap: 6px;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(56, 189, 248, 0.35);
            background: rgba(15, 23, 42, 0.55);
        }

        .amount-label {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .amount-main {
            font-size: clamp(28px, 4vw, 40px);
            font-weight: 800;
            word-break: break-word;
        }

        .amount-usd {
            color: #cbd5e1;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 10px;
        }

        .meta-card {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
            background: rgba(15, 23, 42, 0.45);
        }

        .meta-label {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 4px;
            letter-spacing: 0.05em;
        }

        .meta-value {
            font-size: 13px;
            font-weight: 600;
            word-break: break-word;
        }

        .body {
            padding: 22px;
            display: grid;
            gap: 16px;
        }

        .grid-2 {
            display: grid;
            gap: 14px;
            grid-template-columns: minmax(0, 1.2fr) minmax(260px, 1fr);
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--card-2);
            padding: 14px;
            display: grid;
            gap: 10px;
        }

        .panel h3 {
            margin: 0;
            font-size: 14px;
            color: #f8fafc;
        }

        .sub {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 13px;
            line-height: 1.5;
            word-break: break-all;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 9px 12px;
            font: inherit;
            font-size: 13px;
            color: #f8fafc;
            text-decoration: none;
            cursor: pointer;
            background: #1e293b;
        }

        .btn:hover { filter: brightness(1.08); }
        .btn:disabled { opacity: 0.65; cursor: not-allowed; }

        .btn-primary { background: #0284c7; border-color: rgba(56, 189, 248, 0.45); }
        .btn-secondary { background: #1e293b; border-color: var(--line); }

        .hint {
            font-size: 12px;
            color: #cbd5e1;
            background: rgba(2, 132, 199, 0.08);
            border: 1px solid rgba(56, 189, 248, 0.28);
            border-radius: 8px;
            padding: 8px 10px;
        }

        .copy-feedback {
            min-height: 18px;
            margin: 2px 0 0;
            font-size: 12px;
            color: #bae6fd;
        }

        .copy-feedback.error {
            color: #fecaca;
        }

        .warning {
            color: #fde68a;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.32);
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 12px;
        }

        .qr-wrap {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.6);
            padding: 12px;
            display: grid;
            justify-items: center;
            align-content: start;
            gap: 8px;
        }

        .qr-box {
            background: #fff;
            border-radius: 8px;
            padding: 10px;
            min-width: 220px;
            min-height: 220px;
            display: grid;
            place-items: center;
            color: #334155;
            text-align: center;
            font-size: 12px;
        }

        .stats {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        }

        .stat {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
            background: rgba(15, 23, 42, 0.45);
        }

        .stat .label {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 4px;
            letter-spacing: 0.04em;
        }

        .stat .value {
            font-size: 13px;
            font-weight: 600;
            word-break: break-word;
        }

        .note {
            color: var(--muted);
            font-size: 12px;
            border-left: 3px solid rgba(56, 189, 248, 0.45);
            padding-left: 9px;
        }

        .timer {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--warn);
            font-weight: 700;
        }

        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.45; }
        }

        @media (max-width: 860px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">
    <section class="card">
        <div class="header">
            <div class="row">
                <div class="title">
                    <h1>Payment Invoice</h1>
                    <div class="id">ID: <span id="public-id">{{ $invoice->public_id }}</span></div>
                </div>
                <div id="status-badge" class="status-badge {{ $invoice->status }}">{{ ucfirst($invoice->status) }}</div>
            </div>

            <div class="amount-box">
                <div class="amount-label">Amount to pay</div>
                <div class="amount-main" id="amount-coin">{{ rtrim(rtrim(number_format((float)$invoice->amount_coin, 8, '.', ''), '0'), '.') }} {{ $coinUpper }}</div>
                <div class="amount-usd">≈ $<span id="expected-usd">{{ number_format((float)$invoice->expected_usd, 2, '.', ',') }}</span> USD</div>
            </div>

            <div class="meta-grid">
                <div class="meta-card">
                    <div class="meta-label">Asset</div>
                    <div class="meta-value">{{ $assetLabel }} <span class="mono">({{ $assetKey ?: '—' }})</span></div>
                </div>
                <div class="meta-card">
                    <div class="meta-label">Network</div>
                    <div class="meta-value">{{ $networkLabel }} <span class="mono">({{ $networkKey ?: '—' }})</span></div>
                </div>
                <div class="meta-card">
                    <div class="meta-label">Payment mode</div>
                    <div class="meta-value">{{ $instructionTitle }}</div>
                </div>
            </div>
        </div>

        <div class="body">
            <div class="grid-2">
                <div class="panel">
                    <h3>Payment instructions</h3>
                    <p class="sub" id="instruction-body">{{ $instructionBody }}</p>

                    @if(! $paymentUriUsable)
                        <p class="warning" id="uri-warning">
                            Wallet deep-link is limited for this asset type. Use address + network manually in your wallet.
                        </p>
                    @endif

                    <div>
                        <div class="meta-label">Payment address</div>
                        <div class="mono" id="pay-address">{{ $invoice->pay_address }}</div>
                        <div class="actions" style="margin-top: 8px;">
                            <button type="button" class="btn btn-primary" id="copy-address-btn">Copy address</button>
                        </div>
                    </div>

                    <div>
                        <div class="meta-label">Payment request</div>
                        <div class="mono" id="payment-uri">{{ $paymentUri }}</div>
                        <div class="actions" style="margin-top: 8px;">
                            <button type="button" class="btn btn-secondary" id="copy-uri-btn">Copy request</button>
                            <a
                                class="btn btn-primary"
                                id="open-wallet-link"
                                href="{{ $paymentUriUsable ? $paymentUri : '#' }}"
                                @if(! $paymentUriUsable) style="display:none;" @endif
                            >
                                Open wallet
                            </a>
                        </div>
                        <p class="hint" id="request-hint">
                            @if($paymentUriUsable)
                                URI includes amount and address for compatible wallets.
                            @else
                                Use this as a manual payment reference. URI deep-links are not reliable for this asset type.
                            @endif
                        </p>
                        <p class="copy-feedback" id="copy-feedback" role="status" aria-live="polite"></p>
                    </div>

                    <div>
                        <div class="meta-label">Time remaining</div>
                        <div class="timer" id="countdown">
                            @if($invoice->expires_at)
                                Calculating...
                            @else
                                —
                            @endif
                        </div>
                        <p class="sub">Expires: <span id="expires-at">{{ $invoice->expires_at ? $invoice->expires_at->format('M d, Y H:i:s T') : '—' }}</span></p>
                    </div>
                </div>

                <div class="qr-wrap">
                    <div class="qr-box" id="qr-placeholder">Loading QR...</div>
                    <p class="sub" id="qr-caption">
                        @if($paymentUriUsable)
                            Scan QR for wallet URI request
                        @else
                            Scan QR for payment address
                        @endif
                    </p>
                </div>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="label">Received (all)</div>
                    <div class="value mono" id="received-all">{{ rtrim(rtrim(number_format((float)$invoice->received_all_coin, 8, '.', ''), '0'), '.') }} {{ $coinUpper }}</div>
                </div>
                <div class="stat">
                    <div class="label">Confirmed</div>
                    <div class="value mono" id="received-conf">{{ rtrim(rtrim(number_format((float)$invoice->received_conf_coin, 8, '.', ''), '0'), '.') }} {{ $coinUpper }}</div>
                </div>
                <div class="stat">
                    <div class="label">Fixated at</div>
                    <div class="value" id="fixated-at">{{ $invoice->fixated_at ? $invoice->fixated_at->format('M d, H:i') : '—' }}</div>
                </div>
                <div class="stat">
                    <div class="label">Paid at</div>
                    <div class="value" id="paid-at">{{ $invoice->paid_at ? $invoice->paid_at->format('M d, H:i') : '—' }}</div>
                </div>
            </div>

            <p class="note"><span class="pulse">●</span> This page refreshes automatically every 15 seconds while invoice is active.</p>
        </div>
    </section>
</div>

<script>
    const statusUrl = @json($statusUrl);
    const initialStatus = @json($invoice->status);
    const initialExpiresAt = @json(optional($invoice->expires_at)->toIso8601String());
    const paymentUriUsable = @json($paymentUriUsable);
    const initialQrPayload = @json($initialQrPayload);

    const statusLabels = {
        pending: 'Awaiting payment',
        fixated: 'Payment detected',
        paid: 'Paid',
        expired: 'Expired',
    };

    let currentExpiresAt = initialExpiresAt;

    const els = {
        statusBadge: document.getElementById('status-badge'),
        amountCoin: document.getElementById('amount-coin'),
        expectedUsd: document.getElementById('expected-usd'),
        payAddress: document.getElementById('pay-address'),
        paymentUri: document.getElementById('payment-uri'),
        qrPlaceholder: document.getElementById('qr-placeholder'),
        qrCaption: document.getElementById('qr-caption'),
        receivedAll: document.getElementById('received-all'),
        receivedConf: document.getElementById('received-conf'),
        expiresAt: document.getElementById('expires-at'),
        fixatedAt: document.getElementById('fixated-at'),
        paidAt: document.getElementById('paid-at'),
        countdown: document.getElementById('countdown'),
        openWalletLink: document.getElementById('open-wallet-link'),
        copyAddressBtn: document.getElementById('copy-address-btn'),
        copyUriBtn: document.getElementById('copy-uri-btn'),
        copyFeedback: document.getElementById('copy-feedback'),
    };

    function formatNumber(num, decimals = 8) {
        const numeric = Number.parseFloat(num);
        if (Number.isNaN(numeric)) {
            return '0';
        }

        const normalized = numeric.toFixed(decimals);
        return normalized.replace(/\.?0+$/, '');
    }

    function formatUsd(value) {
        const numeric = Number.parseFloat(value);
        if (Number.isNaN(numeric)) {
            return '0.00';
        }

        return numeric.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function setStatusBadge(status) {
        const normalized = (status || '').toLowerCase();
        els.statusBadge.className = 'status-badge ' + normalized;
        els.statusBadge.textContent = statusLabels[normalized] ?? status;
    }

    function formatDate(isoString) {
        if (!isoString) return '—';
        const date = new Date(isoString);
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function updateCountdown(expiresAt) {
        if (!expiresAt || expiresAt === '—') {
            els.countdown.textContent = '—';
            els.countdown.className = 'timer';
            return;
        }

        const expiry = new Date(expiresAt).getTime();
        const now = Date.now();
        const diff = expiry - now;

        if (diff <= 0) {
            els.countdown.textContent = 'Expired';
            els.countdown.className = 'timer';
            stopCountdown();
            return;
        }

        const totalSeconds = Math.floor(diff / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        let timeStr = '';
        if (hours > 0) {
            timeStr = `${hours}h ${minutes}m ${seconds}s`;
        } else if (minutes > 0) {
            timeStr = `${minutes}m ${seconds}s`;
        } else {
            timeStr = `${seconds}s`;
        }

        els.countdown.textContent = timeStr;
        els.countdown.className = 'timer' + (diff < 300000 ? ' pulse' : '');
    }

    async function generateQR(payload) {
        try {
            const dataUrl = await window.QRCodeLib.toDataURL(payload, {
                width: 220,
                margin: 1,
            });

            els.qrPlaceholder.innerHTML = `<img src="${dataUrl}" alt="Payment QR" style="max-width:220px;height:auto;">`;
        } catch (e) {
            els.qrPlaceholder.textContent = 'QR unavailable';
        }
    }

    async function copyText(text, button, originalText) {
        const safeText = typeof text === 'string' ? text.trim() : '';
        if (!safeText || safeText === '—') {
            if (els.copyFeedback) {
                els.copyFeedback.textContent = 'Nothing to copy.';
                els.copyFeedback.className = 'copy-feedback error';
            }
            return;
        }

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(safeText);
            } else {
                const textArea = document.createElement('textarea');
                textArea.value = safeText;
                textArea.style.position = 'fixed';
                textArea.style.top = '0';
                textArea.style.left = '0';
                textArea.style.opacity = '0';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
            }

            button.textContent = 'Copied';
            if (els.copyFeedback) {
                els.copyFeedback.textContent = `${originalText} successful.`;
                els.copyFeedback.className = 'copy-feedback';
            }
            setTimeout(() => {
                button.textContent = originalText;
            }, 1600);
        } catch (e) {
            button.textContent = 'Copy failed';
            if (els.copyFeedback) {
                els.copyFeedback.textContent = 'Failed to copy.';
                els.copyFeedback.className = 'copy-feedback error';
            }
            setTimeout(() => {
                button.textContent = originalText;
            }, 1600);
        }
    }

    function paymentRequestText(data) {
        const amount = formatNumber(data.amount_coin);
        const coin = (data.coin || '').toUpperCase();
        const address = data.pay_address || '';

        if (paymentUriUsable) {
            return data.payment_uri || '';
        }

        return `Send ${amount} ${coin} to ${address}`;
    }

    function qrPayloadFromData(data) {
        if (paymentUriUsable) {
            return data.payment_uri || data.pay_address || '';
        }

        return data.pay_address || '';
    }

    async function refreshStatus() {
        try {
            const response = await fetch(statusUrl + '?refresh=1', {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const json = await response.json();
            const data = json.data;

            setStatusBadge(data.status);
            els.amountCoin.textContent = `${formatNumber(data.amount_coin)} ${(data.coin || '').toUpperCase()}`;
            els.expectedUsd.textContent = formatUsd(data.expected_usd);
            els.payAddress.textContent = data.pay_address || '—';
            els.paymentUri.textContent = paymentRequestText(data) || '—';
            els.receivedAll.textContent = `${formatNumber(data.received_all_coin)} ${(data.coin || '').toUpperCase()}`;
            els.receivedConf.textContent = `${formatNumber(data.received_conf_coin)} ${(data.coin || '').toUpperCase()}`;
            els.fixatedAt.textContent = formatDate(data.fixated_at);
            els.paidAt.textContent = formatDate(data.paid_at);

            if (els.openWalletLink && paymentUriUsable) {
                els.openWalletLink.href = data.payment_uri || '#';
            }

            const qrPayload = qrPayloadFromData(data);
            if (qrPayload) {
                generateQR(qrPayload);
            }

            if (data.expires_at) {
                els.expiresAt.textContent = new Date(data.expires_at).toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    timeZoneName: 'short',
                });
            } else {
                els.expiresAt.textContent = '—';
            }

            currentExpiresAt = data.expires_at ?? null;
            updateCountdown(currentExpiresAt);

            if (data.status === 'paid' || data.status === 'expired') {
                els.countdown.textContent = data.status === 'paid' ? 'Paid' : 'Expired';
                els.countdown.className = 'timer';
                stopPolling();
                stopCountdown();
            }
        } catch (e) {
            // keep silent in UI; polling should continue
        }
    }

    let pollTimer = null;
    let countdownTimer = null;

    function startPolling() {
        if (initialStatus === 'paid' || initialStatus === 'expired') {
            return;
        }

        pollTimer = setInterval(refreshStatus, 15000);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function startCountdown() {
        updateCountdown(currentExpiresAt);
        countdownTimer = setInterval(() => {
            updateCountdown(currentExpiresAt);
        }, 1000);
    }

    function stopCountdown() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }

    els.copyAddressBtn.addEventListener('click', () => {
        copyText(els.payAddress.textContent, els.copyAddressBtn, 'Copy address');
    });

    els.copyUriBtn.addEventListener('click', () => {
        copyText(els.paymentUri.textContent, els.copyUriBtn, 'Copy request');
    });

    document.addEventListener('DOMContentLoaded', () => {
        generateQR(initialQrPayload);
        startPolling();
        startCountdown();
    });

    window.addEventListener('beforeunload', () => {
        stopPolling();
        stopCountdown();
    });
</script>
</body>
</html>
