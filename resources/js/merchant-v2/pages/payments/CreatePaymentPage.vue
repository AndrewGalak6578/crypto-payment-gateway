<template>
    <section class="page-stack create-payment-page">
        <header class="page-header">
            <div>
                <p class="page-kicker">Checkout link</p>
                <h2 class="page-title">Create payment link</h2>
                <p class="page-subtitle">Compose a hosted checkout link with asset behavior, redirects, metadata, and expiration.</p>
            </div>
            <RouterLink class="btn btn-secondary create-desktop-back" :to="{ name: 'merchant-v2.payments' }">Back to payments</RouterLink>
        </header>

        <div v-if="success" class="alert alert-success">{{ success }}</div>
        <div v-if="formError" class="alert alert-danger">{{ formError }}</div>

        <section v-if="canCreateInvoices" class="mobile-create-overview" aria-label="Payment link summary">
            <div>
                <span>Amount</span>
                <strong>${{ amountPreview }}</strong>
            </div>
            <div>
                <span>Method</span>
                <strong>{{ form.payer_can_choose_asset ? 'Payer chooses' : selectedAsset?.symbol }}</strong>
            </div>
            <div>
                <span>Expires</span>
                <strong>{{ expirationSummary }}</strong>
            </div>
        </section>

        <section v-if="!canCreateInvoices" class="card card-pad permission-panel">
            <h3 class="card-title">Create access required</h3>
            <p class="card-subtitle">Your role can view payments, but cannot create new checkout links.</p>
        </section>

        <section v-else class="create-layout">
            <form id="create-payment-form" class="card card-pad create-form" @submit.prevent="submit">
                <section class="form-section form-section-hero">
                    <div>
                        <span class="section-step">01</span>
                        <h3 class="card-title">Amount</h3>
                        <p class="card-subtitle">Fiat amount is the business source of truth.</p>
                    </div>
                    <label class="field amount-field">
                        <span class="field-label">Amount USD</span>
                        <input
                            v-model="form.amount_usd"
                            class="input amount-input"
                            type="number"
                            :min="checkoutDefaults?.min_amount_usd || 0.01"
                            :max="checkoutDefaults?.max_amount_usd || undefined"
                            step="0.01"
                            required
                        />
                        <span v-if="fieldErrors.amount_usd" class="field-error">{{ fieldErrors.amount_usd }}</span>
                    </label>
                </section>

                <section class="form-section">
                    <div class="section-heading">
                        <span class="section-step">02</span>
                        <div>
                            <h3 class="card-title">Payment method behavior</h3>
                            <p class="card-subtitle">Choose whether the payer selects an asset or the checkout is locked now.</p>
                        </div>
                    </div>

                    <div class="choice-grid">
                        <label class="choice-card" :class="{ 'is-selected': form.payer_can_choose_asset }">
                            <input v-model="form.payer_can_choose_asset" type="radio" :value="true" />
                            <span>
                                <strong>Payer chooses asset</strong>
                                <small>No address is allocated until the hosted checkout selection.</small>
                            </span>
                        </label>
                        <label class="choice-card" :class="{ 'is-selected': !form.payer_can_choose_asset }">
                            <input v-model="form.payer_can_choose_asset" type="radio" :value="false" />
                            <span>
                                <strong>Lock asset now</strong>
                                <small>Generate a payment address immediately for one selected asset.</small>
                            </span>
                        </label>
                    </div>

                    <div class="asset-picker">
                        <button
                            v-for="asset in assetOptions"
                            :key="asset.assetKey"
                            class="asset-option"
                            :class="{ 'is-selected': form.coin === asset.assetKey }"
                            type="button"
                            :disabled="form.payer_can_choose_asset"
                            @click="form.coin = asset.assetKey"
                        >
                            <AssetLogo :item="{ asset_key: asset.assetKey }" />
                            <span>
                                <strong>{{ asset.symbol }}</strong>
                                <small>{{ asset.networkLabel }}</small>
                            </span>
                        </button>
                    </div>
                    <p class="field-help">{{ form.payer_can_choose_asset ? 'All supported assets are shown on hosted checkout.' : 'The payer will only see the selected asset.' }}</p>
                </section>

                <section class="form-section">
                    <div class="section-heading">
                        <span class="section-step">03</span>
                        <div>
                            <h3 class="card-title">Expiration and redirects</h3>
                            <p class="card-subtitle">Keep checkout validity explicit and return payers to your product.</p>
                        </div>
                    </div>
                    <div class="field-grid">
                        <label class="field">
                            <span class="field-label">Expires minutes</span>
                            <input v-model="form.expires_minutes" class="input" type="number" min="1" max="240" placeholder="Backend default" />
                            <p class="field-help">Maximum 240 minutes.</p>
                            <span v-if="fieldErrors.expires_minutes" class="field-error">{{ fieldErrors.expires_minutes }}</span>
                        </label>
                        <label class="field">
                            <span class="field-label">External ID</span>
                            <input v-model.trim="form.external_id" class="input" maxlength="120" placeholder="order-1001" />
                            <span v-if="fieldErrors.external_id" class="field-error">{{ fieldErrors.external_id }}</span>
                        </label>
                    </div>
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
                    <div class="section-heading">
                        <span class="section-step">04</span>
                        <div>
                            <h3 class="card-title">Merchant metadata</h3>
                            <p class="card-subtitle">Attach safe JSON for reconciliation and order lookup.</p>
                        </div>
                    </div>
                    <textarea v-model="form.metadata_json" class="textarea metadata-textarea" placeholder='{"customer_email":"payer@example.com"}'></textarea>
                    <span v-if="fieldErrors.metadata" class="field-error">{{ fieldErrors.metadata }}</span>
                </section>

                <div class="create-actions">
                    <button class="btn btn-primary" type="submit" :disabled="submitting">
                        {{ submitting ? 'Creating...' : 'Create payment link' }}
                    </button>
                    <button class="btn btn-secondary" type="button" @click="resetForm">Reset</button>
                </div>
            </form>

            <aside class="card card-pad preview-panel">
                <div class="preview-header">
                    <div>
                        <h3 class="card-title">Checkout preview</h3>
                        <p class="card-subtitle">Live payer-facing summary.</p>
                    </div>
                    <span class="preview-state">{{ createdInvoice ? 'Created' : 'Draft' }}</span>
                </div>

                <div class="checkout-preview-card">
                    <p class="page-kicker">Merchant checkout</p>
                    <h4>Pay ${{ amountPreview }}</h4>
                    <p>{{ methodPreview }}</p>
                    <div class="preview-asset-row">
                        <AssetLogo :item="{ asset_key: selectedAsset?.assetKey || 'dash' }" />
                        <span>{{ assetPreview }}</span>
                    </div>
                </div>

                <div class="detail-list">
                    <div class="detail-row">
                        <span class="detail-label">Hosted link</span>
                        <span class="detail-value">{{ createdInvoice?.hosted_url || 'Generated after creation' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Expiration</span>
                        <span class="detail-value">{{ expirationSummary }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Redirects</span>
                        <span class="detail-value">{{ redirectSummary }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Metadata</span>
                        <span class="detail-value">{{ metadataSummary }}</span>
                    </div>
                </div>

                <div v-if="createdInvoice" class="created-panel">
                    <strong>Payment link created</strong>
                    <p>{{ createdInvoice.public_id }}</p>
                    <div class="page-actions">
                        <button class="btn btn-secondary" type="button" @click="copy(createdInvoice.hosted_url, 'Hosted link copied.')">Copy link</button>
                        <a class="btn btn-primary" :href="createdInvoice.hosted_url" target="_blank" rel="noopener noreferrer">Open checkout</a>
                    </div>
                </div>
            </aside>

            <nav class="create-mobile-dock" aria-label="Create payment actions">
                <button class="btn btn-secondary" type="button" @click="resetForm">Reset</button>
                <button class="btn btn-primary" type="submit" form="create-payment-form" :disabled="submitting">
                    {{ submitting ? 'Creating...' : 'Create link' }}
                </button>
            </nav>
        </section>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useAuthStore } from '../../../stores/auth';
import { merchantApi } from '../../services/merchantApi';
import { MERCHANT_ASSET_CATALOG } from '../../../utils/merchantAssetCatalog';
import { useCopy } from '../../composables/useCopy';
import AssetLogo from '../../components/payments/AssetLogo.vue';

const { copy } = useCopy();
const authStore = useAuthStore();
const submitting = ref(false);
const formError = ref('');
const success = ref('');
const createdInvoice = ref(null);
const checkoutDefaults = ref(null);
const fieldErrors = reactive({});
const canCreateInvoices = computed(() => authStore.hasCapability('invoices.write'));
const allAssetOptions = MERCHANT_ASSET_CATALOG.filter((asset) => asset.assetKey);
const assetOptions = computed(() => {
    const effectiveAllowed = checkoutDefaults.value?.effective_allowed_assets;
    if (Array.isArray(effectiveAllowed)) {
        return allAssetOptions.filter((asset) => effectiveAllowed.includes(asset.assetKey));
    }

    const allowed = checkoutDefaults.value?.allowed_assets || [];
    if (!Array.isArray(allowed) || allowed.length === 0) return allAssetOptions;
    return allAssetOptions.filter((asset) => allowed.includes(asset.assetKey));
});

const initialForm = () => ({
    payer_can_choose_asset: checkoutDefaults.value?.payer_can_choose_asset !== false,
    coin: checkoutDefaults.value?.default_asset || assetOptions.value[0]?.assetKey || '',
    amount_usd: '10.00',
    expires_minutes: checkoutDefaults.value?.expires_minutes || '',
    success_url: checkoutDefaults.value?.success_url || '',
    cancel_url: checkoutDefaults.value?.cancel_url || '',
    external_id: '',
    metadata_json: '',
});

const form = reactive(initialForm());
const selectedAsset = computed(() => assetOptions.value.find((asset) => asset.assetKey === form.coin) || assetOptions.value[0]);
const amountPreview = computed(() => Number(form.amount_usd || 0).toFixed(2));
const methodPreview = computed(() => (
    form.payer_can_choose_asset
        ? 'Payer chooses a supported crypto asset on the checkout page.'
        : `Locked to ${selectedAsset.value?.symbol || form.coin.toUpperCase()}.`
));
const assetPreview = computed(() => (
    form.payer_can_choose_asset
        ? `${assetOptions.value.length} supported assets`
        : `${selectedAsset.value?.assetLabel || form.coin} · ${selectedAsset.value?.networkLabel || 'Selected network'}`
));
const redirectSummary = computed(() => [form.success_url && 'success', form.cancel_url && 'cancel'].filter(Boolean).join(', ') || 'None');
const expirationSummary = computed(() => (form.expires_minutes ? `${form.expires_minutes} minutes` : 'Backend default'));
const metadataSummary = computed(() => {
    const count = [
        form.success_url || form.cancel_url ? 'redirects' : '',
        form.metadata_json.trim() ? 'custom JSON' : '',
    ].filter(Boolean).length;

    return count ? `${count} block${count === 1 ? '' : 's'}` : 'None';
});

const resetForm = () => {
    Object.assign(form, initialForm());
    createdInvoice.value = null;
    formError.value = '';
    success.value = '';
    Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key]);
};

const loadCheckoutDefaults = async () => {
    try {
        const response = await merchantApi.settings();
        checkoutDefaults.value = response.data?.data?.checkout || null;
        Object.assign(form, initialForm());
        if (!assetOptions.value.some((asset) => asset.assetKey === form.coin)) {
            form.coin = assetOptions.value[0]?.assetKey || 'btc';
        }
    } catch {
        checkoutDefaults.value = null;
    }
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
    if (!canCreateInvoices.value) {
        formError.value = 'Your role cannot create payment links.';
        return;
    }

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

onMounted(loadCheckoutDefaults);
</script>

<style scoped>
.create-payment-page {
    gap: 20px;
}

.mobile-create-overview,
.create-mobile-dock {
    display: none;
}

.permission-panel {
    max-width: 720px;
}

.create-layout {
    display: grid;
    gap: 20px;
}

.create-form,
.form-section {
    display: grid;
    gap: 18px;
}

.form-section {
    padding-bottom: 20px;
    border-bottom: 1px solid var(--m-border);
}

.form-section:last-of-type {
    border-bottom: 0;
}

.form-section-hero {
    grid-template-columns: minmax(0, 0.7fr) minmax(220px, 0.3fr);
    align-items: end;
}

.section-heading {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.section-step {
    min-width: 30px;
    height: 30px;
    border-radius: var(--m-radius-pill);
    display: inline-grid;
    place-items: center;
    background: var(--m-brand-50);
    color: var(--m-brand-700);
    font-size: 11px;
    font-weight: 850;
}

.amount-field {
    align-self: stretch;
}

.amount-input {
    min-height: 50px;
    font-size: 24px;
    font-weight: 800;
}

.choice-grid {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.choice-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 12px;
    padding: 14px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface);
}

.choice-card.is-selected {
    border-color: var(--m-brand-100);
    background: var(--m-brand-50);
}

.choice-card small {
    display: block;
    margin-top: 4px;
    color: var(--m-muted);
    line-height: 1.4;
}

.asset-picker {
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}

.asset-option {
    min-height: 58px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface);
    color: var(--m-text);
    display: grid;
    grid-template-columns: 30px 1fr;
    gap: 10px;
    align-items: center;
    padding: 10px;
    text-align: left;
}

.asset-option.is-selected {
    border-color: var(--m-brand-100);
    background: var(--m-brand-50);
}

.asset-option:disabled {
    cursor: not-allowed;
}

.asset-option strong,
.asset-option small {
    display: block;
}

.asset-option small {
    margin-top: 2px;
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.field-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.metadata-textarea {
    min-height: 120px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.create-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.preview-panel {
    align-self: start;
    display: grid;
    gap: 16px;
}

.preview-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
}

.preview-state {
    min-height: 28px;
    border-radius: var(--m-radius-pill);
    display: inline-flex;
    align-items: center;
    padding: 0 10px;
    background: var(--m-surface-hover);
    color: var(--m-muted);
    font-size: var(--m-xs);
    font-weight: 800;
}

.checkout-preview-card {
    padding: 18px;
    border-radius: var(--m-radius-xl);
    border: 1px solid var(--m-border);
    background:
        linear-gradient(135deg, rgba(36, 107, 254, 0.08), rgba(46, 144, 250, 0.03)),
        var(--m-surface-subtle);
}

.checkout-preview-card h4 {
    margin: 0;
    color: var(--m-text);
    font-size: 30px;
    line-height: 1.1;
    font-weight: 850;
}

.checkout-preview-card p:not(.page-kicker) {
    margin: 9px 0 0;
    color: var(--m-muted);
    font-size: var(--m-sm);
    line-height: 1.45;
}

.preview-asset-row {
    margin-top: 16px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--m-text);
    font-size: var(--m-sm);
    font-weight: 800;
}

.created-panel {
    display: grid;
    gap: 10px;
    padding: 14px;
    border: 1px solid #abefc6;
    border-radius: var(--m-radius-lg);
    background: var(--m-success-50);
}

.created-panel strong {
    color: var(--m-success-700);
}

.created-panel p {
    margin: 0;
    color: var(--m-muted);
    font-size: var(--m-xs);
}

@media (min-width: 1024px) {
    .create-layout {
        grid-template-columns: minmax(0, 1fr) 400px;
        align-items: start;
    }

    .preview-panel {
        position: sticky;
        top: calc(var(--m-topbar-height) + 24px);
    }
}

@media (max-width: 760px) {
    .create-payment-page {
        gap: 14px;
        padding-bottom: 78px;
    }

    .create-payment-page .page-header {
        display: grid;
        gap: 8px;
        padding: 2px 2px 0;
    }

    .create-payment-page .page-kicker,
    .create-payment-page .page-subtitle {
        display: none;
    }

    .create-payment-page .page-title {
        font-size: 22px;
        line-height: 1.14;
    }

    .create-desktop-back {
        display: none;
    }

    .mobile-create-overview {
        display: grid;
        grid-template-columns: 1.1fr 1fr 0.9fr;
        gap: 1px;
        overflow: hidden;
        border: 1px solid #dbe7ff;
        border-radius: 18px;
        background: #dbe7ff;
        box-shadow: 0 14px 34px rgba(16, 24, 40, 0.08);
    }

    .mobile-create-overview div {
        min-width: 0;
        padding: 12px 10px;
        background:
            linear-gradient(180deg, rgba(238, 245, 255, 0.95), rgba(255, 255, 255, 0.98)),
            var(--m-surface);
    }

    .mobile-create-overview span {
        display: block;
        color: var(--m-muted);
        font-size: 11px;
        font-weight: 750;
    }

    .mobile-create-overview strong {
        display: block;
        min-width: 0;
        margin-top: 4px;
        color: var(--m-text);
        font-size: 13px;
        line-height: 1.2;
        font-weight: 850;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .form-section-hero,
    .choice-grid,
    .field-grid {
        grid-template-columns: 1fr;
    }

    .form-section {
        gap: 15px;
        padding-bottom: 18px;
    }

    .form-section-hero {
        padding: 2px 0 18px;
    }

    .section-heading {
        display: grid;
        gap: 8px;
    }

    .section-step {
        min-width: 28px;
        width: 28px;
        height: 28px;
    }

    .amount-input {
        min-height: 58px;
        border-radius: 16px;
        font-size: 30px;
        letter-spacing: -0.01em;
    }

    .choice-grid {
        gap: 8px;
    }

    .choice-card,
    .asset-option {
        border-radius: 16px;
    }

    .choice-card {
        min-height: 86px;
        padding: 14px;
        align-items: start;
    }

    .choice-card input {
        margin-top: 2px;
        width: 18px;
        height: 18px;
    }

    .asset-picker {
        display: flex;
        gap: 8px;
        margin: 0 -15px;
        padding: 0 15px 2px;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        scrollbar-width: none;
    }

    .asset-picker::-webkit-scrollbar {
        display: none;
    }

    .asset-option {
        flex: 0 0 156px;
        scroll-snap-align: start;
    }

    .asset-option:disabled {
        opacity: 1;
        background: var(--m-surface-subtle);
    }

    .input,
    .textarea {
        border-radius: 14px;
    }

    .create-form,
    .preview-panel {
        padding: 16px;
        border-radius: 20px;
        box-shadow: 0 10px 28px rgba(16, 24, 40, 0.06);
    }

    .create-actions {
        display: none;
    }

    .created-panel .page-actions {
        display: grid;
        grid-template-columns: 1fr;
        width: 100%;
    }

    .created-panel .btn {
        width: 100%;
    }

    .preview-panel {
        order: 3;
        gap: 14px;
        border-color: #dbe7ff;
        background:
            linear-gradient(180deg, rgba(238, 245, 255, 0.62), rgba(255, 255, 255, 0.98) 46%),
            var(--m-surface);
    }

    .preview-header {
        align-items: center;
    }

    .preview-state {
        min-height: 26px;
    }

    .checkout-preview-card {
        padding: 16px;
        border-radius: 18px;
    }

    .checkout-preview-card h4 {
        font-size: clamp(28px, 9vw, 38px);
    }

    .preview-asset-row {
        display: flex;
        width: 100%;
        padding: 10px;
        border: 1px solid var(--m-border);
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.78);
    }

    .preview-panel .detail-list .detail-row {
        grid-template-columns: 1fr;
        gap: 4px;
        padding: 10px 11px;
    }

    .create-mobile-dock {
        position: fixed;
        inset: auto 12px 68px;
        z-index: 18;
        display: grid;
        grid-template-columns: 0.78fr 1fr;
        gap: 8px;
        padding: 8px;
        border: 1px solid rgba(214, 221, 232, 0.92);
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.94);
        box-shadow: 0 16px 40px rgba(16, 24, 40, 0.16);
        backdrop-filter: blur(16px);
    }

    .create-mobile-dock .btn {
        min-height: 44px;
        border-radius: 13px;
    }
}

@media (max-width: 420px) {
    .mobile-create-overview {
        grid-template-columns: 1fr;
    }

    .mobile-create-overview div {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 12px;
    }

    .mobile-create-overview strong {
        margin-top: 0;
        text-align: right;
    }

    .create-mobile-dock {
        inset-inline: 10px;
        gap: 6px;
        padding: 7px;
    }
}
</style>
