import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    canonicalSettlementRequest,
    sameSettlementDraft,
    settlementDraftFromPolicy,
    validateSettlementDraft,
} from '../services/settlementRulesState.js';

test('builds an inherit draft without converting monetary values', () => {
    const draft = settlementDraftFromPolicy({
        requested: { mode: null, minimum_invoice_payout: null },
    });

    assert.deepEqual(draft, { mode: 'inherit', minimumInvoicePayout: '' });
    assert.deepEqual(canonicalSettlementRequest(draft), {
        mode: null,
        minimum_invoice_payout: null,
    });
});

test('preserves an 18-decimal threshold as an exact string', () => {
    const amount = '0.123456789012345678';
    const request = canonicalSettlementRequest({
        mode: 'threshold',
        minimumInvoicePayout: amount,
    });

    assert.equal(request.minimum_invoice_payout, amount);
    assert.equal(validateSettlementDraft({ mode: 'threshold', minimumInvoicePayout: amount }, 18), null);
});

test('canonicalizes non-threshold modes with a null minimum', () => {
    assert.deepEqual(canonicalSettlementRequest({
        mode: 'disabled',
        minimumInvoicePayout: '99.999999999999999999',
    }), {
        mode: 'disabled',
        minimum_invoice_payout: null,
    });
});

test('rejects exponent notation, zero and excess asset precision', () => {
    assert.match(
        validateSettlementDraft({ mode: 'threshold', minimumInvoicePayout: '1e-18' }, 18),
        /positive decimal/,
    );
    assert.match(
        validateSettlementDraft({ mode: 'threshold', minimumInvoicePayout: '0.000' }, 18),
        /positive decimal/,
    );
    assert.match(
        validateSettlementDraft({ mode: 'threshold', minimumInvoicePayout: '0.123456789' }, 8),
        /at most 8/,
    );
});

test('dirty comparison remains string exact', () => {
    assert.equal(
        sameSettlementDraft(
            { mode: 'threshold', minimumInvoicePayout: '1.0' },
            { mode: 'threshold', minimumInvoicePayout: '1.00' },
        ),
        false,
    );
});

test('page has separate responsive editors and safe product language', async () => {
    const source = await readFile(
        new URL('../pages/settlements/SettlementRulesPage.vue', import.meta.url),
        'utf8',
    );

    assert.match(source, /class="rules-desktop"/);
    assert.match(source, /class="rules-mobile"/);
    assert.match(source, /class="mobile-asset-strip"/);
    assert.match(source, /class="mobile-save-area"/);
    assert.match(
        source,
        /Applied separately to each invoice\. Payments below this amount are held and are not automatically combined\./,
    );
    assert.match(source, /Pause settlements/);
    assert.doesNotMatch(source, /type="number"/);
    assert.doesNotMatch(source, /Payout when balance reaches/i);
    assert.match(source, /overflow-wrap: anywhere/);
    assert.match(source, /width: calc\(100% \+ 24px\)/);
    assert.match(source, /\.mobile-asset-strip \{[\s\S]*min-width: 0/);
    assert.match(source, /if \(saving\.value \|\| !activePolicy\.value/);
});
