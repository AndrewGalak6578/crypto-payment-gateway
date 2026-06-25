<template>
    <section class="page-stack">
        <header class="page-header">
            <div>
                <p class="page-kicker">Payments workspace</p>
                <h2 class="page-title">Dashboard</h2>
                <p class="page-subtitle">Track payment activity, setup health, balances, and operational exceptions.</p>
            </div>
            <div class="page-actions">
                <RouterLink class="btn btn-secondary" :to="{ name: 'merchant-v2.payments' }">View payments</RouterLink>
                <RouterLink class="btn btn-primary" :to="{ name: 'merchant-v2.create-payment' }">Create payment link</RouterLink>
            </div>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <section class="metric-grid">
            <article v-for="metric in metrics" :key="metric.label" class="card metric-card">
                <p class="metric-label">{{ metric.label }}</p>
                <p class="metric-value">{{ loading ? '—' : metric.value }}</p>
                <p class="metric-note">{{ metric.note }}</p>
            </article>
        </section>

        <section class="dashboard-grid">
            <article class="card card-pad">
                <h3 class="card-title">Operational inbox</h3>
                <p class="card-subtitle">Items that likely need attention.</p>

                <div class="inbox-list">
                    <div v-for="item in inboxItems" :key="item.label" class="inbox-item">
                        <span class="status-badge" :class="`status-${item.tone}`">{{ item.count }}</span>
                        <div>
                            <strong>{{ item.label }}</strong>
                            <p class="card-subtitle">{{ item.help }}</p>
                        </div>
                    </div>
                </div>
            </article>

            <article class="card card-pad">
                <h3 class="card-title">Setup health</h3>
                <p class="card-subtitle">Minimum pieces for production payment acceptance.</p>

                <div class="inbox-list">
                    <div v-for="item in setupItems" :key="item.label" class="inbox-item">
                        <span class="status-badge" :class="item.done ? 'status-success' : 'status-warning'">{{ item.done ? 'Ready' : 'Todo' }}</span>
                        <div>
                            <strong>{{ item.label }}</strong>
                            <p class="card-subtitle">{{ item.help }}</p>
                        </div>
                    </div>
                </div>
            </article>
        </section>

        <article class="card">
            <div class="card-pad table-header">
                <div>
                    <h3 class="card-title">Recent payment events</h3>
                    <p class="card-subtitle">Latest invoices with business-readable status.</p>
                </div>
                <RouterLink class="btn btn-secondary" :to="{ name: 'merchant-v2.payments' }">Open all</RouterLink>
            </div>

            <div class="table-scroll">
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Asset</th>
                            <th>Amount</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="payment in recentPayments" :key="payment.id" @click="openPayment(payment.id)">
                            <td>
                                <div class="payment-primary">
                                    <span class="payment-id">{{ payment.public_id }}</span>
                                    <span class="payment-meta">{{ payment.external_id || 'No reference' }}</span>
                                </div>
                            </td>
                            <td><PaymentStatusBadge :payment="payment" /></td>
                            <td><AssetBadge :item="payment" /></td>
                            <td>{{ payment.expected_usd || '—' }} USD</td>
                            <td>{{ formatDate(payment.created_at) }}</td>
                        </tr>
                        <tr v-if="!recentPayments.length">
                            <td colspan="5">
                                <div class="empty-state">No recent payments yet.</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { merchantApi } from '../services/merchantApi';
import AssetBadge from '../components/payments/AssetBadge.vue';
import PaymentStatusBadge from '../components/payments/PaymentStatusBadge.vue';
import { normalizePaymentStatus } from '../components/payments/paymentStatus';

const router = useRouter();
const loading = ref(true);
const error = ref('');
const dashboard = reactive({ stats: {}, balances: [], wallets: [], recent_invoices: [] });
const apiKeys = ref([]);
const webhookSettings = ref({});
const webhookDeliveries = ref([]);

const recentPayments = computed(() => dashboard.recent_invoices || []);
const failedWebhookCount = computed(() => webhookDeliveries.value.filter((item) => item.status === 'failed').length);
const activeCount = computed(() => recentPayments.value.filter((item) => ['awaiting_asset', 'pending', 'partial', 'confirming'].includes(normalizePaymentStatus(item))).length);
const expiredCount = computed(() => recentPayments.value.filter((item) => normalizePaymentStatus(item) === 'expired').length);
const partialCount = computed(() => recentPayments.value.filter((item) => normalizePaymentStatus(item) === 'partial').length);

const metrics = computed(() => [
    { label: 'Paid invoices', value: dashboard.stats?.paid_invoices_count ?? 0, note: 'Completed payment count' },
    { label: 'Active invoices', value: activeCount.value, note: 'Awaiting asset, payment, or confirmation' },
    { label: 'Failed webhooks', value: failedWebhookCount.value, note: 'Delivery issues to inspect' },
    { label: 'Setup health', value: `${setupItems.value.filter((item) => item.done).length}/${setupItems.value.length}`, note: 'Production readiness checklist' },
]);

const inboxItems = computed(() => [
    { label: 'Underpaid payments', count: partialCount.value, tone: partialCount.value ? 'warning' : 'neutral', help: 'Payer sent less than required.' },
    { label: 'Expired payments', count: expiredCount.value, tone: expiredCount.value ? 'danger' : 'neutral', help: 'Checkout can no longer be paid.' },
    { label: 'Webhook failures', count: failedWebhookCount.value, tone: failedWebhookCount.value ? 'danger' : 'neutral', help: 'Merchant integration did not receive events.' },
]);

const setupItems = computed(() => [
    { label: 'Settlement wallet', done: (dashboard.wallets || []).length > 0, help: 'At least one forwarding wallet is configured.' },
    { label: 'API key', done: apiKeys.value.some((item) => !item.revoked_at), help: 'Server integration can create invoices.' },
    { label: 'Webhook endpoint', done: Boolean(webhookSettings.value.webhook_url), help: 'Payment lifecycle events can be delivered.' },
]);

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const openPayment = (id) => router.push({ name: 'merchant-v2.payment-detail', params: { paymentId: id } });

onMounted(async () => {
    loading.value = true;
    error.value = '';

    try {
        const [dashboardResponse, apiKeysResponse, webhookSettingsResponse, deliveriesResponse] = await Promise.allSettled([
            merchantApi.dashboard(),
            merchantApi.apiKeys(),
            merchantApi.webhookSettings(),
            merchantApi.webhookDeliveries({ per_page: 20 }),
        ]);

        if (dashboardResponse.status === 'fulfilled') Object.assign(dashboard, dashboardResponse.value.data?.data || {});
        if (apiKeysResponse.status === 'fulfilled') apiKeys.value = apiKeysResponse.value.data?.data || [];
        if (webhookSettingsResponse.status === 'fulfilled') webhookSettings.value = webhookSettingsResponse.value.data?.data || {};
        if (deliveriesResponse.status === 'fulfilled') {
            const payload = deliveriesResponse.value.data?.data;
            webhookDeliveries.value = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : [];
        }
    } catch {
        error.value = 'Failed to load dashboard.';
    } finally {
        loading.value = false;
    }
});
</script>

<style scoped>
.dashboard-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
}

.inbox-list {
    display: grid;
    gap: 12px;
    margin-top: 16px;
}

.inbox-item {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 12px;
    align-items: start;
}

.table-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
</style>
