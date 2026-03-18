<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->public_id }}</title>
    <meta name="robots" content="noindex,nofollow">

    @vite('resources/js/app.js')
    <style>
        :root {
            --bg-primary: #0a0e1a;
            --bg-secondary: #14182b;
            --bg-card: #1a1f38;
            --text-primary: #ffffff;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --accent-blue: #3b82f6;
            --accent-blue-hover: #2563eb;
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.1);
            --danger: #ef4444;
            --danger-bg: rgba(239, 68, 68, 0.1);
            --border: rgba(255, 255, 255, 0.1);
            --border-light: rgba(255, 255, 255, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, var(--bg-primary) 0%, #0f1729 50%, #0a0e1a 100%);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            width: 100%;
            max-width: 900px;
        }

        .invoice-card {
            background: var(--bg-card);
            border-radius: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(139, 92, 246, 0.15) 100%);
            padding: 32px;
            border-bottom: 1px solid var(--border);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 20px;
        }

        .invoice-title {
            flex: 1;
        }

        .invoice-title h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #60a5fa 0%, #a78bfa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .invoice-id {
            color: var(--text-secondary);
            font-size: 14px;
            font-family: 'Courier New', monospace;
        }

        .status-badge {
            padding: 10px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            border: 2px solid;
        }

        .status-badge.pending {
            background: var(--warning-bg);
            color: var(--warning);
            border-color: var(--warning);
        }

        .status-badge.fixated {
            background: rgba(59, 130, 246, 0.1);
            color: var(--accent-blue);
            border-color: var(--accent-blue);
        }

        .status-badge.paid {
            background: var(--success-bg);
            color: var(--success);
            border-color: var(--success);
        }

        .status-badge.expired {
            background: var(--danger-bg);
            color: var(--danger);
            border-color: var(--danger);
        }

        .amount-display {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
        }

        .amount-label {
            color: var(--text-secondary);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }

        .amount-value {
            font-size: 42px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 8px;
            word-break: break-all;
        }

        .amount-usd {
            color: var(--text-secondary);
            font-size: 18px;
        }

        .card-body {
            padding: 32px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            .header-top {
                flex-direction: column;
            }
            .amount-value {
                font-size: 32px;
            }
        }

        .info-panel {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 16px;
            padding: 20px;
        }

        .panel-label {
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .panel-value {
            color: var(--text-primary);
            font-size: 16px;
            font-weight: 600;
            word-break: break-all;
        }

        .monospace {
            font-family: 'Courier New', Courier, monospace;
            font-size: 14px;
            line-height: 1.6;
        }

        .qr-container {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 280px;
        }

        .qr-code {
            background: white;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 16px;
        }

        .qr-code img {
            display: block;
            max-width: 100%;
            height: auto;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        button, .btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background: var(--accent-blue);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-blue-hover);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--bg-primary);
            border-color: var(--accent-blue);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-top: 24px;
        }

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-box {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 16px;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-value {
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 600;
        }

        .timer {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--warning);
            font-weight: 700;
        }

        .footer-note {
            margin-top: 24px;
            padding: 16px;
            background: rgba(59, 130, 246, 0.05);
            border-left: 3px solid var(--accent-blue);
            border-radius: 8px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="invoice-card">
            <div class="card-header">
                <div class="header-top">
                    <div class="invoice-title">
                        <h1>Payment Invoice</h1>
                        <div class="invoice-id">ID: <span id="public-id">{{ $invoice->public_id }}</span></div>
                    </div>
                    <div id="status-badge" class="status-badge {{ $invoice->status }}">
                        {{ ucfirst($invoice->status) }}
                    </div>
                </div>

                <div class="amount-display">
                    <div class="amount-label">Amount to Pay</div>
                    <div class="amount-value" id="amount-coin">{{ rtrim(rtrim(number_format((float)$invoice->amount_coin, 8, '.', ''), '0'), '.') }} {{ strtoupper($invoice->coin) }}</div>
                    <div class="amount-usd">≈ $<span id="expected-usd">{{ number_format((float)$invoice->expected_usd, 2, '.', ',') }}</span> USD</div>
                </div>
            </div>

            <div class="card-body">
                <div class="grid">
                    <div>
                        <div class="info-panel">
                            <div class="panel-label">Payment Address</div>
                            <div class="panel-value monospace" id="pay-address">{{ $invoice->pay_address }}</div>
                            <div class="button-group">
                                <button type="button" class="btn-primary" id="copy-address-btn">Copy Address</button>
                            </div>
                        </div>

                        <div class="info-panel" style="margin-top: 16px;">
                            <div class="panel-label">Payment URI</div>
                            <div class="panel-value monospace" id="payment-uri" style="font-size: 12px; line-height: 1.5;">{{ $paymentUri }}</div>
                            <div class="button-group">
                                <button type="button" class="btn-secondary" id="copy-uri-btn">Copy URI</button>
                                <a class="btn btn-primary" id="open-wallet-link" href="{{ $paymentUri }}">Open Wallet</a>
                            </div>
                        </div>

                        <div class="info-panel" style="margin-top: 16px;">
                            <div class="panel-label">Time Remaining</div>
                            <div class="panel-value">
                                <span class="timer" id="countdown">
                                    @if($invoice->expires_at)
                                        Calculating...
                                    @else
                                        —
                                    @endif
                                </span>
                            </div>
                            <div style="margin-top: 8px; font-size: 12px; color: var(--text-muted);">
                                Expires: <span id="expires-at">{{ $invoice->expires_at ? $invoice->expires_at->format('M d, Y H:i:s T') : '—' }}</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="qr-container">
                            <div class="qr-code">
                                <div id="qr-placeholder" style="text-align: center; color: #666;">Loading QR...</div>
                            </div>
                            <div style="text-align: center; color: var(--text-muted); font-size: 12px;">
                                Scan to pay with your wallet
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-label">Received (All)</div>
                        <div class="stat-value monospace" id="received-all">{{ rtrim(rtrim(number_format((float)$invoice->received_all_coin, 8, '.', ''), '0'), '.') }} {{ strtoupper($invoice->coin) }}</div>
                    </div>

                    <div class="stat-box">
                        <div class="stat-label">Confirmed</div>
                        <div class="stat-value monospace" id="received-conf">{{ rtrim(rtrim(number_format((float)$invoice->received_conf_coin, 8, '.', ''), '0'), '.') }} {{ strtoupper($invoice->coin) }}</div>
                    </div>

                    <div class="stat-box">
                        <div class="stat-label">Fixated At</div>
                        <div class="stat-value" id="fixated-at">{{ $invoice->fixated_at ? $invoice->fixated_at->format('M d, H:i') : '—' }}</div>
                    </div>

                    <div class="stat-box">
                        <div class="stat-label">Paid At</div>
                        <div class="stat-value" id="paid-at">{{ $invoice->paid_at ? $invoice->paid_at->format('M d, H:i') : '—' }}</div>
                    </div>
                </div>

                <div class="footer-note">
                    <span class="pulse">●</span> This page refreshes automatically every 15 seconds while the invoice is active.
                </div>
            </div>
        </div>
    </div>

    <script>
        const statusUrl = @json($statusUrl);
        const initialStatus = @json($invoice->status);
        const initialExpiresAt = @json(optional($invoice->expires_at)->toIso8601String());
        const statusLabels = {
            pending: 'Awaiting Payment',
            fixated: 'Payment Detected',
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
            receivedAll: document.getElementById('received-all'),
            receivedConf: document.getElementById('received-conf'),
            expiresAt: document.getElementById('expires-at'),
            fixatedAt: document.getElementById('fixated-at'),
            paidAt: document.getElementById('paid-at'),
            countdown: document.getElementById('countdown'),
            openWalletLink: document.getElementById('open-wallet-link'),
            copyAddressBtn: document.getElementById('copy-address-btn'),
            copyUriBtn: document.getElementById('copy-uri-btn'),
        };

        function formatNumber(num, decimals = 8) {
            const numStr = parseFloat(num).toFixed(decimals);
            return numStr.replace(/\.?0+$/, '');
        }

        function setStatusBadge(status) {
            const normalized = (status || '').toLowerCase();
            els.statusBadge.className = 'status-badge ' + normalized;
            els.statusBadge.textContent = statusLabels[normalized] ?? status;
        }

        function formatDate(isoString) {
            if (!isoString) return '—';
            const date = new Date(isoString);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        function updateCountdown(expiresAt) {
            if (!expiresAt || expiresAt === '—') {
                els.countdown.textContent = '—';
                els.countdown.className = '';
                return;
            }

            const expiry = new Date(expiresAt).getTime();
            const now = Date.now();
            const diff = expiry - now;

            if (diff <= 0) {
                els.countdown.textContent = 'Expired';
                els.countdown.className = '';
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
            els.countdown.className = 'timer' + (diff < 300000 ? ' pulse' : ''); // pulse if < 5 min
        }

        async function refreshStatus() {
            try {
                const response = await fetch(statusUrl + '?refresh=1', {
                    headers: {
                        'Accept': 'application/json',
                    }
                });

                if (!response.ok) {
                    console.error('Status refresh failed:', response.status);
                    return;
                }

                const json = await response.json();
                const data = json.data;

                setStatusBadge(data.status);
                els.amountCoin.textContent = `${formatNumber(data.amount_coin)} ${data.coin}`;
                els.expectedUsd.textContent = parseFloat(data.expected_usd).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                els.payAddress.textContent = data.pay_address;
                els.paymentUri.textContent = data.payment_uri;
                generateQR(data.payment_uri);
                els.receivedAll.textContent = `${formatNumber(data.received_all_coin)} ${data.coin}`;
                els.receivedConf.textContent = `${formatNumber(data.received_conf_coin)} ${data.coin}`;

                if (data.expires_at) {
                    const formattedExpires = new Date(data.expires_at).toLocaleDateString('en-US', {
                        month: 'short', day: 'numeric', year: 'numeric',
                        hour: '2-digit', minute: '2-digit', second: '2-digit', timeZoneName: 'short'
                    });
                    els.expiresAt.textContent = formattedExpires;
                } else {
                    els.expiresAt.textContent = '—';
                }

                els.fixatedAt.textContent = formatDate(data.fixated_at);
                els.paidAt.textContent = formatDate(data.paid_at);
                els.openWalletLink.href = data.payment_uri;

                currentExpiresAt = data.expires_at ?? null;
                updateCountdown(currentExpiresAt);

                if (data.status === 'paid'){
                    els.countdown.textContent = 'Paid';
                    els.countdown.className = 'timer';
                    stopPolling();
                    stopCountdown();
                    return
                }

                if (data.status === 'expired') {
                    els.countdown.textContent = 'Expired';
                    els.countdown.className = 'timer';
                    stopPolling();
                    stopCountdown();
                    return;
                }
            } catch (e) {
                console.error('Invoice status refresh failed:', e);
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

        async function generateQR(uri) {
            try {
                const dataUrl = await window.QRCodeLib.toDataURL(uri, {
                    width: 220,
                    margin: 1
                });

                els.qrPlaceholder.innerHTML = `<img src="${dataUrl}" alt="QR Code" style="max-width: 220px; height: auto;">`;
            } catch (e) {
                console.error('QR code generation failed:', e);
                els.qrPlaceholder.textContent = 'QR unavailable';
            }
        }

        async function copyText(text, button, originalText) {
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    // Fallback for older browsers
                    const textArea = document.createElement('textarea');
                    textArea.value = text;
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

                button.textContent = '✓ Copied!';
                setTimeout(() => {
                    button.textContent = originalText;
                }, 2000);
            } catch (e) {
                console.error('Copy failed:', e);
                button.textContent = '✗ Failed';
                setTimeout(() => {
                    button.textContent = originalText;
                }, 2000);
            }
        }

        els.copyAddressBtn.addEventListener('click', () => {
            copyText(els.payAddress.textContent, els.copyAddressBtn, 'Copy Address');
        });

        els.copyUriBtn.addEventListener('click', () => {
            copyText(els.paymentUri.textContent, els.copyUriBtn, 'Copy URI');
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            generateQR(@json($paymentUri));
            startPolling();
            startCountdown();
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            stopPolling();
            stopCountdown();
        });
    </script>
</body>
</html>
