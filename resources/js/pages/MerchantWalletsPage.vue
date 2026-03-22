<template>
    <section>
        <header class="page-header">
            <div>
                <h2 class="page-title">Wallets</h2>
                <p class="page-subtitle">Forwarding wallets used for merchant settlements.</p>
            </div>
        </header>

        <article class="panel form-panel">
            <div class="form-header">
                <div>
                    <h3>{{ isEditing ? 'Edit wallet' : 'Configure wallet' }}</h3>
                    <p class="muted">
                        {{
                            isEditing
                                ? 'Coin is fixed for existing wallets. Update the address or fee rate below.'
                                : 'One wallet per coin is supported. Saving an existing coin will overwrite its current configuration.'
                        }}
                    </p>
                </div>
            </div>

            <form class="wallet-form" @submit.prevent="submitForm">
                <label class="field">
                    <span>Coin</span>
                    <select v-model="form.coin" required :disabled="isEditing || submitting">
                        <option v-for="option in coinOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label class="field field-wide">
                    <span>Wallet</span>
                    <input
                        v-model.trim="form.wallet"
                        type="text"
                        required
                        autocomplete="off"
                        placeholder="Enter forwarding wallet address"
                        :disabled="submitting"
                    />
                </label>

                <label class="field">
                    <span>Fee rate</span>
                    <input
                        v-model="form.fee_rate"
                        type="number"
                        min="0"
                        step="any"
                        inputmode="decimal"
                        placeholder="Optional"
                        :disabled="submitting"
                    />
                </label>

                <div class="form-actions">
                    <button type="submit" class="primary-btn" :disabled="submitting">
                        {{ submitting ? 'Saving...' : isEditing ? 'Update wallet' : 'Save wallet' }}
                    </button>
                    <button
                        v-if="isEditing"
                        type="button"
                        class="secondary-btn"
                        :disabled="submitting"
                        @click="resetForm"
                    >
                        Cancel
                    </button>
                </div>
            </form>

            <p v-if="formError" class="error form-message">{{ formError }}</p>
            <p v-else-if="formSuccess" class="success form-message">{{ formSuccess }}</p>
        </article>

        <p v-if="loading" class="muted">Loading wallets...</p>

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadWallets">Retry</button>
        </div>

        <div v-else-if="!wallets.length" class="state-card">
            <p class="muted">No forwarding wallets configured yet.</p>
        </div>

        <div v-else class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Coin</th>
                    <th>Wallet</th>
                    <th>Fee rate</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="wallet in wallets" :key="wallet.id">
                    <td>{{ wallet.coin }}</td>
                    <td class="wallet-cell">{{ wallet.wallet }}</td>
                    <td>{{ wallet.fee_rate ?? '—' }}</td>
                    <td>{{ formatDate(wallet.updated_at) }}</td>
                    <td>
                        <div class="table-actions">
                            <button type="button" class="link-btn" @click="startEdit(wallet)">Edit</button>
                            <button
                                type="button"
                                class="link-btn danger-btn"
                                :disabled="deletingId === wallet.id"
                                @click="removeWallet(wallet)"
                            >
                                {{ deletingId === wallet.id ? 'Deleting...' : 'Delete' }}
                            </button>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import {
    createMerchantWallet,
    updateMerchantWallet,
    getMerchantWallets,
    deleteMerchantWallet
} from "../api/merchant.js";

const coinOptions = [
    { label: 'BTC', value: 'btc' },
    { label: 'LTC', value: 'ltc' },
    { label: 'DASH', value: 'dash' },
];

const loading = ref(true);
const submitting = ref(false);
const deletingId = ref(null);
const error = ref('');
const formError = ref('');
const formSuccess = ref('');
const wallets = ref([]);
const editingWalletId = ref(null);

const form = reactive({
    coin: 'btc',
    wallet: '',
    fee_rate: '',
});

const isEditing = computed(() => editingWalletId.value !== null);

const resetForm = () => {
    editingWalletId.value = null;
    form.coin = coinOptions[0].value;
    form.wallet = '';
    form.fee_rate = '';
    formError.value = '';
    formSuccess.value = '';
};

const formatDate = (dateString) => {
    if (!dateString) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(dateString));
};

const normalizeWallets = (items) => {
    wallets.value = [...items].sort((left, right) => left.coin.localeCompare(right.coin));
};

const loadWallets = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await getMerchantWallets();
        normalizeWallets(Array.isArray(response.data?.data) ? response.data.data : []);
    } catch {
        error.value = 'Failed to load wallets.';
    } finally {
        loading.value = false;
    }
};

const extractErrorMessage = (requestError, fallbackMessage) => {
    const validationErrors = requestError?.response?.data?.errors;

    if (validationErrors && typeof validationErrors === 'object') {
        const firstMessage = Object.values(validationErrors).flat()[0];

        if (firstMessage) {
            return firstMessage;
        }
    }

    return requestError?.response?.data?.message || fallbackMessage;
};

const buildPayload = () => ({
    wallet: form.wallet,
    fee_rate: form.fee_rate === '' ? null : form.fee_rate,
});

const submitForm = async () => {
    formError.value = '';
    formSuccess.value = '';
    submitting.value = true;

    try {
        const successMessage = isEditing.value ? 'Wallet updated.' : 'Wallet saved.';

        if (isEditing.value) {
            await updateMerchantWallet(editingWalletId.value, buildPayload());
        } else {
            await createMerchantWallet({
                coin: form.coin,
                ...buildPayload(),
            });
        }

        await loadWallets();
        resetForm();
        formSuccess.value = successMessage;
    } catch (requestError) {
        formError.value = extractErrorMessage(requestError, 'Failed to save wallet.');
    } finally {
        submitting.value = false;
    }
};

const startEdit = (wallet) => {
    editingWalletId.value = wallet.id;
    form.coin = wallet.coin.toLowerCase();
    form.wallet = wallet.wallet;
    form.fee_rate = wallet.fee_rate ?? '';
    formError.value = '';
    formSuccess.value = '';
};

const removeWallet = async (wallet) => {
    if (!window.confirm(`Delete ${wallet.coin} wallet?`)) {
        return;
    }

    deletingId.value = wallet.id;
    error.value = '';
    formError.value = '';
    formSuccess.value = '';

    try {
        await deleteMerchantWallet(wallet.id);

        if (editingWalletId.value === wallet.id) {
            resetForm();
        }

        await loadWallets();
        formSuccess.value = 'Wallet deleted.';
    } catch (requestError) {
        error.value = extractErrorMessage(requestError, 'Failed to delete wallet.');
    } finally {
        deletingId.value = null;
    }
};

onMounted(loadWallets);
</script>

<style scoped>
.page-header {
    margin-bottom: 16px;
}

.page-title {
    margin: 0;
    color: #0f172a;
}

.page-subtitle {
    margin: 6px 0 0;
    color: #64748b;
}

.panel,
.state-card,
.table-wrap {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}

.form-panel,
.state-card {
    padding: 14px;
    margin-bottom: 16px;
}

.form-header h3 {
    margin: 0;
    color: #0f172a;
    font-size: 16px;
}

.form-header .muted {
    margin-top: 6px;
}

.wallet-form {
    display: grid;
    gap: 12px;
    margin-top: 14px;
}

.field {
    display: grid;
    gap: 6px;
    color: #334155;
    font-size: 14px;
}

.field input,
.field select {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    background: #fff;
    color: #0f172a;
}

.field input:disabled,
.field select:disabled {
    background: #f8fafc;
    color: #64748b;
}

.form-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.primary-btn,
.secondary-btn,
.link-btn {
    border-radius: 8px;
    padding: 9px 12px;
    cursor: pointer;
    font: inherit;
}

.primary-btn {
    border: 1px solid #2563eb;
    background: #2563eb;
    color: #fff;
}

.secondary-btn {
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
}

.primary-btn:disabled,
.secondary-btn:disabled,
.link-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.form-message {
    margin: 12px 0 0;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    border-bottom: 1px solid #f1f5f9;
    padding: 9px;
    text-align: left;
    font-size: 13px;
    vertical-align: top;
}

tbody tr:last-child td {
    border-bottom: 0;
}

.wallet-cell {
    min-width: 220px;
    word-break: break-all;
    white-space: normal;
}

.table-actions {
    display: flex;
    gap: 6px;
}

.link-btn {
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
    padding: 6px 10px;
}

.danger-btn {
    color: #b91c1c;
}

.muted {
    color: #64748b;
}

.error {
    color: #b91c1c;
}

.success {
    color: #15803d;
}

@media (min-width: 720px) {
    .wallet-form {
        grid-template-columns: minmax(160px, 200px) minmax(0, 1fr) minmax(160px, 220px);
        align-items: end;
    }

    .field-wide {
        grid-column: span 1;
    }

    .form-actions {
        grid-column: 1 / -1;
    }
}
</style>
