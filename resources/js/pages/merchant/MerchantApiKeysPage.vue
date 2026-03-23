<template>
    <section>
        <header class="page-header">
            <div>
                <h2 class="page-title">API Keys</h2>
                <p class="page-subtitle">Manage API keys used to create and query merchant invoices.</p>
            </div>
        </header>

        <div v-if="isReadOnly" class="info-banner">
            API keys are read-only for your account.
        </div>

        <article v-if="canWriteApiKeys" class="panel form-panel">
            <div class="form-header">
                <div>
                    <h3>Create API key</h3>
                    <p class="muted">Create a named key for server-to-server API access.</p>
                </div>
            </div>

            <form class="api-key-form" @submit.prevent="submitForm">
                <label class="field field-wide">
                    <span>Name</span>
                    <input
                        v-model.trim="form.name"
                        type="text"
                        autocomplete="off"
                        placeholder="Production key"
                        :disabled="submitting"
                    />
                </label>

                <div class="form-actions">
                    <button type="submit" class="primary-btn" :disabled="submitting">
                        {{ submitting ? 'Creating...' : 'Create API Key' }}
                    </button>
                </div>
            </form>

            <p v-if="formError" class="error form-message">{{ formError }}</p>
            <p v-else-if="formSuccess" class="success form-message">{{ formSuccess }}</p>
        </article>

        <article v-if="createdToken" class="token-card">
            <div class="token-card-header">
                <div>
                    <h3 class="token-title">New API key created</h3>
                    <p class="token-subtitle">Copy this token now. It will not be shown again.</p>
                </div>
                <button type="button" class="secondary-btn" @click="dismissToken">Dismiss</button>
            </div>

            <pre class="token-value">{{ createdToken }}</pre>
        </article>

        <p v-if="loading" class="muted">Loading API keys...</p>

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadApiKeys">Retry</button>
        </div>

        <div v-else-if="!apiKeys.length" class="state-card">
            <p class="muted">No API keys yet.</p>
        </div>

        <div v-else class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Last used</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="apiKey in apiKeys" :key="apiKey.id">
                    <td>{{ apiKey.name }}</td>
                    <td>
                        <span class="status-badge" :class="statusBadgeClass(apiKey)">
                            {{ apiKey.revoked_at ? 'Revoked' : 'Active' }}
                        </span>
                    </td>
                    <td>{{ formatDate(apiKey.last_used_at) }}</td>
                    <td>{{ formatDate(apiKey.created_at) }}</td>
                    <td>
                        <button
                            v-if="canWriteApiKeys && !apiKey.revoked_at"
                            type="button"
                            class="link-btn danger-btn"
                            :disabled="revokingId === apiKey.id"
                            @click="revokeApiKey(apiKey)"
                        >
                            {{ revokingId === apiKey.id ? 'Revoking...' : 'Revoke' }}
                        </button>
                        <span v-else class="muted">—</span>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { createMerchantApiKey, deleteMerchantApiKey, getMerchantApiKeys } from '../../api/merchant.js';
import { useAuthStore } from "../../stores/auth.js";

const authStore = useAuthStore();

const loading = ref(true);
const submitting = ref(false);
const revokingId = ref(null);
const error = ref('');
const formError = ref('');
const formSuccess = ref('');
const apiKeys = ref([]);
const createdToken = ref('');

const form = reactive({
    name: '',
});

const canWriteApiKeys = computed(() => authStore.hasCapability('api_keys.write'));
const isReadOnly = computed(() => authStore.hasCapability('api_keys.read') && !canWriteApiKeys.value);

const formatDate = (dateString) => {
    if (!dateString) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(dateString));
};

const normalizeApiKeys = (items) => {
    apiKeys.value = [...items].sort((left, right) => new Date(right.created_at) - new Date(left.created_at));
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

const loadApiKeys = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await getMerchantApiKeys();
        normalizeApiKeys(Array.isArray(response.data?.data) ? response.data.data : []);
    } catch (requestError) {
        error.value = extractErrorMessage(requestError, 'Failed to load API keys.');
    } finally {
        loading.value = false;
    }
};

const submitForm = async () => {
    if (!canWriteApiKeys.value) {
        return;
    }

    formError.value = '';
    formSuccess.value = '';

    if (!form.name) {
        formError.value = 'Name is required.';
        return;
    }

    submitting.value = true;

    try {
        const response = await createMerchantApiKey({
            name: form.name,
        });

        createdToken.value = response.data?.data?.token || '';
        form.name = '';
        formSuccess.value = 'API key created.';
        await loadApiKeys();
    } catch (requestError) {
        formError.value = extractErrorMessage(requestError, 'Failed to create API key.');
    } finally {
        submitting.value = false;
    }
};

const revokeApiKey = async (apiKey) => {
    if (!canWriteApiKeys.value || apiKey.revoked_at) {
        return;
    }

    if (!window.confirm(`Revoke API key \"${apiKey.name}\"?`)) {
        return;
    }

    revokingId.value = apiKey.id;
    error.value = '';
    formError.value = '';
    formSuccess.value = '';

    try {
        await deleteMerchantApiKey(apiKey.id);
        await loadApiKeys();
        formSuccess.value = 'API key revoked.';
    } catch (requestError) {
        error.value = extractErrorMessage(requestError, 'Failed to revoke API key.');
    } finally {
        revokingId.value = null;
    }
};

const dismissToken = () => {
    createdToken.value = '';
};

const statusBadgeClass = (apiKey) => (apiKey.revoked_at ? 'status-badge-danger' : 'status-badge-success');

onMounted(loadApiKeys);
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

.info-banner,
.panel,
.token-card,
.state-card,
.table-wrap {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}

.info-banner,
.form-panel,
.token-card,
.state-card {
    padding: 14px;
    margin-bottom: 16px;
}

.info-banner {
    color: #475569;
}

.form-header h3,
.token-title {
    margin: 0;
    color: #0f172a;
    font-size: 16px;
}

.form-header .muted,
.token-subtitle {
    margin-top: 6px;
}

.api-key-form {
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

.field input {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    background: #fff;
    color: #0f172a;
}

.field input:disabled {
    background: #f8fafc;
    color: #64748b;
}

.form-actions,
.token-card-header {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: flex-start;
    justify-content: space-between;
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

.secondary-btn,
.link-btn {
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
}

.danger-btn {
    color: #b91c1c;
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

.success {
    color: #166534;
}

.error {
    color: #b91c1c;
}

.muted {
    color: #64748b;
}

.token-value {
    margin: 12px 0 0;
    padding: 12px;
    background: #0f172a;
    border-radius: 8px;
    color: #f8fafc;
    font-size: 13px;
    white-space: pre-wrap;
    word-break: break-all;
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

.status-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge-success {
    background: #dcfce7;
    color: #166534;
}

.status-badge-danger {
    background: #fef2f2;
    color: #b91c1c;
}


@media (min-width: 720px) {
    .api-key-form {
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: end;
    }

    .field-wide {
        grid-column: span 1;
    }

    .form-actions {
        justify-content: flex-start;
    }
}
</style>
