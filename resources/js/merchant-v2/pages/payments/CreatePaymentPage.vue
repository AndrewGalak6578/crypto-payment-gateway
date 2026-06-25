<template>
    <section class="page-stack">
        <header class="page-header">
            <div>
                <p class="page-kicker">Checkout link</p>
                <h2 class="page-title">Create payment link</h2>
                <p class="page-subtitle">Create a hosted checkout where the payer can choose an asset, or lock the asset now.</p>
            </div>
            <RouterLink class="btn btn-secondary" :to="{ name: 'merchant-v2.payments' }">Back to payments</RouterLink>
        </header>

        <div v-if="success" class="alert alert-success">{{ success }}</div>
        <div v-if="formError" class="alert alert-danger">{{ formError }}</div>

        <section class="create-layout">
            <form class="card card-pad create-form" @submit.prevent="submit">
                <section class="form-section">
                    <h3 class="card-title">Amount</h3>
                    <p class="card-subtitle">Fiat amount is the business source of truth.</p>
                    <label class="field">
                        <span class="field-label">Amount USD</span>
                        <input v-model="form.amount_usd" class="input" type="number" min="0.01" step="0.01" required />
                        <span v-if="fieldErrors.amount_usd" class="field-error">{{ fieldErrors.amount_usd }}</span>
                    </label>
                </section>

                <section class="form-section">
                    <h3 class="card-title">Payment method behavior</h3>
                    <p class="card-subtitle">Universal checkout lets the payer choose a supported crypto asset on the hosted page.</p>
                    <label class="choice-card">
                        <input v-model="form.payer_can_choose_asset" type="radio" :value="true" />
                        <span>
                            <strong>Let payer choose asset</strong>
                            <small>Creates an invoice without a coin until the payer selects one.</small>
                        </span>
                    </label>
                    <label class="choice-card">
                        <input v-model="form.payer_can_choose_asset" type="radio" :value="false" />
                        <span>
                            <strong>Lock asset now</strong>
                            <small>Generate payment address immediately for the selected asset.</small>
                        </span>
                    </label>

                    <label v-if="!form.payer_can_choose_asset" class="field">
                        <span class="field-label">Asset</span>
                        <select v-model="form.coin" class="select" required>
                            <option v-for="asset in assetOptions" :key="asset.assetKey" :value="asset.assetKey">
                                {{ asset.assetLabel }} · {{ asset.networkLabel }}
                            </option>
                        </select>
                    </label>
                </section>

                <section class="form-section">
                    <h3 class="card-title">Expiration</h3>
                    <label class="field">
                        <span class="field-label">Expires minutes</span>
                        <input v-model="form.expires_minutes" class="input" type="number" min="1" max="10080" placeholder="10080" />
                        <p class="field-help">Default is handled by the backend when empty.</p>
                    </label>
                </section>

                <section class="form-section">
                    <h3 class="card-title">Redirect URLs</h3>
                    <label class="field">
                        <span class="field-label">Success URL</span>
                        <input v-model.trim="form.success_url" class="input" type="url" placeholder="https://merchant.example/orders/123/success" />
                    </label>
                    <label class="field">
                        <span class="field-label">Cancel URL</span>
                        <input v-model.trim="form.cancel_url" class="input" type="url" placeholder="https://merchant.example/orders/123/cancel" />
                    </label>
                </section>

                <section class="form-section">
                    <h3 class="card-title">Merchant metadata</h3>
                    <label class="field">
                        <span class="field-label">External ID</span>
                        <input v-model.trim="form.external_id" class="input" maxlength="120" placeholder="order-1001" />
                    </label>
                    <label class="field">
                        <span class="field-label">Additional metadata JSON</span>
                        <textarea v-model="form.metadata_json" class="textarea" placeholder='{"customer_email":"payer@example.com"}'></textarea>
                    </label>
                </section>

                <div class="page-actions">
                    <button class="btn btn-primary" type="submit" :disabled="submitting">
                        {{ submitting ? 'Creating...' : 'Create link' }}
                    </button>
                    <button class="btn btn-secondary" type="button" @click="resetForm">Reset</button>
                </div>
            </form>

            <aside class="card card-pad preview-panel">
                <h3 class="card-title">Checkout preview</h3>
                <p class="card-subtitle">What the payer will receive.</p>

                <div class="preview-card">
                    <p class="page-kicker">Merchant checkout</p>
                    <h4>Pay ${{ Number(form.amount_usd || 0).toFixed(2) }}</h4>
                    <p>{{ form.payer_can_choose_asset ? 'Payer chooses crypto asset on the checkout page.' : `Locked to ${form.coin.toUpperCase()}.` }}</p>
                </div>

                <div class="detail-list">
                    <div class="detail-row">
                        <span class="detail-label">Hosted link</span>
                        <span class="detail-value">{{ createdInvoice?.hosted_url || 'Generated after creation' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Redirects</span>
                        <span class="detail-value">{{ redirectSummary }}</span>
                    </div>
                </div>

                <div v-if="createdInvoice" class="page-actions">
                    <button class="btn btn-secondary" type="button" @click="copy(createdInvoice.hosted_url, 'Hosted link copied.')">Copy link</button>
                    <a class="btn btn-primary" :href="createdInvoice.hosted_url" target="_blank" rel="noopener noreferrer">Open checkout</a>
                </div>
            </aside>
        </section>
    </section>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { merchantApi } from '../../services/merchantApi';
import { MERCHANT_ASSET_CATALOG } from '../../../utils/merchantAssetCatalog';
import { useCopy } from '../../composables/useCopy';

const { copy } = useCopy();
const submitting = ref(false);
const formError = ref('');
const success = ref('');
const createdInvoice = ref(null);
const fieldErrors = reactive({});

const initialForm = () => ({
    payer_can_choose_asset: true,
    coin: MERCHANT_ASSET_CATALOG[0]?.assetKey || 'btc',
    amount_usd: '10.00',
    expires_minutes: '',
    success_url: '',
    cancel_url: '',
    external_id: '',
    metadata_json: '',
});

const form = reactive(initialForm());
const assetOptions = MERCHANT_ASSET_CATALOG;
const redirectSummary = computed(() => [form.success_url && 'success', form.cancel_url && 'cancel'].filter(Boolean).join(', ') || 'None');

const resetForm = () => {
    Object.assign(form, initialForm());
    createdInvoice.value = null;
    formError.value = '';
    success.value = '';
    Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key]);
};

const parseMetadata = () => {
    const metadata = {};
    if (form.success_url || form.cancel_url) {
        metadata.redirects = {
            success_url: form.success_url || undefined,
            cancel_url: form.cancel_url || undefined,
        };
    }

    const raw = form.metadata_json.trim();
    if (raw) {
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
            throw new Error('Metadata must be a JSON object.');
        }
        Object.assign(metadata, parsed);
    }

    return Object.keys(metadata).length ? metadata : undefined;
};

const extractErrors = (requestError) => {
    Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key]);
    const errors = requestError?.response?.data?.errors;
    if (errors && typeof errors === 'object') {
        Object.entries(errors).forEach(([key, messages]) => {
            fieldErrors[key] = Array.isArray(messages) ? messages[0] : String(messages);
        });
    }

    return requestError?.response?.data?.message || requestError?.response?.data?.error || 'Failed to create payment link.';
};

const submit = async () => {
    submitting.value = true;
    formError.value = '';
    success.value = '';
    createdInvoice.value = null;

    try {
        const payload = {
            amount_usd: Number(form.amount_usd),
            coin: form.payer_can_choose_asset ? null : form.coin,
            external_id: form.external_id || undefined,
            expires_minutes: form.expires_minutes ? Number(form.expires_minutes) : undefined,
            metadata: parseMetadata(),
        };

        const response = await merchantApi.createPayment(payload);
        createdInvoice.value = response.data?.data || null;
        success.value = 'Payment link created.';
    } catch (error) {
        formError.value = error instanceof SyntaxError ? 'Metadata JSON is invalid.' : extractErrors(error);
    } finally {
        submitting.value = false;
    }
};
</script>

<style scoped>
.create-layout {
    display: grid;
    gap: 20px;
}

.create-form,
.form-section {
    display: grid;
    gap: 16px;
}

.form-section {
    padding-bottom: 18px;
    border-bottom: 1px solid var(--m-border);
}

.choice-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 12px;
    padding: 12px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
}

.choice-card small {
    display: block;
    margin-top: 3px;
    color: var(--m-muted);
}

.preview-panel {
    align-self: start;
    display: grid;
    gap: 16px;
}

.preview-card {
    padding: 18px;
    border-radius: var(--m-radius-xl);
    background: var(--m-brand-50);
}

.preview-card h4 {
    margin: 0;
    font-size: 24px;
}

@media (min-width: 1024px) {
    .create-layout {
        grid-template-columns: minmax(0, 1fr) 380px;
        align-items: start;
    }

    .preview-panel {
        position: sticky;
        top: calc(var(--m-topbar-height) + 24px);
    }
}
</style>
