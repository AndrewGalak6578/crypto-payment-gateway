<template>
    <section class="page-stack developers-page">
        <header class="page-header developers-header">
            <div>
                <p class="page-kicker">Integration center</p>
                <h2 class="page-title">Developers</h2>
                <p class="page-subtitle">Manage API access, webhook endpoint checks, and delivery troubleshooting.</p>
            </div>
            <div class="page-actions developers-actions">
                <button class="btn btn-secondary" type="button" :disabled="loading" @click="load">{{ loading ? 'Refreshing...' : 'Refresh' }}</button>
                <button class="btn btn-primary" type="button" :disabled="!canWriteWebhooks || sendingTest || !webhookReady" @click="sendTestWebhook">
                    {{ sendingTest ? 'Sending...' : 'Send test webhook' }}
                </button>
            </div>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div v-if="success" class="alert alert-success">{{ success }}</div>

        <section class="mobile-developer-hero">
            <div class="mobile-hero-main">
                <span :class="webhookReady ? 'mobile-hero-dot mobile-hero-dot-ready' : 'mobile-hero-dot'"></span>
                <div>
                    <p>Integration status</p>
                    <strong>{{ webhookReady ? 'Ready to receive events' : 'Endpoint setup needed' }}</strong>
                    <small>{{ latestDeliveryLabel }}</small>
                </div>
            </div>
            <div class="mobile-hero-actions">
                <button class="btn btn-secondary" type="button" :disabled="loading" @click="load">{{ loading ? 'Refreshing...' : 'Refresh' }}</button>
                <button class="btn btn-primary" type="button" :disabled="!canWriteWebhooks || sendingTest || !webhookReady" @click="sendTestWebhook">
                    {{ sendingTest ? 'Sending...' : 'Test signal' }}
                </button>
            </div>
        </section>

        <section class="developer-health-grid">
            <article v-for="item in healthCards" :key="item.label" class="card card-pad health-card">
                <span :class="item.ready ? 'health-dot health-dot-ready' : 'health-dot'"></span>
                <div>
                    <p>{{ item.label }}</p>
                    <strong>{{ item.value }}</strong>
                    <small>{{ item.note }}</small>
                </div>
            </article>
        </section>

        <section class="integration-command-grid">
            <article class="card card-pad test-console">
                <div class="section-header">
                    <div>
                        <h3 class="card-title">Test console</h3>
                        <p class="card-subtitle">Queue a signed test signal through the same delivery pipeline as production events.</p>
                    </div>
                    <span class="status-badge" :class="webhookReady ? 'status-success' : 'status-warning'">{{ webhookReady ? 'Endpoint ready' : 'Configure first' }}</span>
                </div>
                <div class="test-console-body">
                    <div>
                        <span>Event</span>
                        <strong>merchant.webhook_test</strong>
                    </div>
                    <div>
                        <span>Target</span>
                        <strong>{{ webhookSettings.webhook_url || 'No endpoint configured' }}</strong>
                    </div>
                    <div>
                        <span>Latest result</span>
                        <strong>{{ latestDeliveryLabel }}</strong>
                    </div>
                </div>
                <button class="btn btn-primary" type="button" :disabled="!canWriteWebhooks || sendingTest || !webhookReady" @click="sendTestWebhook">
                    {{ sendingTest ? 'Sending...' : 'Send test signal' }}
                </button>
            </article>

            <article class="card card-pad event-catalog">
                <div class="section-header">
                    <div>
                        <h3 class="card-title">Event catalog</h3>
                        <p class="card-subtitle">Core lifecycle events your endpoint should handle.</p>
                    </div>
                </div>
                <div class="event-grid">
                    <div v-for="event in eventCatalog" :key="event.name" class="event-item">
                        <strong>{{ event.name }}</strong>
                        <p>{{ event.description }}</p>
                    </div>
                </div>
            </article>
        </section>

        <section class="developers-workspace">
            <div class="developers-main-column">
                <article class="card card-pad webhook-card">
                    <div class="section-header">
                        <div>
                            <h3 class="card-title">Webhook endpoint</h3>
                            <p class="card-subtitle">Send payment lifecycle events to your application.</p>
                        </div>
                        <span class="status-badge" :class="webhookReady ? 'status-success' : 'status-warning'">{{ webhookReady ? 'Ready' : 'Setup needed' }}</span>
                    </div>

                    <form class="webhook-form" @submit.prevent="saveWebhookSettings">
                        <label class="field">
                            <span class="field-label">Endpoint URL</span>
                            <input v-model.trim="webhookForm.webhook_url" class="input" type="url" placeholder="https://merchant.example/webhooks/payments" :readonly="!canWriteWebhooks" />
                        </label>
                        <label class="field">
                            <span class="field-label">Signing secret</span>
                            <input v-model.trim="webhookForm.webhook_secret" class="input" type="password" autocomplete="new-password" placeholder="Leave blank to keep current secret" :readonly="!canWriteWebhooks" />
                            <p class="field-help">{{ webhookSettings.has_webhook_secret ? 'A secret is configured. Enter a new value only when rotating it.' : 'Required before webhook deliveries can be sent.' }}</p>
                        </label>
                        <div class="webhook-actions">
                            <button class="btn btn-primary" type="submit" :disabled="!canWriteWebhooks || savingWebhook">
                                {{ savingWebhook ? 'Saving...' : 'Save endpoint' }}
                            </button>
                            <button class="btn btn-secondary" type="button" :disabled="!canWriteWebhooks || sendingTest || !webhookReady" @click="sendTestWebhook">
                                {{ sendingTest ? 'Sending...' : 'Send test signal' }}
                            </button>
                        </div>
                    </form>
                </article>

                <article class="card deliveries-card">
                    <div class="card-pad section-header">
                        <div>
                            <h3 class="card-title">Webhook deliveries</h3>
                            <p class="card-subtitle">Recent delivery attempts, including test signals.</p>
                        </div>
                    </div>

                    <div class="delivery-list">
                        <article v-for="delivery in deliveries" :key="delivery.id" class="delivery-item">
                            <div class="delivery-row">
                                <div>
                                    <strong>{{ delivery.event || 'webhook.delivery' }}</strong>
                                    <p>#{{ delivery.id }} · {{ delivery.invoice_id ? `Invoice ${delivery.invoice_id}` : 'Merchant-level signal' }}</p>
                                </div>
                                <span class="status-badge" :class="deliveryStatusClass(delivery.status)">{{ delivery.status || 'unknown' }}</span>
                                <div class="delivery-meta">
                                    <span>{{ formatDate(delivery.created_at) }}</span>
                                    <button
                                        class="delivery-action"
                                        type="button"
                                        :disabled="loadingDeliveryId === delivery.id"
                                        @click="inspectDelivery(delivery)"
                                    >
                                        {{ loadingDeliveryId === delivery.id ? 'Loading...' : selectedDelivery?.id === delivery.id ? 'Hide' : 'Inspect' }}
                                    </button>
                                    <button
                                        v-if="canWriteWebhooks && delivery.status !== 'delivered'"
                                        class="delivery-action"
                                        type="button"
                                        :disabled="retryingDeliveryId === delivery.id"
                                        @click="retryDelivery(delivery)"
                                    >
                                        {{ retryingDeliveryId === delivery.id ? 'Retrying...' : 'Retry' }}
                                    </button>
                                </div>
                                <p v-if="delivery.last_error" class="delivery-error">{{ delivery.last_error }}</p>
                            </div>

                            <div v-if="selectedDelivery?.id === delivery.id" class="delivery-inspector">
                                <div class="section-header">
                                    <div>
                                        <h3 class="card-title">Delivery #{{ selectedDelivery.id }}</h3>
                                        <p class="card-subtitle">{{ selectedDelivery.event }} · {{ selectedDelivery.status }}</p>
                                    </div>
                                    <button class="btn btn-secondary" type="button" @click="copyPayload">
                                        {{ payloadCopied ? 'Copied' : 'Copy payload' }}
                                    </button>
                                </div>
                                <div class="inspector-grid">
                                    <div>
                                        <span>URL</span>
                                        <strong>{{ selectedDelivery.url || '—' }}</strong>
                                    </div>
                                    <div>
                                        <span>Attempts</span>
                                        <strong>{{ selectedDelivery.attempts ?? '—' }}</strong>
                                    </div>
                                    <div>
                                        <span>Delivered</span>
                                        <strong>{{ formatDate(selectedDelivery.delivered_at) }}</strong>
                                    </div>
                                    <div>
                                        <span>Next retry</span>
                                        <strong>{{ formatDate(selectedDelivery.next_retry_at) }}</strong>
                                    </div>
                                </div>
                                <p v-if="selectedDelivery.last_error" class="delivery-error">{{ selectedDelivery.last_error }}</p>
                                <pre>{{ selectedDeliveryPayload }}</pre>
                            </div>
                        </article>
                        <div v-if="!deliveries.length" class="empty-state">No webhook deliveries yet.</div>
                    </div>
                </article>
            </div>

            <aside class="developers-side-column">
                <article class="card card-pad api-keys-card">
                    <div class="section-header">
                        <div>
                            <h3 class="card-title">API keys</h3>
                            <p class="card-subtitle">{{ activeKeys }} active key{{ activeKeys === 1 ? '' : 's' }}</p>
                        </div>
                    </div>

                    <form v-if="canWriteApiKeys" class="api-key-form" @submit.prevent="createApiKey">
                        <input v-model.trim="newKeyName" class="input" placeholder="Production key" />
                        <button class="btn btn-primary" type="submit" :disabled="creatingKey || !newKeyName">
                            {{ creatingKey ? 'Creating...' : 'Create key' }}
                        </button>
                    </form>

                    <div v-if="createdToken" class="created-token">
                        <span>New API token</span>
                        <code>{{ createdToken }}</code>
                        <button class="btn btn-secondary" type="button" @click="copyToken">Copy token</button>
                    </div>

                    <div class="api-key-list">
                        <div v-for="key in apiKeys" :key="key.id" class="api-key-row">
                            <div>
                                <strong>{{ key.name || `Key #${key.id}` }}</strong>
                                <p>{{ key.revoked_at ? 'Revoked' : `Created ${formatDate(key.created_at)}` }}</p>
                            </div>
                            <span class="status-badge" :class="key.revoked_at ? 'status-danger' : 'status-success'">{{ key.revoked_at ? 'Revoked' : 'Active' }}</span>
                            <button
                                v-if="canWriteApiKeys && !key.revoked_at"
                                class="delivery-action"
                                type="button"
                                :disabled="revokingKeyId === key.id"
                                @click="revokeApiKey(key)"
                            >
                                {{ revokingKeyId === key.id ? 'Revoking...' : 'Revoke' }}
                            </button>
                        </div>
                        <div v-if="!apiKeys.length" class="empty-state">No API keys yet.</div>
                    </div>
                </article>

                <article class="card card-pad quickstart-card">
                    <h3 class="card-title">Quickstart</h3>
                    <p class="card-subtitle">Create a hosted checkout from your server using a merchant API key.</p>
                    <pre>curl -X POST https://settlane.tech/api/v1/invoices \
  -H "Authorization: Bearer mapi_..." \
  -H "Content-Type: application/json" \
  -d '{"amount_usd":10,"coin":null}'</pre>
                </article>
            </aside>
        </section>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useAuthStore } from '../../../stores/auth';
import { merchantApi } from '../../services/merchantApi';

const authStore = useAuthStore();
const loading = ref(true);
const error = ref('');
const success = ref('');
const apiKeys = ref([]);
const webhookSettings = ref({});
const deliveries = ref([]);
const savingWebhook = ref(false);
const sendingTest = ref(false);
const creatingKey = ref(false);
const retryingDeliveryId = ref(null);
const loadingDeliveryId = ref(null);
const revokingKeyId = ref(null);
const newKeyName = ref('');
const createdToken = ref('');
const selectedDelivery = ref(null);
const payloadCopied = ref(false);
let payloadCopyTimer = null;
const webhookForm = reactive({
    webhook_url: '',
    webhook_secret: '',
});

const canWriteWebhooks = computed(() => authStore.hasCapability('webhooks.write'));
const canWriteApiKeys = computed(() => authStore.hasCapability('api_keys.write'));
const activeKeys = computed(() => apiKeys.value.filter((item) => !item.revoked_at).length);
const webhookReady = computed(() => Boolean(webhookSettings.value.webhook_url && webhookSettings.value.has_webhook_secret));
const failedDeliveries = computed(() => deliveries.value.filter((item) => item.status === 'failed').length);
const latestDelivery = computed(() => deliveries.value[0] || null);
const latestDeliveryLabel = computed(() => {
    if (!latestDelivery.value) return 'No signal sent';
    return `${latestDelivery.value.status || 'unknown'} · #${latestDelivery.value.id}`;
});
const selectedDeliveryPayload = computed(() => {
    const payload = selectedDelivery.value?.payload ?? selectedDelivery.value?.payload_preview ?? {};
    return typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
});
const eventCatalog = [
    { name: 'invoice.created', description: 'A payment link was created.' },
    { name: 'invoice.fixated', description: 'Funds were detected before final confirmation.' },
    { name: 'invoice.paid', description: 'Payment reached the paid threshold.' },
    { name: 'invoice.expired', description: 'Checkout expired before completion.' },
    { name: 'invoice.forwarded', description: 'Merchant settlement forwarding completed.' },
    { name: 'merchant.webhook_test', description: 'Manual test signal from this console.' },
];
const healthCards = computed(() => [
    {
        label: 'API access',
        value: activeKeys.value ? `${activeKeys.value} active` : 'No active keys',
        note: 'Server-side invoice creation',
        ready: activeKeys.value > 0,
    },
    {
        label: 'Webhook endpoint',
        value: webhookReady.value ? 'Ready' : 'Missing setup',
        note: webhookSettings.value.webhook_url || 'No endpoint configured',
        ready: webhookReady.value,
    },
    {
        label: 'Delivery health',
        value: failedDeliveries.value ? `${failedDeliveries.value} failed` : 'Clear',
        note: `${deliveries.value.length} recent attempt${deliveries.value.length === 1 ? '' : 's'}`,
        ready: failedDeliveries.value === 0,
    },
]);

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const deliveryStatusClass = (status) => {
    if (status === 'delivered') return 'status-success';
    if (status === 'failed') return 'status-danger';
    if (status === 'pending') return 'status-warning';
    return 'status-info';
};

const applyWebhookSettings = (settings) => {
    webhookSettings.value = settings || {};
    webhookForm.webhook_url = webhookSettings.value.webhook_url || '';
    webhookForm.webhook_secret = '';
};

const load = async () => {
    loading.value = true;
    error.value = '';

    try {
        const [keysResponse, settingsResponse, deliveriesResponse] = await Promise.all([
            merchantApi.apiKeys(),
            merchantApi.webhookSettings(),
            merchantApi.webhookDeliveries({ per_page: 20 }),
        ]);
        apiKeys.value = keysResponse.data?.data || [];
        applyWebhookSettings(settingsResponse.data?.data || {});
        const payload = deliveriesResponse.data?.data;
        deliveries.value = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : [];
    } catch {
        error.value = 'Failed to load developer resources.';
    } finally {
        loading.value = false;
    }
};

const saveWebhookSettings = async () => {
    savingWebhook.value = true;
    error.value = '';
    success.value = '';

    try {
        const payload = {
            webhook_url: webhookForm.webhook_url || null,
        };
        if (webhookForm.webhook_secret) {
            payload.webhook_secret = webhookForm.webhook_secret;
        }

        const response = await merchantApi.updateWebhookSettings(payload);
        applyWebhookSettings(response.data?.data || {});
        success.value = 'Webhook endpoint saved.';
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || 'Failed to save webhook endpoint.';
    } finally {
        savingWebhook.value = false;
    }
};

const sendTestWebhook = async () => {
    sendingTest.value = true;
    error.value = '';
    success.value = '';

    try {
        const response = await merchantApi.sendTestWebhook();
        const delivery = response.data?.data;
        if (delivery) {
            deliveries.value = [delivery, ...deliveries.value].slice(0, 20);
        }
        success.value = 'Test webhook queued.';
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || 'Failed to send test webhook.';
    } finally {
        sendingTest.value = false;
    }
};

const retryDelivery = async (delivery) => {
    retryingDeliveryId.value = delivery.id;
    error.value = '';
    success.value = '';

    try {
        await merchantApi.retryWebhookDelivery(delivery.id);
        success.value = 'Webhook retry queued.';
        await load();
    } catch {
        error.value = 'Failed to retry webhook delivery.';
    } finally {
        retryingDeliveryId.value = null;
    }
};

const inspectDelivery = async (delivery) => {
    if (selectedDelivery.value?.id === delivery.id) {
        selectedDelivery.value = null;
        payloadCopied.value = false;
        return;
    }

    loadingDeliveryId.value = delivery.id;
    error.value = '';
    payloadCopied.value = false;

    try {
        const response = await merchantApi.webhookDelivery(delivery.id);
        selectedDelivery.value = response.data?.data || null;
    } catch {
        error.value = 'Failed to load webhook delivery details.';
    } finally {
        loadingDeliveryId.value = null;
    }
};

const createApiKey = async () => {
    creatingKey.value = true;
    error.value = '';
    success.value = '';
    createdToken.value = '';

    try {
        const response = await merchantApi.createApiKey({ name: newKeyName.value });
        const key = response.data?.data;
        if (key) {
            createdToken.value = key.token || '';
            apiKeys.value = [{ ...key, revoked_at: null }, ...apiKeys.value];
        }
        newKeyName.value = '';
        success.value = 'API key created.';
    } catch {
        error.value = 'Failed to create API key.';
    } finally {
        creatingKey.value = false;
    }
};

const copyToken = async () => {
    if (!createdToken.value) return;
    await navigator.clipboard.writeText(createdToken.value);
    success.value = 'API token copied.';
};

const copyPayload = async () => {
    await navigator.clipboard.writeText(selectedDeliveryPayload.value);
    payloadCopied.value = true;
    if (payloadCopyTimer) {
        window.clearTimeout(payloadCopyTimer);
    }
    payloadCopyTimer = window.setTimeout(() => {
        payloadCopied.value = false;
        payloadCopyTimer = null;
    }, 1200);
    success.value = 'Webhook payload copied.';
};

const revokeApiKey = async (key) => {
    revokingKeyId.value = key.id;
    error.value = '';
    success.value = '';

    try {
        await merchantApi.deleteApiKey(key.id);
        key.revoked_at = new Date().toISOString();
        success.value = 'API key revoked.';
    } catch {
        error.value = 'Failed to revoke API key.';
    } finally {
        revokingKeyId.value = null;
    }
};

onMounted(load);
</script>

<style scoped>
.developers-page {
    gap: 18px;
}

.developer-health-grid,
.integration-command-grid,
.developers-workspace {
    display: grid;
    gap: 16px;
}

.developer-health-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.mobile-developer-hero {
    display: none;
}

.integration-command-grid {
    grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.08fr);
    align-items: start;
}

.health-card {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 12px;
    align-items: start;
}

.health-card p,
.health-card small {
    margin: 0;
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.health-card strong {
    display: block;
    margin: 5px 0;
    color: var(--m-text);
    font-size: 22px;
    line-height: 1.1;
}

.health-dot {
    width: 10px;
    height: 10px;
    margin-top: 4px;
    border-radius: 50%;
    background: var(--m-warning-500);
    box-shadow: 0 0 0 4px var(--m-warning-50);
}

.health-dot-ready {
    background: var(--m-success-500);
    box-shadow: 0 0 0 4px var(--m-success-50);
}

.developers-main-column,
.developers-side-column,
.delivery-list,
.api-key-list {
    display: grid;
    gap: 12px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
}

.webhook-form,
.api-key-form {
    display: grid;
    gap: 12px;
    margin-top: 16px;
}

.test-console,
.event-catalog {
    display: grid;
    gap: 16px;
}

.test-console-body,
.inspector-grid {
    display: grid;
    gap: 1px;
    overflow: hidden;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-border);
}

.inspector-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.test-console-body div,
.inspector-grid div {
    min-width: 0;
    padding: 11px 12px;
    background: var(--m-surface);
}

.inspector-grid div {
    padding: 8px 10px;
}

.test-console-body span,
.inspector-grid span {
    display: block;
    color: var(--m-muted);
    font-size: var(--m-xs);
    font-weight: 750;
}

.test-console-body strong,
.inspector-grid strong {
    display: block;
    min-width: 0;
    margin-top: 4px;
    color: var(--m-text);
    font-size: var(--m-sm);
    overflow-wrap: anywhere;
}

.inspector-grid strong {
    font-size: var(--m-xs);
    line-height: 1.35;
}

.event-grid {
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.event-item {
    padding: 11px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
}

.event-item strong {
    color: var(--m-text);
    font-size: var(--m-sm);
}

.event-item p {
    margin: 5px 0 0;
    color: var(--m-muted);
    font-size: var(--m-xs);
    line-height: 1.4;
}

.api-key-form {
    grid-template-columns: minmax(0, 1fr) auto;
}

.webhook-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.delivery-list {
    padding: 0 18px 18px;
}

.delivery-item,
.delivery-row,
.api-key-row {
    border-radius: var(--m-radius-lg);
}

.delivery-item {
    overflow: hidden;
    border: 1px solid var(--m-border);
    background: var(--m-surface-subtle);
}

.delivery-row,
.api-key-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 12px;
    align-items: center;
    padding: 12px;
    background: var(--m-surface-subtle);
}

.api-key-row {
    border: 1px solid var(--m-border);
}

.delivery-row strong,
.api-key-row strong {
    color: var(--m-text);
    font-size: var(--m-sm);
}

.delivery-row p,
.api-key-row p {
    margin: 4px 0 0;
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.delivery-meta {
    display: grid;
    justify-items: end;
    gap: 6px;
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.delivery-action {
    min-height: 28px;
    border: 1px solid var(--m-border-strong);
    border-radius: var(--m-radius-md);
    background: var(--m-surface);
    color: var(--m-brand-700);
    padding: 0 9px;
    font-size: var(--m-xs);
    font-weight: 850;
}

.delivery-error {
    grid-column: 1 / -1;
    color: var(--m-danger-700) !important;
}

.delivery-inspector {
    display: block;
    gap: 10px;
    margin: 0;
    padding: 12px;
    border-top: 1px solid var(--m-border);
    border-radius: 0;
    background:
        linear-gradient(180deg, rgba(238, 245, 255, 0.6), rgba(255, 255, 255, 0.96) 48%),
        var(--m-surface);
}

.delivery-inspector pre {
    max-height: 220px;
    overflow: auto;
    margin: 0;
    padding: 10px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
    color: var(--m-text);
    font-size: 11px;
    line-height: 1.45;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.delivery-inspector .section-header {
    align-items: center;
}

.delivery-inspector .card-title {
    font-size: var(--m-md);
}

.delivery-inspector .card-subtitle {
    margin-top: 3px;
    font-size: var(--m-xs);
}

.delivery-inspector .btn {
    min-height: 34px;
    padding-inline: 10px;
    font-size: var(--m-xs);
}

.created-token {
    display: grid;
    gap: 8px;
    margin-top: 14px;
    padding: 12px;
    border: 1px solid #abefc6;
    border-radius: var(--m-radius-lg);
    background: var(--m-success-50);
}

.created-token span {
    color: var(--m-success-700);
    font-size: var(--m-xs);
    font-weight: 800;
}

.created-token code,
.quickstart-card pre {
    overflow: auto;
    margin: 0;
    padding: 12px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
    color: var(--m-text);
    font-size: 12px;
}

.quickstart-card {
    display: grid;
    gap: 12px;
}

@media (min-width: 1120px) {
    .developers-workspace {
        grid-template-columns: minmax(0, 1fr) 390px;
        align-items: start;
    }

    .developers-side-column {
        position: sticky;
        top: calc(var(--m-topbar-height) + 24px);
    }
}

@media (max-width: 820px) {
    .developer-health-grid,
    .integration-command-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .developers-page {
        gap: 12px;
    }

    .developers-header {
        display: grid;
        gap: 6px;
        padding: 2px 2px 0;
    }

    .developers-header .page-kicker,
    .developers-header .page-subtitle {
        display: none;
    }

    .developers-header .page-title {
        font-size: 24px;
        line-height: 1.1;
    }

    .developers-actions {
        display: none;
    }

    .mobile-developer-hero {
        order: 1;
        display: grid;
        gap: 14px;
        padding: 16px;
        border: 1px solid rgba(21, 94, 239, 0.18);
        border-radius: 22px;
        background:
            linear-gradient(135deg, rgba(238, 245, 255, 0.96), rgba(255, 255, 255, 0.98) 58%),
            var(--m-surface);
        box-shadow: var(--m-shadow-sm);
    }

    .mobile-hero-main {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 12px;
        align-items: start;
    }

    .mobile-hero-main p,
    .mobile-hero-main small {
        margin: 0;
        color: var(--m-muted);
        font-size: var(--m-xs);
        line-height: 1.35;
    }

    .mobile-hero-main strong {
        display: block;
        margin: 4px 0;
        color: var(--m-text);
        font-size: 18px;
        line-height: 1.2;
    }

    .mobile-hero-dot {
        width: 12px;
        height: 12px;
        margin-top: 4px;
        border-radius: 50%;
        background: var(--m-warning-500);
        box-shadow: 0 0 0 5px var(--m-warning-50);
    }

    .mobile-hero-dot-ready {
        background: var(--m-success-500);
        box-shadow: 0 0 0 5px var(--m-success-50);
    }

    .mobile-hero-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        width: 100%;
        gap: 8px;
    }

    .mobile-hero-actions .btn,
    .webhook-actions .btn,
    .api-key-form .btn {
        min-height: 44px;
        width: 100%;
    }

    .developer-health-grid {
        order: 2;
        display: flex;
        gap: 10px;
        margin: 0 -16px;
        padding: 1px 16px 3px;
        overflow-x: auto;
        scroll-padding: 16px;
        scroll-snap-type: x mandatory;
        scrollbar-width: none;
    }

    .developer-health-grid::-webkit-scrollbar {
        display: none;
    }

    .health-card {
        flex: 0 0 min(78vw, 286px);
        min-height: 112px;
        border-radius: 18px;
        scroll-snap-align: start;
    }

    .health-card strong {
        font-size: 20px;
    }

    .integration-command-grid,
    .developers-workspace,
    .developers-main-column,
    .developers-side-column {
        display: contents;
    }

    .test-console {
        order: 3;
    }

    .webhook-card {
        order: 4;
    }

    .deliveries-card {
        order: 5;
        overflow: hidden;
        padding: 0 !important;
    }

    .api-keys-card {
        order: 6;
    }

    .event-catalog {
        order: 7;
    }

    .quickstart-card {
        order: 8;
    }

    .section-header {
        display: grid;
        gap: 10px;
    }

    .webhook-actions,
    .api-key-form {
        grid-template-columns: 1fr;
    }

    .webhook-form {
        gap: 14px;
    }

    .test-console,
    .webhook-card,
    .api-keys-card,
    .quickstart-card {
        border-radius: 20px;
    }

    .test-console-body,
    .event-grid {
        grid-template-columns: 1fr;
    }

    .delivery-list {
        gap: 10px;
        padding: 0 14px 14px;
    }

    .deliveries-card > .section-header {
        padding: 16px 16px 12px;
    }

    .delivery-item,
    .delivery-row,
    .api-key-row {
        border-radius: 18px;
    }

    .delivery-row,
    .api-key-row {
        grid-template-columns: 1fr;
        gap: 10px;
        align-items: start;
        padding: 12px;
    }

    .delivery-row > .status-badge,
    .api-key-row > .status-badge {
        justify-self: start;
    }

    .delivery-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-start;
        gap: 8px;
        width: 100%;
    }

    .delivery-action {
        min-height: 36px;
        padding: 0 12px;
    }

    .delivery-inspector {
        padding: 10px;
    }

    .delivery-inspector .section-header {
        display: grid;
        gap: 10px;
    }

    .delivery-inspector .btn {
        width: 100%;
        min-height: 38px;
    }

    .inspector-grid {
        grid-template-columns: 1fr;
    }

    .delivery-inspector pre {
        max-height: 180px;
        font-size: 10.5px;
    }

    .api-key-form {
        margin-top: 14px;
    }

    .created-token code,
    .quickstart-card pre {
        max-width: 100%;
        font-size: 11px;
        white-space: pre;
    }

    .event-catalog {
        overflow: hidden;
    }

    .event-grid {
        display: flex;
        gap: 8px;
        margin: 0 -16px;
        padding: 0 16px 2px;
        overflow-x: auto;
        scroll-padding: 16px;
        scroll-snap-type: x mandatory;
        scrollbar-width: none;
    }

    .event-grid::-webkit-scrollbar {
        display: none;
    }

    .event-item {
        flex: 0 0 min(74vw, 270px);
        scroll-snap-align: start;
    }
}

@media (max-width: 420px) {
    .mobile-hero-actions {
        grid-template-columns: 1fr;
    }

    .mobile-hero-main strong {
        font-size: 17px;
    }
}
</style>
