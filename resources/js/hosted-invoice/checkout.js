import QRCode from 'qrcode';

const POLL_INTERVAL_MS = 5000;
const REDIRECT_SECONDS = 5;

const config = window.hostedInvoiceCheckout;
const card = document.querySelector('[data-checkout-card]');
const sections = document.querySelectorAll('[data-checkout-state]');
const announcer = document.getElementById('checkout-status-announcer');

let invoice = { ...(config?.invoice || {}) };
let pollTimer = null;
let countdownTimer = null;
let redirectTimer = null;
let redirectSecondsLeft = REDIRECT_SECONDS;

const els = {
    invoiceIds: document.querySelectorAll('[data-invoice-id]'),
    timers: document.querySelectorAll('[data-countdown]'),
    methodButtons: document.querySelectorAll('[data-select-asset]'),
    assetFeedback: document.querySelector('[data-asset-feedback]'),
    qrBoxes: document.querySelectorAll('[data-qr-box]'),
    fiatAmounts: document.querySelectorAll('[data-fiat-amount]'),
    cryptoAmounts: document.querySelectorAll('[data-crypto-amount]'),
    networkLabels: document.querySelectorAll('[data-network-label]'),
    assetSymbols: document.querySelectorAll('[data-asset-symbol]'),
    addresses: document.querySelectorAll('[data-address]'),
    paymentUris: document.querySelectorAll('[data-payment-uri]'),
    receivedAmounts: document.querySelectorAll('[data-received-amount]'),
    remainingAmounts: document.querySelectorAll('[data-remaining-amount]'),
    paidAmounts: document.querySelectorAll('[data-paid-amount]'),
    partialExpiredText: document.querySelector('[data-expired-partial-text]'),
    copyFeedback: document.querySelector('[data-copy-feedback]'),
    openWalletLinks: document.querySelectorAll('[data-open-wallet]'),
    returnLinks: document.querySelectorAll('[data-return-link]'),
    redirectNotes: document.querySelectorAll('[data-redirect-note]'),
    closeButton: document.querySelector('[data-close-checkout]'),
    copyButtons: document.querySelectorAll('[data-copy-kind]'),
};

function numeric(value) {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatNumber(value, decimals = 8) {
    return numeric(value).toFixed(decimals).replace(/\.?0+$/, '') || '0';
}

function formatFiat(value) {
    return `$${numeric(value).toFixed(2)}`;
}

function coinLabel(data = invoice) {
    return (data.coin || '').toUpperCase();
}

function cryptoAmountLabel(value, data = invoice) {
    const coin = coinLabel(data);
    return coin ? `${formatNumber(value)} ${coin}` : formatNumber(value);
}

function requiredAmount(data = invoice) {
    return numeric(data.amount_coin);
}

function receivedAmount(data = invoice) {
    return numeric(data.received_all_coin);
}

function remainingAmount(data = invoice) {
    return Math.max(requiredAmount(data) - receivedAmount(data), 0);
}

function checkoutState(data = invoice) {
    const status = (data.status || '').toLowerCase();
    const received = receivedAmount(data);
    const required = requiredAmount(data);

    if (status === 'paid') return 'complete';
    if (status === 'expired') return received > 0 && required > 0 && received < required ? 'expired-partial' : 'expired';
    if (!data.asset_key || status === 'awaiting_asset') return 'choose-method';
    if (received <= 0) return 'awaiting-payment';
    if (required > 0 && received < required) return 'partial';

    return 'confirming';
}

function setText(targets, value) {
    targets.forEach((target) => {
        target.textContent = value;
    });
}

function setState(state) {
    sections.forEach((section) => {
        section.hidden = section.dataset.checkoutState !== state;
    });

    card.dataset.activeState = state;
    card.classList.toggle('is-payment', ['awaiting-payment', 'partial'].includes(state));

    if (announcer) {
        announcer.textContent = state.replace('-', ' ');
    }
}

function isTerminalState(state) {
    return ['complete', 'expired', 'expired-partial'].includes(state);
}

function applyTerminalGuards(state) {
    const terminal = isTerminalState(state);

    els.methodButtons.forEach((button) => {
        button.disabled = terminal;
        button.classList.remove('is-loading');
    });

    els.copyButtons.forEach((button) => {
        button.disabled = terminal;
    });

    if (terminal) {
        els.openWalletLinks.forEach((link) => {
            link.hidden = true;
            link.href = '#';
        });

        els.qrBoxes.forEach((box) => {
            box.replaceChildren();
            box.textContent = '';
        });
    }
}

function renderStaticFields() {
    const publicId = invoice.public_id || '';
    const fiat = formatFiat(invoice.expected_usd);
    const crypto = cryptoAmountLabel(invoice.amount_coin);
    const received = cryptoAmountLabel(invoice.received_all_coin);
    const remaining = cryptoAmountLabel(remainingAmount());
    const network = invoice.network_label || invoice.network_key || '';
    const assetSymbol = coinLabel() || 'this asset';
    const address = invoice.pay_address || '';
    const paymentUri = invoice.payment_uri || '';

    setText(els.invoiceIds, publicId);
    setText(els.fiatAmounts, fiat);
    setText(els.cryptoAmounts, crypto);
    setText(els.networkLabels, network);
    setText(els.assetSymbols, assetSymbol);
    setText(els.addresses, address);
    setText(els.paymentUris, paymentUri || 'Unavailable');
    setText(els.receivedAmounts, received);
    setText(els.remainingAmounts, remaining);
    setText(els.paidAmounts, crypto);

    els.openWalletLinks.forEach((link) => {
        const canOpenWallet = invoice.payment_mode === 'utxo' && paymentUri;
        link.hidden = !canOpenWallet;
        link.href = canOpenWallet ? paymentUri : '#';
    });

    els.returnLinks.forEach((link) => {
        const url = config.redirects?.complete_url || config.redirects?.return_url;
        link.hidden = !url;
        link.href = url || '#';
    });

    if (els.closeButton) {
        const closeUrl = config.redirects?.cancel_url || config.redirects?.return_url;
        els.closeButton.hidden = !closeUrl;
        els.closeButton.onclick = () => {
            window.location.assign(closeUrl);
        };
    }

    if (els.partialExpiredText) {
        els.partialExpiredText.textContent = `Received ${received}. Contact the merchant with this invoice ID.`;
    }
}

async function renderQr(payload) {
    if (!payload) return;

    const alt = `Payment QR code for ${cryptoAmountLabel(invoice.amount_coin)}`;
    const dataUrl = await QRCode.toDataURL(payload, { width: 176, margin: 1 });

    els.qrBoxes.forEach((box) => {
        box.innerHTML = `<img src="${dataUrl}" alt="${alt}">`;
    });
}

function render() {
    const state = checkoutState();

    renderStaticFields();
    setState(state);
    applyTerminalGuards(state);

    if (['awaiting-payment', 'partial'].includes(state)) {
        renderQr(invoice.payment_mode === 'utxo' ? invoice.payment_uri || invoice.pay_address : invoice.pay_address);
    }

    if (['complete', 'expired', 'expired-partial'].includes(state)) {
        stopPolling();
    } else if (state !== 'choose-method') {
        startPolling();
    }

    if (state === 'complete') {
        startRedirectCountdown();
    }
}

function updateCountdown() {
    if (!invoice.expires_at) {
        setText(els.timers, '—');
        return;
    }

    const diffMs = new Date(invoice.expires_at).getTime() - Date.now();
    if (diffMs <= 0) {
        setText(els.timers, 'Expired');
        return;
    }

    const totalSeconds = Math.floor(diffMs / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    setText(els.timers, `${minutes}:${String(seconds).padStart(2, '0')}`);
}

function startCountdown() {
    updateCountdown();
    countdownTimer = window.setInterval(updateCountdown, 1000);
}

function stopCountdown() {
    if (countdownTimer) {
        window.clearInterval(countdownTimer);
        countdownTimer = null;
    }
}

async function refreshStatus() {
    const response = await fetch(config.statusUrl, { headers: { Accept: 'application/json' } });
    if (!response.ok) return;

    const json = await response.json();
    invoice = { ...invoice, ...(json.data || {}) };
    render();
}

function startPolling() {
    if (pollTimer) return;
    pollTimer = window.setInterval(refreshStatus, POLL_INTERVAL_MS);
}

function stopPolling() {
    if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function selectAsset(assetKey, button) {
    if (isTerminalState(checkoutState())) return;

    els.methodButtons.forEach((item) => {
        item.disabled = true;
        item.classList.toggle('is-loading', item === button);
    });

    if (els.assetFeedback) {
        els.assetFeedback.textContent = 'Preparing payment details...';
        els.assetFeedback.className = 'copy-feedback';
    }

    try {
        const response = await fetch(config.selectAssetUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
            },
            body: JSON.stringify({ asset_key: assetKey }),
        });

        const json = await response.json();
        if (!response.ok || !json.success) {
            throw new Error(json.message || 'Unable to select payment method.');
        }

        invoice = { ...invoice, ...(json.data || {}) };
        if (els.assetFeedback) {
            els.assetFeedback.textContent = '';
        }
        render();
    } catch (error) {
        if (els.assetFeedback) {
            els.assetFeedback.textContent = error.message || 'Unable to select payment method.';
            els.assetFeedback.className = 'copy-feedback error';
        }

        els.methodButtons.forEach((item) => {
            item.disabled = false;
            item.classList.remove('is-loading');
        });
        button?.focus();
    }
}

async function copyValue(value, button) {
    const text = String(value || '').trim();
    if (!text) return;

    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
    } else {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    }

    const originalText = button.textContent;
    button.textContent = 'Copied';
    if (els.copyFeedback) {
        els.copyFeedback.textContent = 'Copied.';
        els.copyFeedback.className = 'copy-feedback';
    }

    window.setTimeout(() => {
        button.textContent = originalText;
    }, 1200);
}

function copyPayload(kind) {
    if (kind === 'address') return invoice.pay_address || '';
    if (kind === 'amount') return formatNumber(invoice.amount_coin);
    if (kind === 'remaining') return formatNumber(remainingAmount());
    if (kind === 'payment_uri') return invoice.payment_uri || '';

    return '';
}

function startRedirectCountdown() {
    const url = config.redirects?.complete_url;
    if (!url || redirectTimer) return;

    redirectSecondsLeft = REDIRECT_SECONDS;
    els.redirectNotes.forEach((note) => {
        note.hidden = false;
        note.textContent = `Redirecting in ${redirectSecondsLeft} seconds`;
    });

    redirectTimer = window.setInterval(() => {
        redirectSecondsLeft -= 1;
        els.redirectNotes.forEach((note) => {
            note.textContent = `Redirecting in ${redirectSecondsLeft} seconds`;
        });

        if (redirectSecondsLeft <= 0) {
            window.clearInterval(redirectTimer);
            window.location.assign(url);
        }
    }, 1000);
}

els.methodButtons.forEach((button) => {
    button.addEventListener('click', () => selectAsset(button.dataset.selectAsset, button));
});

document.querySelectorAll('[data-copy-kind]').forEach((button) => {
    button.addEventListener('click', () => copyValue(copyPayload(button.dataset.copyKind), button));
});

document.addEventListener('DOMContentLoaded', () => {
    render();
    startCountdown();

    if (checkoutState() !== 'choose-method') {
        startPolling();
    }
});

window.addEventListener('beforeunload', () => {
    stopPolling();
    stopCountdown();
});
