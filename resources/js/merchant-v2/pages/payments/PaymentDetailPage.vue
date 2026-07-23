<template>
    <section class="page-stack payment-detail-page">
        <header class="page-header">
            <div>
                <p class="page-kicker">Payment detail</p>
                <h2 class="page-title">{{ payment.public_id || `Payment #${paymentId}` }}</h2>
                <p class="page-subtitle">{{ payment.external_id || 'No merchant reference' }}</p>
            </div>
            <div class="page-actions detail-desktop-actions">
                <RouterLink class="btn btn-secondary" :to="backTarget.to">{{ backTarget.desktopLabel }}</RouterLink>
                <button class="btn btn-secondary" type="button" :disabled="refreshing" @click="refresh">{{ refreshing ? 'Refreshing...' : 'Refresh' }}</button>
                <button class="btn btn-primary" type="button" :disabled="!payment.hosted_url" @click="copy(payment.hosted_url, 'Hosted link copied.')">Copy checkout link</button>
            </div>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div v-if="copied" class="alert alert-success">{{ copied }}</div>

        <template v-if="loading">
            <section class="detail-hero card card-pad">
                <div class="skeleton detail-skeleton-title"></div>
                <div class="skeleton detail-skeleton-line"></div>
            </section>
        </template>

        <template v-else>
            <section class="detail-hero card card-pad">
                <div class="detail-hero-main">
                    <AssetLogo class="detail-asset-logo" :item="payment" />
                    <div>
                        <PaymentStatusBadge :payment="payment" />
                        <p class="detail-amount">{{ payment.expected_usd || '—' }} USD</p>
                        <p class="card-subtitle">{{ displayAssetNetwork(payment) }}</p>
                    </div>
                </div>

                <div class="detail-hero-actions">
                    <a v-if="payment.hosted_url" class="btn btn-secondary" :href="payment.hosted_url" target="_blank" rel="noopener noreferrer">Open checkout</a>
                    <button class="btn btn-secondary" type="button" :disabled="!payment.pay_address" @click="copy(payment.pay_address, 'Payment address copied.')">Copy address</button>
                </div>
            </section>

            <nav class="detail-mobile-dock" aria-label="Payment actions">
                <RouterLink class="btn btn-secondary" :to="backTarget.to">{{ backTarget.mobileLabel }}</RouterLink>
                <button class="btn btn-secondary" type="button" :disabled="refreshing" @click="refresh">{{ refreshing ? 'Refreshing...' : 'Refresh' }}</button>
                <button class="btn btn-primary" type="button" :disabled="!payment.hosted_url" @click="copy(payment.hosted_url, 'Hosted link copied.')">Copy link</button>
            </nav>

            <section class="detail-summary-grid">
                <article class="card card-pad detail-summary-card">
                    <span>Expected</span>
                    <strong>{{ payment.amount_coin || '—' }}</strong>
                    <small>{{ displayAssetNetwork(payment) }}</small>
                </article>
                <article class="card card-pad detail-summary-card">
                    <span>Received</span>
                    <strong>{{ payment.received_all_coin || '0' }}</strong>
                    <small>Confirmed {{ payment.received_conf_coin || '0' }}</small>
                </article>
                <article class="card card-pad detail-summary-card">
                    <span>Merchant payout</span>
                    <strong>{{ payment.merchant_payout_coin || payment.merchant_payout_usd || '—' }}</strong>
                    <small>Fee {{ payment.fee_coin || payment.fee_usd || '—' }}</small>
                </article>
                <article class="card card-pad detail-summary-card">
                    <span>Expires</span>
                    <strong>{{ compactDate(payment.expires_at) }}</strong>
                    <small>Created {{ compactDate(payment.created_at) }}</small>
                </article>
            </section>

            <section class="payment-detail-layout">
                <div class="detail-main-column">
                    <article class="card card-pad">
                        <div class="detail-section-header">
                            <div>
                                <h3 class="card-title">Lifecycle</h3>
                                <p class="card-subtitle">{{ nextAction.body }}</p>
                            </div>
                            <span class="detail-tone" :class="`detail-tone-${nextAction.tone}`">{{ nextAction.title }}</span>
                        </div>
                        <PaymentLifecycle :payment="payment" />
                    </article>

                    <article class="card card-pad">
                        <div class="detail-section-header">
                            <div>
                                <h3 class="card-title">Checkout and payer experience</h3>
                                <p class="card-subtitle">Hosted checkout fields visible to the payer.</p>
                            </div>
                        </div>
                        <div class="detail-list">
                            <div class="detail-row">
                                <span class="detail-label">Hosted checkout</span>
                                <span class="detail-value">{{ payment.hosted_url || '—' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Payment address</span>
                                <span class="detail-value">{{ payment.pay_address || 'Allocated after asset selection' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">First transaction</span>
                                <span class="detail-value">{{ payment.first_txid || 'Not detected' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">First amount</span>
                                <span class="detail-value">{{ payment.first_amount_coin || '—' }}</span>
                            </div>
                        </div>
                    </article>

                    <article v-if="canViewWebhooks" class="card card-pad">
                        <div class="detail-section-header">
                            <div>
                                <h3 class="card-title">Webhook delivery</h3>
                                <p class="card-subtitle">Visible because your role can read webhook activity.</p>
                            </div>
                            <span class="detail-count">{{ webhookDeliveries.length }}</span>
                        </div>

                        <div v-if="webhookDeliveries.length" class="webhook-list">
                            <div v-for="delivery in webhookDeliveries" :key="delivery.id" class="webhook-item">
                                <div>
                                    <strong>{{ delivery.event }}</strong>
                                    <p>{{ delivery.url || 'No URL' }}</p>
                                </div>
                                <div class="webhook-meta">
                                    <span :class="`webhook-status webhook-status-${delivery.status || 'unknown'}`">{{ delivery.status || 'unknown' }}</span>
                                    <small>{{ delivery.attempts }} attempt{{ Number(delivery.attempts) === 1 ? '' : 's' }}</small>
                                </div>
                                <div class="webhook-actions">
                                    <button
                                        class="webhook-action-button"
                                        type="button"
                                        :disabled="webhookDetailLoading && selectedWebhookDelivery?.id === delivery.id"
                                        @click="inspectWebhook(delivery)"
                                    >
                                        {{ webhookDetailLoading && selectedWebhookDelivery?.id === delivery.id ? 'Loading...' : 'View payload' }}
                                    </button>
                                    <button
                                        v-if="canRetryWebhooks && delivery.status !== 'delivered'"
                                        class="webhook-action-button"
                                        type="button"
                                        :disabled="retryingDeliveryId === delivery.id"
                                        @click="retryWebhook(delivery)"
                                    >
                                        {{ retryingDeliveryId === delivery.id ? 'Retrying...' : 'Retry delivery' }}
                                    </button>
                                </div>
                                <p v-if="delivery.last_error" class="webhook-error">{{ delivery.last_error }}</p>
                            </div>
                        </div>
                        <div v-if="webhookDetailError" class="alert alert-danger webhook-detail-alert">{{ webhookDetailError }}</div>
                        <div v-if="selectedWebhookDelivery" class="webhook-detail-panel">
                            <div class="webhook-detail-header">
                                <div>
                                    <span>Delivery #{{ selectedWebhookDelivery.id }}</span>
                                    <strong>{{ selectedWebhookDelivery.event }}</strong>
                                </div>
                                <button class="webhook-action-button" type="button" @click="copyWebhookPayload">Copy payload</button>
                            </div>
                            <div class="detail-list detail-list-compact">
                                <div class="detail-row">
                                    <span class="detail-label">Status</span>
                                    <span class="detail-value">{{ selectedWebhookDelivery.status || '—' }}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Attempts</span>
                                    <span class="detail-value">{{ selectedWebhookDelivery.attempts ?? '—' }}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">URL</span>
                                    <span class="detail-value">{{ selectedWebhookDelivery.url || '—' }}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Delivered</span>
                                    <span class="detail-value">{{ formatDate(selectedWebhookDelivery.delivered_at) }}</span>
                                </div>
                            </div>
                            <p v-if="selectedWebhookDelivery.last_error" class="webhook-error">{{ selectedWebhookDelivery.last_error }}</p>
                            <pre class="webhook-payload-box">{{ webhookPayloadPreview }}</pre>
                        </div>
                        <div v-if="!webhookDeliveries.length" class="empty-state">No webhook deliveries recorded for this payment.</div>
                    </article>

                    <article class="card card-pad">
                        <div class="detail-section-header">
                            <div>
                                <h3 class="card-title">Merchant metadata</h3>
                                <p class="card-subtitle">Merchant-defined data attached to this payment.</p>
                            </div>
                        </div>
                        <div v-if="metadataEntries.length" class="metadata-grid">
                            <div v-for="[key, value] in metadataEntries" :key="key" class="metadata-row">
                                <span>{{ key }}</span>
                                <strong>{{ stringifyValue(value) }}</strong>
                            </div>
                        </div>
                        <div v-else class="empty-state">No metadata attached.</div>
                    </article>
                </div>

                <aside class="detail-side-column">
                    <article class="card card-pad">
                        <h3 class="card-title">Access</h3>
                        <div class="detail-list detail-list-compact">
                            <div class="detail-row">
                                <span class="detail-label">Role</span>
                                <span class="detail-value">{{ authStore.role?.name || authStore.role?.slug || '—' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Webhook visibility</span>
                                <span class="detail-value">{{ canViewWebhooks ? 'Allowed' : 'Hidden' }}</span>
                            </div>
                        </div>
                    </article>

                    <article class="card card-pad">
                        <h3 class="card-title">Settlement outcome</h3>
                        <div class="detail-list detail-list-compact">
                            <div class="detail-row">
                                <span class="detail-label">Forward status</span>
                                <span class="status-badge" :class="`status-${settlementStatus.tone}`">{{ settlementStatus.label }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Forwarded</span>
                                <span class="detail-value">{{ payment.forwarded_coin || '—' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Last forwarded</span>
                                <span class="detail-value">{{ formatDate(payment.last_forwarded_at) }}</span>
                            </div>
                        </div>
                    </article>

                    <article class="card card-pad">
                        <details class="technical-details">
                            <summary>Technical details</summary>
                            <pre class="metadata-box">{{ JSON.stringify(payment, null, 2) }}</pre>
                        </details>
                    </article>
                </aside>
            </section>
        </template>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../../../stores/auth';
import { merchantApi } from '../../services/merchantApi';
import { useCopy } from '../../composables/useCopy';
import { displayAssetNetwork } from '../../../utils/assetDisplay';
import AssetLogo from '../../components/payments/AssetLogo.vue';
import PaymentLifecycle from '../../components/payments/PaymentLifecycle.vue';
import PaymentStatusBadge from '../../components/payments/PaymentStatusBadge.vue';
import { forwardStatusMeta, paymentNextAction } from '../../components/payments/paymentStatus';

const props = defineProps({
    paymentId: {
        type: [String, Number],
        required: true,
    },
});

const { copied, copy } = useCopy();
const route = useRoute();
const authStore = useAuthStore();
const loading = ref(true);
const refreshing = ref(false);
const retryingDeliveryId = ref(null);
const webhookDetailLoading = ref(false);
const webhookDetailError = ref('');
const selectedWebhookDelivery = ref(null);
const error = ref('');
const payment = ref({});
const backToPaymentsQuery = computed(() => ({
    ...route.query,
    from: undefined,
    selected: props.paymentId,
}));
const backTarget = computed(() => {
    if (route.query.from === 'settlements') {
        return {
            to: { name: 'merchant-v2.settlements' },
            desktopLabel: 'Back to settlements',
            mobileLabel: 'Settlements',
        };
    }

    return {
        to: { name: 'merchant-v2.payments', query: backToPaymentsQuery.value },
        desktopLabel: 'Back to payments',
        mobileLabel: 'Payments',
    };
});
const canViewWebhooks = computed(() => authStore.hasCapability('webhooks.read'));
const canRetryWebhooks = computed(() => authStore.hasCapability('webhooks.write'));
const webhookDeliveries = computed(() => Array.isArray(payment.value.webhook_deliveries) ? payment.value.webhook_deliveries : []);
const webhookPayloadPreview = computed(() => {
    const payload = selectedWebhookDelivery.value?.payload ?? selectedWebhookDelivery.value?.payload_preview ?? {};
    return typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
});
const metadataEntries = computed(() => Object.entries(payment.value.metadata || {}));
const nextAction = computed(() => paymentNextAction(payment.value));
const settlementStatus = computed(() => forwardStatusMeta(payment.value));
const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const compactDate = (value) => {
    if (!value) return '—';
    return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
};
const stringifyValue = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
};

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        const response = await merchantApi.payment(props.paymentId);
        payment.value = response.data?.data || {};
    } catch {
        error.value = 'Failed to load payment.';
    } finally {
        loading.value = false;
    }
};

const refresh = async () => {
    refreshing.value = true;
    error.value = '';
    try {
        const response = await merchantApi.refreshPayment(props.paymentId);
        payment.value = response.data?.data || {};
    } catch {
        error.value = 'Failed to refresh payment.';
    } finally {
        refreshing.value = false;
    }
};

const retryWebhook = async (delivery) => {
    retryingDeliveryId.value = delivery.id;
    error.value = '';

    try {
        await merchantApi.retryWebhookDelivery(delivery.id);
        await refresh();
    } catch {
        error.value = 'Failed to queue webhook retry.';
    } finally {
        retryingDeliveryId.value = null;
    }
};

const inspectWebhook = async (delivery) => {
    webhookDetailLoading.value = true;
    webhookDetailError.value = '';
    selectedWebhookDelivery.value = { id: delivery.id, event: delivery.event };

    try {
        const response = await merchantApi.webhookDelivery(delivery.id);
        selectedWebhookDelivery.value = response.data?.data || null;
    } catch {
        webhookDetailError.value = 'Failed to load webhook delivery details.';
    } finally {
        webhookDetailLoading.value = false;
    }
};

const copyWebhookPayload = async () => {
    await copy(webhookPayloadPreview.value, 'Webhook payload copied.');
};

watch(() => props.paymentId, load, { immediate: true });
</script>

<style scoped>
.detail-hero {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    flex-wrap: wrap;
}

.detail-mobile-dock {
    display: none;
}

.detail-hero-main {
    display: flex;
    gap: 16px;
    align-items: center;
    min-width: 0;
}

.detail-asset-logo.asset-logo {
    width: 58px;
    height: 58px;
}

:deep(.detail-asset-logo.asset-logo svg) {
    width: 36px;
    height: 36px;
}

.detail-hero-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.detail-amount {
    margin: 12px 0 0;
    font-size: 36px;
    line-height: 1;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.detail-summary-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
}

.detail-summary-card {
    display: grid;
    gap: 5px;
}

.detail-summary-card span,
.detail-summary-card small {
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.detail-summary-card strong {
    color: var(--m-text);
    font-size: 22px;
    line-height: 1.15;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.payment-detail-layout {
    display: grid;
    gap: 16px;
}

.detail-main-column,
.detail-side-column {
    display: grid;
    align-content: start;
    gap: 16px;
}

.detail-section-header {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    align-items: flex-start;
    margin-bottom: 16px;
}

.detail-tone,
.detail-count {
    min-height: 28px;
    border-radius: var(--m-radius-pill);
    padding: 0 10px;
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
    font-size: var(--m-xs);
    font-weight: 800;
}

.detail-tone-info { background: var(--m-info-50); color: var(--m-info-700); }
.detail-tone-warning { background: var(--m-warning-50); color: var(--m-warning-700); }
.detail-tone-success { background: var(--m-success-50); color: var(--m-success-700); }
.detail-tone-danger { background: var(--m-danger-50); color: var(--m-danger-700); }
.detail-tone-neutral,
.detail-count { background: var(--m-surface-hover); color: var(--m-muted); }

.webhook-list {
    display: grid;
    gap: 10px;
}

.webhook-item {
    display: grid;
    gap: 8px;
    padding: 12px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
}

.webhook-item strong {
    color: var(--m-text);
    font-size: var(--m-sm);
}

.webhook-item p {
    margin: 4px 0 0;
    color: var(--m-muted);
    font-size: var(--m-xs);
    overflow-wrap: anywhere;
}

.webhook-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.webhook-status {
    min-height: 24px;
    border-radius: var(--m-radius-pill);
    padding: 0 9px;
    display: inline-flex;
    align-items: center;
    background: var(--m-surface-hover);
    color: var(--m-muted);
    font-size: 11px;
    font-weight: 800;
}

.webhook-status-delivered,
.webhook-status-success {
    background: var(--m-success-50);
    color: var(--m-success-700);
}

.webhook-status-failed {
    background: var(--m-danger-50);
    color: var(--m-danger-700);
}

.webhook-status-pending,
.webhook-status-retrying {
    background: var(--m-warning-50);
    color: var(--m-warning-700);
}

.webhook-error {
    color: var(--m-danger-700) !important;
}

.webhook-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.webhook-action-button {
    justify-self: start;
    min-height: 30px;
    border: 1px solid var(--m-border-strong);
    border-radius: var(--m-radius-md);
    background: var(--m-surface);
    color: var(--m-brand-700);
    padding: 0 10px;
    font-size: var(--m-xs);
    font-weight: 800;
}

.webhook-action-button:hover {
    border-color: var(--m-brand-100);
    background: var(--m-brand-50);
}

.webhook-action-button:disabled {
    cursor: wait;
}

.webhook-detail-alert {
    margin-top: 12px;
}

.webhook-detail-panel {
    display: grid;
    gap: 12px;
    margin-top: 14px;
    padding: 14px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: #ffffff;
}

.webhook-detail-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
}

.webhook-detail-header span {
    display: block;
    color: var(--m-muted);
    font-size: var(--m-xs);
    font-weight: 700;
}

.webhook-detail-header strong {
    display: block;
    margin-top: 3px;
    color: var(--m-text);
    font-size: var(--m-sm);
}

.webhook-payload-box {
    max-height: 320px;
    overflow: auto;
    margin: 0;
    padding: 12px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
    color: var(--m-text);
    font-size: 12px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.metadata-grid {
    display: grid;
    gap: 1px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    overflow: hidden;
}

.metadata-row {
    display: grid;
    grid-template-columns: 160px minmax(0, 1fr);
    gap: 12px;
    padding: 11px 12px;
    background: var(--m-surface);
}

.metadata-row span {
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.metadata-row strong {
    min-width: 0;
    color: var(--m-text);
    font-size: var(--m-sm);
    overflow-wrap: anywhere;
}

.detail-list-compact .detail-row {
    grid-template-columns: 110px minmax(0, 1fr);
}

.technical-details summary {
    cursor: pointer;
    color: var(--m-text);
    font-weight: 800;
}

.metadata-box {
    max-height: 360px;
    overflow: auto;
    margin: 12px 0 0;
    padding: 12px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
    color: var(--m-text);
    font-size: 12px;
}

.detail-skeleton-title {
    width: min(420px, 100%);
    height: 36px;
}

.detail-skeleton-line {
    width: min(260px, 80%);
    height: 16px;
}

@media (min-width: 1100px) {
    .payment-detail-layout {
        grid-template-columns: minmax(0, 1fr) minmax(320px, 0.42fr);
        align-items: start;
    }

    .detail-side-column {
        position: sticky;
        top: calc(var(--m-topbar-height) + 24px);
    }
}

@media (max-width: 720px) {
    .payment-detail-page {
        gap: 14px;
        padding-bottom: 76px;
    }

    .payment-detail-page .page-header {
        gap: 8px;
        padding: 2px 2px 0;
    }

    .payment-detail-page .page-kicker,
    .payment-detail-page .page-subtitle {
        display: none;
    }

    .payment-detail-page .page-title {
        font-size: 18px;
        line-height: 1.18;
        overflow-wrap: anywhere;
    }

    .detail-desktop-actions {
        display: none;
    }

    .detail-mobile-dock {
        position: fixed;
        inset: auto 12px 68px;
        z-index: 18;
        display: grid;
        grid-template-columns: 0.78fr 0.78fr 1fr;
        gap: 8px;
        padding: 8px;
        border: 1px solid rgba(214, 221, 232, 0.92);
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.94);
        box-shadow: 0 16px 40px rgba(16, 24, 40, 0.16);
        backdrop-filter: blur(16px);
    }

    .detail-mobile-dock .btn {
        min-height: 44px;
        padding: 0 10px;
        border-radius: 13px;
        white-space: nowrap;
    }

    .detail-hero-actions {
        display: none;
    }

    .detail-hero {
        display: grid;
        gap: 16px;
        padding: 18px;
        border-radius: 20px;
        border-color: #dbe7ff;
        background:
            linear-gradient(180deg, rgba(238, 245, 255, 0.82), rgba(255, 255, 255, 0.96) 52%),
            var(--m-surface);
        box-shadow: 0 18px 44px rgba(16, 24, 40, 0.10);
    }

    .detail-hero-main {
        display: grid;
        grid-template-columns: 52px minmax(0, 1fr);
        align-items: start;
        gap: 12px;
    }

    .detail-asset-logo.asset-logo {
        width: 52px;
        height: 52px;
    }

    :deep(.detail-asset-logo.asset-logo svg) {
        width: 32px;
        height: 32px;
    }

    .detail-amount {
        margin-top: 12px;
        font-size: clamp(30px, 9vw, 38px);
        letter-spacing: -0.01em;
    }

    .detail-hero .card-subtitle {
        margin-top: 8px;
        font-size: 13px;
    }

    .detail-summary-grid {
        display: flex;
        gap: 10px;
        margin: 0 -12px;
        padding: 0 12px 3px;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        scrollbar-width: none;
    }

    .detail-summary-grid::-webkit-scrollbar {
        display: none;
    }

    .detail-summary-card {
        flex: 0 0 min(74vw, 250px);
        min-width: 0;
        padding: 14px;
        border-radius: 16px;
        scroll-snap-align: start;
    }

    .detail-summary-card strong {
        font-size: 20px;
        overflow-wrap: anywhere;
    }

    .detail-summary-card small {
        line-height: 1.35;
    }

    .payment-detail-layout,
    .detail-main-column,
    .detail-side-column {
        gap: 12px;
    }

    .detail-main-column > .card,
    .detail-side-column > .card {
        padding: 16px;
        border-radius: 18px;
    }

    .detail-section-header {
        display: grid;
        gap: 10px;
        margin-bottom: 14px;
    }

    .detail-tone,
    .detail-count {
        justify-self: start;
        white-space: normal;
        line-height: 1.25;
        padding: 6px 10px;
    }

    .detail-list .detail-row,
    .detail-list-compact .detail-row {
        grid-template-columns: 1fr;
        gap: 4px;
        padding: 10px 11px;
    }

    .webhook-item {
        padding: 13px;
        border-radius: 15px;
    }

    .webhook-meta {
        align-items: flex-start;
    }

    .webhook-actions {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .webhook-action-button {
        width: 100%;
        min-height: 36px;
        justify-content: center;
    }

    .webhook-detail-panel {
        margin-top: 12px;
        padding: 12px;
        border-radius: 12px;
    }

    .webhook-detail-header {
        display: grid;
        gap: 10px;
    }

    .webhook-payload-box,
    .metadata-box {
        max-height: 260px;
        font-size: 11px;
    }

    .metadata-row {
        grid-template-columns: 1fr;
        gap: 4px;
    }

    .metadata-grid,
    .detail-list {
        border-radius: 12px;
    }

    .technical-details summary {
        min-height: 38px;
        display: flex;
        align-items: center;
    }
}

@media (max-width: 420px) {
    .detail-mobile-dock {
        inset-inline: 10px;
        grid-template-columns: 0.7fr 0.72fr 1fr;
        gap: 6px;
        padding: 7px;
    }

    .detail-mobile-dock .btn {
        font-size: 12px;
        padding: 0 8px;
    }
}
</style>
