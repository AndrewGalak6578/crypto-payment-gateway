<template>
    <section>
        <header class="page-header">
            <div>
                <h2 class="page-title">Create Test Invoice</h2>
                <p class="page-subtitle">Runs against existing merchant API (`/api/v1/invoices`) using a temporary API key.</p>
            </div>
        </header>

        <div v-if="!canCreateTestInvoice" class="state-card">
            <p class="error">You need both `invoices.read` and `api_keys.write` capabilities to run this flow.</p>
        </div>

        <article v-else class="panel form-panel">
            <form class="invoice-form" @submit.prevent="submitForm">
                <label class="field">
                    <span>Asset</span>
                    <select v-model="form.asset_key" required :disabled="submitting">
                        <option v-for="option in assetOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label class="field">
                    <span>Amount (USD)</span>
                    <input
                        v-model="form.amount_usd"
                        type="number"
                        min="0.01"
                        step="0.01"
                        required
                        placeholder="10.00"
                        :disabled="submitting"
                    />
                </label>

                <label class="field">
                    <span>External ID (optional)</span>
                    <input
                        v-model.trim="form.external_id"
                        type="text"
                        maxlength="120"
                        placeholder="order-1001"
                        :disabled="submitting"
                    />
                </label>

                <label class="field">
                    <span>Expires minutes (optional)</span>
                    <input
                        v-model="form.expires_minutes"
                        type="number"
                        min="1"
                        max="240"
                        step="1"
                        placeholder="60"
                        :disabled="submitting"
                    />
                </label>

                <label class="field field-wide">
                    <span>Metadata JSON (optional)</span>
                    <textarea
                        v-model="form.metadata_json"
                        rows="5"
                        placeholder='{"source":"merchant-portal-test"}'
                        :disabled="submitting"
                    />
                </label>

                <div class="form-actions">
                    <button class="primary-btn" type="submit" :disabled="submitting">
                        {{ submitting ? 'Creating...' : 'Create test invoice' }}
                    </button>
                </div>
            </form>

            <p v-if="formError" class="error form-message">{{ formError }}</p>
            <p v-else-if="formSuccess" class="success form-message">{{ formSuccess }}</p>
            <p v-if="cleanupWarning" class="warning form-message">{{ cleanupWarning }}</p>
            <RouterLink v-if="cleanupKeyId" class="warning-link" to="/merchant/api-keys">
                Open API Keys (temporary key id: {{ cleanupKeyId }})
            </RouterLink>
        </article>

        <article v-if="createdInvoice" class="panel result-panel">
            <h3>Invoice created</h3>
            <dl class="result-grid">
                <dt>public_id</dt>
                <dd>{{ createdInvoice.public_id }}</dd>
                <dt>hosted_url</dt>
                <dd class="break">{{ createdInvoice.hosted_url }}</dd>
            </dl>

            <div class="result-actions">
                <a class="secondary-btn" :href="createdInvoice.hosted_url" target="_blank" rel="noopener noreferrer">
                    Open hosted invoice
                </a>
                <button type="button" class="secondary-btn" @click="copyToClipboard(createdInvoice.hosted_url, 'Hosted link copied.')">
                    Copy hosted link
                </button>
                <RouterLink class="secondary-btn" :to="`/merchant/invoices/${createdInvoice.id}`">
                    Open merchant invoice detail
                </RouterLink>
            </div>
        </article>
    </section>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { useAuthStore } from '../../stores/auth.js';
import {
    createMerchantApiKey,
    createMerchantInvoiceWithToken,
    deleteMerchantApiKey
} from '../../api/merchant.js';
import { MERCHANT_ASSET_CATALOG } from '../../utils/merchantAssetCatalog.js';

const authStore = useAuthStore();
const submitting = ref(false);
const formError = ref('');
const formSuccess = ref('');
const createdInvoice = ref(null);
const cleanupWarning = ref('');
const cleanupKeyId = ref(null);

const form = reactive({
    asset_key: MERCHANT_ASSET_CATALOG[0]?.assetKey || 'btc',
    amount_usd: '10.00',
    external_id: '',
    expires_minutes: '',
    metadata_json: '',
});

const canCreateTestInvoice = computed(() => {
    return authStore.hasCapability('invoices.read') && authStore.hasCapability('api_keys.write');
});

const assetOptions = computed(() => {
    return MERCHANT_ASSET_CATALOG.map((item) => ({
        value: item.assetKey,
        label: `${item.assetLabel} (${item.symbol}) • ${item.assetKey} • ${item.networkLabel}`,
    }));
});

const parseMetadata = () => {
    const raw = form.metadata_json.trim();
    if (!raw) {
        return undefined;
    }

    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
        throw new Error('Metadata must be a JSON object.');
    }

    return parsed;
};

const buildInvoicePayload = () => {
    const payload = {
        coin: form.asset_key,
        amount_usd: Number(form.amount_usd),
    };

    const externalId = form.external_id.trim();
    if (externalId) {
        payload.external_id = externalId;
    }

    const expiresMinutes = form.expires_minutes === '' ? null : Number(form.expires_minutes);
    if (Number.isInteger(expiresMinutes) && expiresMinutes > 0) {
        payload.expires_minutes = expiresMinutes;
    }

    const metadata = parseMetadata();
    if (metadata) {
        payload.metadata = metadata;
    }

    return payload;
};

const extractErrorMessage = (requestError, fallbackMessage) => {
    const validationErrors = requestError?.response?.data?.errors;

    if (validationErrors && typeof validationErrors === 'object') {
        const firstMessage = Object.values(validationErrors).flat()[0];
        if (firstMessage) {
            return firstMessage;
        }
    }

    return requestError?.response?.data?.error || requestError?.response?.data?.message || fallbackMessage;
};

const copyToClipboard = async (value, successMessage) => {
    try {
        await navigator.clipboard.writeText(value);
        formSuccess.value = successMessage;
    } catch {
        formError.value = 'Failed to copy to clipboard.';
    }
};

const submitForm = async () => {
    if (!canCreateTestInvoice.value) {
        return;
    }

    formError.value = '';
    formSuccess.value = '';
    cleanupWarning.value = '';
    cleanupKeyId.value = null;
    createdInvoice.value = null;
    submitting.value = true;

    let tempApiKeyId = null;

    try {
        const keyResponse = await createMerchantApiKey({
            name: `tmp-test-invoice-${Date.now()}`,
        });

        tempApiKeyId = keyResponse.data?.data?.id ?? null;
        const tempToken = keyResponse.data?.data?.token ?? '';
        if (!tempToken) {
            throw new Error('Failed to obtain temporary API token.');
        }

        const payload = buildInvoicePayload();
        const invoiceResponse = await createMerchantInvoiceWithToken(tempToken, payload);
        createdInvoice.value = invoiceResponse.data?.data ?? null;
        formSuccess.value = 'Test invoice created.';
    } catch (requestError) {
        formError.value = extractErrorMessage(requestError, 'Failed to create test invoice.');
    } finally {
        if (tempApiKeyId) {
            try {
                await deleteMerchantApiKey(tempApiKeyId);
            } catch (cleanupError) {
                cleanupWarning.value = 'Temporary API key cleanup failed. Revoke it manually in API Keys.';
                cleanupKeyId.value = tempApiKeyId;
            }
        }

        submitting.value = false;
    }
};
</script>

<style scoped>
.panel,
.state-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
}

.form-panel {
    margin-bottom: 16px;
}

.invoice-form {
    display: grid;
    gap: 12px;
}

.field {
    display: grid;
    gap: 6px;
    color: #334155;
    font-size: 14px;
}

.field input,
.field select,
.field textarea {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    background: #fff;
    color: #0f172a;
}

.field textarea {
    resize: vertical;
}

.form-actions {
    display: flex;
    gap: 8px;
}

.primary-btn,
.secondary-btn {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    color: #0f172a;
    padding: 9px 12px;
    text-decoration: none;
    font: inherit;
    cursor: pointer;
}

.primary-btn {
    border-color: #2563eb;
    background: #2563eb;
    color: #fff;
}

.result-panel h3 {
    margin: 0 0 10px;
}

.result-grid {
    display: grid;
    grid-template-columns: 140px 1fr;
    gap: 8px;
    margin: 0;
}

.result-grid dt {
    color: #64748b;
}

.result-grid dd {
    margin: 0;
}

.break {
    word-break: break-all;
}

.result-actions {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.form-message {
    margin: 12px 0 0;
}

.error {
    color: #b91c1c;
}

.success {
    color: #166534;
}

.warning {
    color: #9a3412;
}

.warning-link {
    display: inline-flex;
    margin-top: 8px;
    color: #9a3412;
    text-decoration: underline;
}

@media (min-width: 900px) {
    .invoice-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .field-wide,
    .form-actions {
        grid-column: 1 / -1;
    }
}
</style>
