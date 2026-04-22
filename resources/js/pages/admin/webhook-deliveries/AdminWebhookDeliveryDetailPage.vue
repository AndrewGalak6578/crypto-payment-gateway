<template>
    <section>
        <PageHeader :title="`Webhook Delivery #${deliveryId}`" subtitle="Delivery payload, signature and retry state.">
            <template #actions>
                <button type="button" class="secondary-btn" :disabled="loading" @click="loadDelivery">Refresh data</button>
                <button type="button" class="primary-btn" :disabled="retryLoading || loading || !delivery" @click="openRetryConfirm">
                    {{ retryLoading ? 'Queueing...' : 'Retry / Redeliver' }}
                </button>
                <button type="button" class="secondary-btn" @click="router.push({ name: 'admin.webhook-deliveries' })">Back</button>
            </template>
        </PageHeader>

        <LoadingState v-if="loading" text="Loading delivery details..." />
        <p v-if="!loading && !error && notice" class="copy-notice" :class="{ 'copy-notice-error': noticeType === 'error' }">{{ notice }}</p>

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadDelivery">Retry</button>
        </div>

        <template v-else-if="delivery">
            <article class="panel">
                <h3 class="panel-title">Delivery state</h3>
                <div class="kv-grid">
                    <div><strong>status:</strong> <StatusBadge :text="delivery.status || '—'" :variant="statusVariant(delivery.status)" /></div>
                    <div><strong>event:</strong> {{ delivery.event || '—' }}</div>
                    <div><strong>attempts:</strong> {{ delivery.attempts ?? '—' }}</div>
                    <div><strong>url:</strong> <span class="mono">{{ delivery.url || '—' }}</span></div>
                    <div><strong>error:</strong> {{ delivery.last_error || '—' }}</div>
                    <div><strong>next_retry_at:</strong> {{ formatDate(delivery.next_retry_at) }}</div>
                    <div><strong>delivered_at:</strong> {{ formatDate(delivery.delivered_at) }}</div>
                    <div><strong>created_at:</strong> {{ formatDate(delivery.created_at) }}</div>
                    <div><strong>updated_at:</strong> {{ formatDate(delivery.updated_at) }}</div>
                </div>
                <div class="actions-row">
                    <button type="button" class="secondary-btn" :disabled="!delivery.url" @click="copyText(delivery.url)">Copy URL</button>
                    <button type="button" class="secondary-btn" :disabled="!delivery.last_error" @click="copyText(delivery.last_error)">Copy error</button>
                </div>
            </article>

            <article class="panel">
                <h3 class="panel-title">Invoice block</h3>
                <div class="kv-grid">
                    <div><strong>invoice_id:</strong> {{ delivery.invoice?.id ?? '—' }}</div>
                    <div><strong>invoice_public_id:</strong> {{ delivery.invoice?.public_id || '—' }}</div>
                    <div><strong>merchant_id:</strong> {{ delivery.invoice?.merchant_id ?? '—' }}</div>
                    <div><strong>merchant_name:</strong> {{ delivery.invoice?.merchant_name || '—' }}</div>
                </div>
                <div class="actions-row">
                    <RouterLink
                        v-if="delivery.invoice?.id"
                        class="secondary-btn link-btn"
                        :to="{ name: 'admin.invoices.detail', params: { id: delivery.invoice.id } }"
                    >
                        Open invoice
                    </RouterLink>
                    <RouterLink
                        v-if="delivery.invoice?.merchant_id"
                        class="secondary-btn link-btn"
                        :to="{ name: 'admin.merchants.detail', params: { id: delivery.invoice.merchant_id } }"
                    >
                        Open merchant
                    </RouterLink>
                </div>
            </article>

            <article class="panel">
                <h3 class="panel-title">Signature</h3>
                <pre class="json-box">{{ delivery.signature || '—' }}</pre>
                <div class="actions-row">
                    <button type="button" class="secondary-btn" :disabled="!delivery.signature" @click="copyText(delivery.signature)">Copy signature</button>
                </div>
            </article>

            <article class="panel">
                <h3 class="panel-title">Payload</h3>
                <pre class="json-box">{{ formatJson(delivery.payload) }}</pre>
                <div class="actions-row">
                    <button type="button" class="secondary-btn" :disabled="!delivery.payload" @click="copyText(formatJson(delivery.payload))">Copy payload JSON</button>
                </div>
            </article>
        </template>

        <ConfirmModal
            :open="confirmOpen"
            title="Retry webhook delivery"
            :message="`Queue retry for delivery #${deliveryId}?`"
            confirm-label="Retry"
            danger
            :loading="retryLoading"
            @close="confirmOpen = false"
            @confirm="retryDelivery"
        />
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    extractApiErrorMessage,
    getAdminWebhookDelivery,
    retryAdminWebhookDelivery,
} from '../../../api/admin';
import ConfirmModal from '../../../components/admin/ConfirmModal.vue';
import LoadingState from '../../../components/admin/LoadingState.vue';
import PageHeader from '../../../components/admin/PageHeader.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import { copyTextToClipboard } from '../../../utils/clipboard';

const route = useRoute();
const router = useRouter();
const deliveryId = computed(() => route.params.id);

const loading = ref(true);
const retryLoading = ref(false);
const error = ref('');
const notice = ref('');
const noticeType = ref('success');
const delivery = ref(null);
const confirmOpen = ref(false);

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const formatJson = (value) => JSON.stringify(value ?? {}, null, 2);
const statusVariant = (status) => {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'delivered') {
        return 'success';
    }
    if (normalized === 'failed') {
        return 'danger';
    }
    if (normalized === 'delivering') {
        return 'info';
    }
    return 'warning';
};

const copyText = async (text) => {
    const result = await copyTextToClipboard(text);
    if (result.ok) {
        noticeType.value = 'success';
        notice.value = 'Copied.';
        return;
    }

    noticeType.value = 'error';
    notice.value = result.message || 'Copy failed.';
};

const loadDelivery = async () => {
    loading.value = true;
    error.value = '';
    notice.value = '';

    try {
        const response = await getAdminWebhookDelivery(deliveryId.value);
        delivery.value = response.data?.data || null;
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load webhook delivery details.');
    } finally {
        loading.value = false;
    }
};

const openRetryConfirm = () => {
    confirmOpen.value = true;
};

const retryDelivery = async () => {
    retryLoading.value = true;
    error.value = '';

    try {
        await retryAdminWebhookDelivery(deliveryId.value);
        confirmOpen.value = false;
        await loadDelivery();
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to queue delivery retry.');
    } finally {
        retryLoading.value = false;
    }
};

loadDelivery();
</script>

<style scoped>
.panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 14px;
}

.panel-title {
    margin: 0 0 10px;
    color: #0f172a;
}

.kv-grid {
    display: grid;
    gap: 8px;
    color: #334155;
    font-size: 14px;
}

.kv-grid > div {
    min-width: 0;
    overflow-wrap: anywhere;
}

.json-box {
    margin: 0;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 10px;
    padding: 12px;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 12px;
}

.actions-row {
    margin-top: 10px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.primary-btn,
.secondary-btn {
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 9px 11px;
    background: #fff;
    color: #0f172a;
    cursor: pointer;
    font: inherit;
}

.link-btn {
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}

.primary-btn {
    background: #0f172a;
    border-color: #0f172a;
    color: #fff;
}

.primary-btn:disabled,
.secondary-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.state-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
}

.error {
    color: #b91c1c;
    margin: 0 0 10px;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    word-break: break-all;
}

.copy-notice {
    margin: 0 0 10px;
    font-size: 13px;
    color: #0369a1;
}

.copy-notice-error {
    color: #b91c1c;
}
</style>
