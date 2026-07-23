export const paymentStatusMap = {
    awaiting_asset: { label: 'Awaiting asset', tone: 'info' },
    pending: { label: 'Awaiting payment', tone: 'warning' },
    awaiting_payment: { label: 'Awaiting payment', tone: 'warning' },
    fixated: { label: 'Confirming', tone: 'info' },
    confirming: { label: 'Confirming', tone: 'info' },
    paid: { label: 'Paid', tone: 'success' },
    partial: { label: 'Partial', tone: 'warning' },
    underpaid: { label: 'Underpaid', tone: 'purple' },
    expired: { label: 'Expired', tone: 'danger' },
    created: { label: 'Created', tone: 'neutral' },
};

export const forwardStatusMap = {
    none: { label: 'Awaiting settlement', tone: 'warning' },
    processing: { label: 'Processing', tone: 'info' },
    partial: { label: 'Partially settled', tone: 'warning' },
    done: { label: 'Settled', tone: 'success' },
    failed: { label: 'Settlement failed', tone: 'danger' },
    held: { label: 'Held by policy', tone: 'warning' },
    manual: { label: 'Manual settlement', tone: 'warning' },
    needs_reconciliation: { label: 'Reconciliation required', tone: 'danger' },
};

export function normalizePaymentStatus(payment = {}) {
    const status = String(payment.status || '').trim().toLowerCase();
    const required = Number.parseFloat(payment.amount_coin || 0);
    const received = Number.parseFloat(payment.received_all_coin ?? payment.received_conf_coin ?? 0);

    // Terminal backend states are authoritative. Amount math must not downgrade them.
    if (status === 'expired') return 'expired';
    if (status === 'paid') return 'paid';
    if (status === 'awaiting_asset') return 'awaiting_asset';

    if (['pending', 'fixated', 'awaiting_payment', 'confirming'].includes(status) && received > 0 && required > 0 && received < required) {
        return 'partial';
    }

    if (status === 'fixated') return 'confirming';
    if (status === 'confirming') return 'confirming';
    if (status === 'pending') return 'pending';
    if (status === 'awaiting_payment') return 'pending';

    return status || 'created';
}

export function paymentStatusMeta(payment = {}) {
    const normalized = normalizePaymentStatus(payment);
    return paymentStatusMap[normalized] || { label: normalized, tone: 'neutral' };
}

export function forwardStatusMeta(payment = {}) {
    const status = String(payment.forward_status || '').trim().toLowerCase();
    return forwardStatusMap[status] || { label: status || 'Unknown', tone: 'neutral' };
}

export function paymentNextAction(payment = {}) {
    const status = normalizePaymentStatus(payment);

    if (status === 'awaiting_asset') {
        return {
            tone: 'info',
            title: 'Waiting for payer choice',
            body: 'The payer still needs to choose a payment asset on the hosted checkout page.',
            action: 'Copy checkout link',
        };
    }

    if (status === 'pending') {
        return {
            tone: 'warning',
            title: 'Awaiting payment',
            body: 'Payment instructions are active. Share the hosted checkout link if the payer has not opened it.',
            action: 'Copy checkout link',
        };
    }

    if (status === 'partial') {
        return {
            tone: 'warning',
            title: 'Payment is short',
            body: 'The payer sent less than required. The hosted checkout should ask for the remaining amount.',
            action: 'Open details',
        };
    }

    if (status === 'paid') {
        return {
            tone: 'success',
            title: 'Payment complete',
            body: 'Funds were received. Check settlement and webhook delivery if the merchant system is not updated.',
            action: 'Open details',
        };
    }

    if (status === 'expired') {
        return {
            tone: 'danger',
            title: 'Checkout expired',
            body: 'The payer can no longer pay this checkout. Create a new payment link if needed.',
            action: 'Create new link',
        };
    }

    return {
        tone: 'neutral',
        title: 'Review payment',
        body: 'Open full details to inspect lifecycle, metadata, and technical fields.',
        action: 'Open details',
    };
}
