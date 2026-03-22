<template>
    <section>
        <header class="page-header">
            <div>
                <h2 class="page-title">Webhook Settings</h2>
                <p class="page-subtitle">Configure the webhook endpoint used for invoice event delivery.</p>
            </div>
        </header>

        <p v-if="loading" class="muted">Loading webhook settings...</p>

        <div v-else class="card">
            <div v-if="loadError" class="message message-error">
                {{ loadError }}
            </div>

            <div v-if="successMessage" class="message message-success">
                {{ successMessage }}
            </div>

            <form class="form" @submit.prevent="saveSettings">
                <div class="field">
                    <label class="label" for="webhook_url">Webhook URL</label>
                    <input
                        id="webhook_url"
                        v-model="form.webhook_url"
                        class="input"
                        type="url"
                        autocomplete="url"
                        placeholder="https://example.com/webhook"
                        :disabled="saving || loadingFailed"
                    />
                </div>

                <div class="field">
                    <div class="label-row">
                        <label class="label" for="webhook_secret">Webhook Secret</label>
                        <span class="status-badge" :class="statusClass">{{ secretStatusLabel }}</span>
                    </div>
                    <input
                        id="webhook_secret"
                        v-model="form.webhook_secret"
                        class="input"
                        type="password"
                        autocomplete="new-password"
                        placeholder="Enter a new secret"
                        :disabled="saving || loadingFailed"
                    />
                    <p class="helper-text">The current secret is never shown. Leave blank to keep the current secret unchanged.</p>
                </div>

                <div v-if="saveError" class="message message-error">
                    {{ saveError }}
                </div>

                <div class="actions">
                    <button class="action-btn" type="submit" :disabled="saving || loadingFailed">
                        {{ saving ? 'Saving...' : 'Save' }}
                    </button>

                    <button v-if="loadingFailed" class="secondary-btn" type="button" @click="loadSettings">
                        Retry
                    </button>
                </div>
            </form>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { getMerchantWebhookSettings, updateMerchantWebhookSettings } from '../../api/merchant.js';

const loading = ref(true);
const saving = ref(false);
const loadError = ref('');
const saveError = ref('');
const successMessage = ref('');
const hasWebhookSecret = ref(false);

const form = reactive({
    webhook_url: '',
    webhook_secret: '',
});

const loadingFailed = computed(() => Boolean(loadError.value));
const secretStatusLabel = computed(() => (hasWebhookSecret.value ? 'Secret configured' : 'No secret configured'));
const statusClass = computed(() => (hasWebhookSecret.value ? 'status-badge-success' : 'status-badge-muted'));

const applySettings = (settings) => {
    form.webhook_url = settings?.webhook_url ?? '';
    hasWebhookSecret.value = Boolean(settings?.has_webhook_secret);
};

const loadSettings = async () => {
    loading.value = true;
    loadError.value = '';
    saveError.value = '';
    successMessage.value = '';

    try {
        const response = await getMerchantWebhookSettings();
        applySettings(response.data?.data ?? {});
        form.webhook_secret = '';
    } catch (error) {
        loadError.value = error.response?.data?.message || 'Failed to load webhook settings.';
    } finally {
        loading.value = false;
    }
};

const saveSettings = async () => {
    if (loadingFailed.value) {
        return;
    }

    saving.value = true;
    saveError.value = '';
    successMessage.value = '';

    const payload = {
        webhook_url: form.webhook_url || null,
    };

    const trimmedSecret = form.webhook_secret.trim();

    if (trimmedSecret) {
        payload.webhook_secret = trimmedSecret;
    }

    try {
        const response = await updateMerchantWebhookSettings(payload);
        const responseData = response.data?.data;

        applySettings({
            webhook_url: responseData?.webhook_url ?? payload.webhook_url ?? '',
            has_webhook_secret: responseData?.has_webhook_secret ?? (trimmedSecret ? true : hasWebhookSecret.value),
        });

        form.webhook_secret = '';
        successMessage.value = 'Webhook settings saved.';
    } catch (error) {
        const validationErrors = error.response?.data?.errors;

        if (validationErrors && typeof validationErrors === 'object') {
            saveError.value = Object.values(validationErrors).flat().join(' ');
        } else {
            saveError.value = error.response?.data?.message || 'Failed to save webhook settings.';
        }
    } finally {
        saving.value = false;
    }
};

onMounted(loadSettings);
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

.card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    max-width: 720px;
}

.form {
    display: grid;
    gap: 16px;
}

.field {
    display: grid;
    gap: 8px;
}

.label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.label {
    color: #334155;
    font-size: 14px;
    font-weight: 600;
}

.input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    background: #fff;
    color: #0f172a;
}

.input:disabled {
    background: #f8fafc;
    cursor: not-allowed;
}

.helper-text {
    margin: 0;
    color: #64748b;
    font-size: 13px;
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

.status-badge-muted {
    background: #e2e8f0;
    color: #475569;
}

.message {
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 14px;
}

.message-error {
    background: #fef2f2;
    color: #b91c1c;
}

.message-success {
    background: #f0fdf4;
    color: #166534;
}

.actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.action-btn,
.secondary-btn {
    border-radius: 8px;
    padding: 10px 14px;
    cursor: pointer;
}

.action-btn {
    border: 1px solid #0f172a;
    background: #0f172a;
    color: #fff;
}

.secondary-btn {
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
}

.action-btn:disabled,
.secondary-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.muted {
    color: #64748b;
}
</style>
