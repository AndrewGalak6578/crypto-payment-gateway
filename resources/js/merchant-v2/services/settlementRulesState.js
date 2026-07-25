const REQUESTED_MODES = new Set(['immediate', 'threshold', 'disabled']);

export const settlementPolicyKey = (policy) => `${policy.asset.key}:${policy.asset.network.key}`;

export const settlementDraftFromPolicy = (policy) => ({
    mode: policy?.requested?.mode ?? 'inherit',
    minimumInvoicePayout: policy?.requested?.minimum_invoice_payout ?? '',
});

export const canonicalSettlementRequest = (draft) => {
    const mode = draft?.mode ?? 'inherit';

    if (mode === 'inherit') {
        return { mode: null, minimum_invoice_payout: null };
    }

    if (!REQUESTED_MODES.has(mode)) {
        throw new TypeError(`Unsupported settlement mode: ${mode}`);
    }

    return {
        mode,
        minimum_invoice_payout: mode === 'threshold'
            ? String(draft.minimumInvoicePayout ?? '').trim()
            : null,
    };
};

export const sameSettlementDraft = (left, right) => (
    (left?.mode ?? 'inherit') === (right?.mode ?? 'inherit')
    && String(left?.minimumInvoicePayout ?? '') === String(right?.minimumInvoicePayout ?? '')
);

export const validateSettlementDraft = (draft, scale) => {
    if (draft?.mode !== 'threshold') return null;

    const amount = String(draft.minimumInvoicePayout ?? '').trim();
    if (!/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/.test(amount) || /^0(?:\.0+)?$/.test(amount)) {
        return 'Enter a positive decimal amount without exponent notation.';
    }

    const fraction = amount.includes('.') ? amount.split('.')[1].length : 0;
    if (fraction > scale) {
        return `Amount supports at most ${scale} decimal places.`;
    }

    return null;
};
