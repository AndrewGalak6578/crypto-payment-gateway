<template>
    <section class="page-stack dashboard-page">
        <header class="page-header dashboard-header">
            <div>
                <p class="page-kicker">Merchant operations</p>
                <h2 class="page-title">Dashboard</h2>
                <p class="page-subtitle">Revenue, in-flight payments, forwarding, wallet balances, and integration health.</p>
            </div>
            <div class="page-actions dashboard-actions">
                <RouterLink class="btn btn-secondary" :to="{ name: 'merchant-v2.payments' }">View payments</RouterLink>
                <RouterLink v-if="canCreateInvoices" class="btn btn-primary" :to="{ name: 'merchant-v2.create-payment' }">Create payment link</RouterLink>
            </div>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <section class="dashboard-period card card-pad">
            <div>
                <span>Current period</span>
                <strong>{{ dashboard.period?.label || 'This month' }}</strong>
            </div>
            <div>
                <span>Compared with</span>
                <strong>{{ dashboard.period?.comparison_label || 'Previous period' }}</strong>
            </div>
            <div>
                <span>Updated</span>
                <strong>{{ formatTime(dashboard.computed_at) }}</strong>
            </div>
        </section>

        <section class="finance-grid">
            <article v-for="metric in financeMetrics" :key="metric.key" class="card card-pad finance-card" :class="`finance-card-${metric.tone}`">
                <div class="finance-card-header">
                    <span>{{ metric.label }}</span>
                    <strong v-if="metric.badge" :class="`metric-badge metric-badge-${metric.tone}`">{{ metric.badge }}</strong>
                </div>
                <p class="finance-value">{{ loading ? '—' : metric.value }}</p>
                <p class="finance-note">{{ metric.note }}</p>
            </article>
        </section>

        <section class="dashboard-workspace">
            <div class="dashboard-main-column">
                <article class="card card-pad attention-card">
                    <div class="dashboard-section-header">
                        <div>
                            <h3 class="card-title">Needs attention</h3>
                            <p class="card-subtitle">Operational exceptions that can affect payer support or settlement.</p>
                        </div>
                        <span class="attention-count">{{ metrics.needs_attention_count || 0 }}</span>
                    </div>

                    <div class="attention-list">
                        <div v-for="item in attentionItems" :key="item.type" class="attention-item">
                            <span class="status-badge" :class="`status-${item.tone}`">{{ item.count }}</span>
                            <div>
                                <strong>{{ item.title }}</strong>
                                <p>{{ item.body }}</p>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="card card-pad asset-card">
                    <div class="dashboard-section-header">
                        <div>
                            <h3 class="card-title">Asset breakdown</h3>
                            <p class="card-subtitle">Paid volume for the current period.</p>
                        </div>
                    </div>

                    <div v-if="assetBreakdown.length" class="asset-breakdown">
                        <div v-for="asset in assetBreakdown" :key="asset.asset_key" class="asset-breakdown-row">
                            <AssetBadge :item="asset" />
                            <div>
                                <span>{{ asset.paid_count }} paid payment{{ Number(asset.paid_count) === 1 ? '' : 's' }}</span>
                            </div>
                            <b>{{ money(asset.received_usd) }}</b>
                        </div>
                    </div>
                    <div v-else class="empty-state">No paid volume in this period yet.</div>
                </article>

                <article class="card recent-card">
                    <div class="card-pad table-header">
                        <div>
                            <h3 class="card-title">Recent payment activity</h3>
                            <p class="card-subtitle">Latest invoices with business-readable status.</p>
                        </div>
                        <RouterLink class="btn btn-secondary" :to="{ name: 'merchant-v2.payments' }">Open all</RouterLink>
                    </div>

                    <div class="table-scroll dashboard-table">
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
                                    <td>{{ money(payment.expected_usd) }}</td>
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

                    <div class="mobile-payment-feed">
                        <button v-for="payment in recentPayments" :key="payment.id" class="mobile-payment-item" type="button" @click="openPayment(payment.id)">
                            <AssetLogo class="mobile-payment-logo" :item="payment" />
                            <span>
                                <strong>{{ payment.public_id }}</strong>
                                <small>{{ payment.external_id || formatDate(payment.created_at) }}</small>
                            </span>
                            <span>
                                <b>{{ money(payment.expected_usd) }}</b>
                                <PaymentStatusBadge :payment="payment" />
                            </span>
                        </button>
                    </div>
                </article>
            </div>

            <aside class="dashboard-side-column">
                <article class="card card-pad wallet-card">
                    <div class="dashboard-section-header">
                        <div>
                            <h3 class="card-title">Wallet estimate</h3>
                            <p class="card-subtitle">{{ walletEstimateSubtitle }}</p>
                        </div>
                    </div>
                    <p class="wallet-total">{{ money(metrics.wallet_estimate_usd) }}</p>
                    <div v-if="walletBalances.length" class="wallet-list">
                        <div v-for="balance in walletBalances" :key="balance.asset_key" class="wallet-row">
                            <AssetBadge :item="balance" />
                            <div>
                                <span>{{ formatAssetAmount(balance.amount) }}</span>
                            </div>
                            <b>{{ balance.estimated_usd ? money(balance.estimated_usd) : 'No rate' }}</b>
                        </div>
                    </div>
                    <div v-else class="empty-state">No merchant balances yet.</div>
                </article>

                <article class="card card-pad health-card">
                    <div class="dashboard-section-header">
                        <div>
                            <h3 class="card-title">Integration health</h3>
                            <p class="card-subtitle">Production readiness across API, webhooks, and settlement wallets.</p>
                        </div>
                    </div>
                    <div class="health-list">
                        <div v-for="item in healthItems" :key="item.label" class="health-row">
                            <span :class="item.ready ? 'health-dot health-dot-ready' : 'health-dot'"></span>
                            <div>
                                <strong>{{ item.label }}</strong>
                                <p>{{ item.body }}</p>
                            </div>
                            <RouterLink v-if="item.to && item.actionVisible" class="health-action" :to="item.to">{{ item.action }}</RouterLink>
                        </div>
                    </div>
                </article>
            </aside>
        </section>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { merchantApi } from '../services/merchantApi';
import AssetBadge from '../components/payments/AssetBadge.vue';
import AssetLogo from '../components/payments/AssetLogo.vue';
import PaymentStatusBadge from '../components/payments/PaymentStatusBadge.vue';

const router = useRouter();
const authStore = useAuthStore();
const loading = ref(true);
const error = ref('');
const dashboard = reactive({
    period: {},
    metrics: {},
    asset_breakdown: [],
    wallet_balances: [],
    attention: [],
    recent_payments: [],
    integration_health: {},
    computed_at: null,
});

const metrics = computed(() => dashboard.metrics || {});
const integration = computed(() => dashboard.integration_health || {});
const recentPayments = computed(() => dashboard.recent_payments || []);
const attentionItems = computed(() => dashboard.attention || []);
const assetBreakdown = computed(() => dashboard.asset_breakdown || []);
const walletBalances = computed(() => dashboard.wallet_balances || []);
const canCreateInvoices = computed(() => authStore.hasCapability('invoices.write'));
const canWriteWebhooks = computed(() => authStore.hasCapability('webhooks.write'));
const canWriteWallets = computed(() => authStore.hasCapability('wallets.write'));

const financeMetrics = computed(() => [
    {
        key: 'received',
        label: 'Received this month',
        value: money(metrics.value.received_month_usd),
        note: `${metrics.value.paid_count || 0} paid payment${Number(metrics.value.paid_count || 0) === 1 ? '' : 's'}`,
        badge: growthBadge(metrics.value.received_month_change_percent),
        tone: growthTone(metrics.value.received_month_change_percent),
    },
    {
        key: 'in-flight',
        label: 'In flight',
        value: money(metrics.value.in_flight_usd),
        note: `${metrics.value.awaiting_count || 0} awaiting · ${metrics.value.confirming_count || 0} confirming`,
        badge: metrics.value.underpaid_count ? `${metrics.value.underpaid_count} short` : 'Live',
        tone: metrics.value.underpaid_count ? 'warning' : 'info',
    },
    {
        key: 'forwarded',
        label: 'Forwarded this month',
        value: money(metrics.value.forwarded_month_usd),
        note: `${metrics.value.forwarded_count || 0} successful forward${Number(metrics.value.forwarded_count || 0) === 1 ? '' : 's'}`,
        badge: metrics.value.forwarding_failed_count ? `${metrics.value.forwarding_failed_count} failed` : 'Clear',
        tone: metrics.value.forwarding_failed_count ? 'danger' : 'success',
    },
    {
        key: 'wallet',
        label: 'Wallet estimate',
        value: money(metrics.value.wallet_estimate_usd),
        note: `${metrics.value.wallet_asset_count || 0} balance asset${Number(metrics.value.wallet_asset_count || 0) === 1 ? '' : 's'}`,
        badge: metrics.value.wallet_estimate_partial ? 'Partial' : 'Estimated',
        tone: metrics.value.wallet_estimate_partial ? 'warning' : 'neutral',
    },
]);

const healthItems = computed(() => [
    {
        label: 'API keys',
        ready: Boolean(integration.value.api_keys_ready),
        body: `${integration.value.active_api_keys_count || 0} active key${Number(integration.value.active_api_keys_count || 0) === 1 ? '' : 's'}`,
        to: { name: 'merchant-v2.developers' },
        action: 'Manage',
        actionVisible: true,
    },
    {
        label: 'Webhook endpoint',
        ready: Boolean(integration.value.webhook_ready),
        body: integration.value.webhook_ready ? `${integration.value.recent_webhook_failures || 0} recent failure${Number(integration.value.recent_webhook_failures || 0) === 1 ? '' : 's'}` : 'Endpoint or secret is missing',
        to: { name: 'merchant-v2.developers' },
        action: 'Configure',
        actionVisible: canWriteWebhooks.value,
    },
    {
        label: 'Settlement wallet',
        ready: Boolean(integration.value.settlement_wallet_ready),
        body: `${integration.value.settlement_wallet_count || 0} wallet${Number(integration.value.settlement_wallet_count || 0) === 1 ? '' : 's'} configured`,
        to: { name: 'merchant-v2.settlements' },
        action: 'Open',
        actionVisible: canWriteWallets.value,
    },
]);

const walletEstimateSubtitle = computed(() => (
    metrics.value.wallet_estimate_partial
        ? 'Estimated from balances where a recent rate is available.'
        : 'Estimated from merchant balances and latest known rates.'
));

const money = (value) => {
    const amount = Number.parseFloat(value || 0);
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(Number.isFinite(amount) ? amount : 0);
};

const formatAssetAmount = (value) => {
    const amount = Number.parseFloat(value || 0);
    if (!Number.isFinite(amount)) return '0';

    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: amount >= 1 ? 6 : 8,
    }).format(amount);
};

const growthBadge = (value) => {
    if (value === null || value === undefined) return 'No baseline';
    const amount = Number.parseFloat(value);
    if (!Number.isFinite(amount)) return 'No baseline';
    if (amount === 0) return '0%';
    return `${amount > 0 ? '+' : ''}${amount.toFixed(1)}%`;
};

const growthTone = (value) => {
    const amount = Number.parseFloat(value);
    if (!Number.isFinite(amount) || amount === 0) return 'neutral';
    return amount > 0 ? 'success' : 'danger';
};

const formatDate = (value) => (value ? new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '—');
const formatTime = (value) => (value ? new Date(value).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '—');
const openPayment = (id) => router.push({ name: 'merchant-v2.payment-detail', params: { paymentId: id } });

onMounted(async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await merchantApi.dashboard();
        Object.assign(dashboard, response.data?.data || {});
    } catch {
        error.value = 'Failed to load dashboard.';
    } finally {
        loading.value = false;
    }
});
</script>

<style scoped>
.dashboard-page {
    gap: 18px;
}

.dashboard-period {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1px;
    padding: 0;
    overflow: hidden;
    background: var(--m-border);
}

.dashboard-period div {
    min-width: 0;
    padding: 13px 16px;
    background: var(--m-surface);
}

.dashboard-period span {
    display: block;
    color: var(--m-muted);
    font-size: var(--m-xs);
    font-weight: 750;
}

.dashboard-period strong {
    display: block;
    margin-top: 4px;
    color: var(--m-text);
    font-size: var(--m-sm);
    font-weight: 850;
}

.finance-grid {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.finance-card {
    min-height: 166px;
    display: grid;
    align-content: space-between;
    gap: 18px;
}

.finance-card-header {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: flex-start;
}

.finance-card-header span,
.finance-note {
    color: var(--m-muted);
    font-size: var(--m-sm);
}

.metric-badge,
.attention-count {
    min-height: 26px;
    border-radius: var(--m-radius-pill);
    padding: 0 9px;
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
    font-size: 11px;
    font-weight: 850;
}

.metric-badge-success { background: var(--m-success-50); color: var(--m-success-700); }
.metric-badge-danger { background: var(--m-danger-50); color: var(--m-danger-700); }
.metric-badge-warning { background: var(--m-warning-50); color: var(--m-warning-700); }
.metric-badge-info { background: var(--m-info-50); color: var(--m-info-700); }
.metric-badge-neutral,
.attention-count { background: var(--m-surface-hover); color: var(--m-muted); }

.finance-value {
    margin: 0;
    color: var(--m-text);
    font-size: clamp(26px, 3vw, 34px);
    line-height: 1;
    font-weight: 850;
    font-variant-numeric: tabular-nums;
}

.finance-note {
    margin: 0;
    line-height: 1.4;
}

.dashboard-workspace {
    display: grid;
    gap: 16px;
}

.dashboard-main-column,
.dashboard-side-column {
    display: grid;
    align-content: start;
    gap: 16px;
}

.dashboard-section-header,
.table-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
}

.attention-list,
.health-list {
    display: grid;
    gap: 10px;
    margin-top: 16px;
}

.attention-item,
.health-row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 12px;
    align-items: start;
    padding: 12px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
}

.attention-item {
    grid-template-columns: auto minmax(0, 1fr);
}

.attention-item strong,
.health-row strong {
    display: block;
    color: var(--m-text);
    font-size: var(--m-sm);
}

.attention-item p,
.health-row p {
    margin: 4px 0 0;
    color: var(--m-muted);
    font-size: var(--m-xs);
    line-height: 1.4;
}

.asset-breakdown,
.wallet-list {
    display: grid;
    gap: 10px;
    margin-top: 16px;
}

.asset-breakdown-row,
.wallet-row {
    display: grid;
    grid-template-columns: minmax(128px, auto) minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
    min-height: 54px;
    padding: 11px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
}

.asset-breakdown-row .asset-badge,
.wallet-row .asset-badge {
    align-self: center;
}

.asset-breakdown-row .asset-badge :deep(.asset-logo),
.wallet-row .asset-badge :deep(.asset-logo) {
    transform: translateY(2px);
}

.asset-breakdown-row > div,
.wallet-row > div {
    min-width: 0;
    display: flex;
    align-items: center;
}

.asset-breakdown-row span,
.wallet-row span {
    display: block;
    color: var(--m-muted);
    font-size: var(--m-xs);
    line-height: 1.2;
}

.asset-breakdown-row b,
.wallet-row b {
    align-self: center;
    color: var(--m-text);
    font-size: var(--m-sm);
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
}

.wallet-total {
    margin: 18px 0 0;
    color: var(--m-text);
    font-size: 34px;
    line-height: 1;
    font-weight: 850;
    font-variant-numeric: tabular-nums;
}

.health-dot {
    width: 10px;
    height: 10px;
    margin-top: 5px;
    border-radius: 50%;
    background: var(--m-warning-500);
    box-shadow: 0 0 0 4px var(--m-warning-50);
}

.health-dot-ready {
    background: var(--m-success-500);
    box-shadow: 0 0 0 4px var(--m-success-50);
}

.health-action {
    color: var(--m-brand-700);
    font-size: var(--m-xs);
    font-weight: 850;
    text-decoration: none;
}

.mobile-payment-feed {
    display: none;
}

@media (min-width: 1180px) {
    .dashboard-workspace {
        grid-template-columns: minmax(0, 1fr) 390px;
        align-items: start;
    }

    .dashboard-side-column {
        position: sticky;
        top: calc(var(--m-topbar-height) + 24px);
    }
}

@media (max-width: 1080px) {
    .finance-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .dashboard-page {
        gap: 13px;
    }

    .dashboard-header {
        display: grid;
        gap: 10px;
        padding: 2px 2px 0;
    }

    .dashboard-header .page-kicker,
    .dashboard-header .page-subtitle {
        display: none;
    }

    .dashboard-header .page-title {
        font-size: 24px;
    }

    .dashboard-actions {
        display: grid;
        width: 100%;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .dashboard-actions .btn {
        width: 100%;
        min-height: 44px;
        padding-inline: 10px;
        white-space: nowrap;
    }

    .dashboard-actions .btn-primary {
        grid-column: 1 / -1;
    }

    .dashboard-period {
        grid-template-columns: 1fr;
        border-radius: 18px;
    }

    .dashboard-period div {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 11px 13px;
    }

    .dashboard-period strong {
        margin-top: 0;
        text-align: right;
    }

    .finance-grid {
        display: flex;
        gap: 10px;
        margin: 0 -12px;
        padding: 0 12px 2px;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        scrollbar-width: none;
    }

    .finance-grid::-webkit-scrollbar {
        display: none;
    }

    .finance-card {
        flex: 0 0 min(84vw, 318px);
        min-height: 154px;
        border-radius: 20px;
        scroll-snap-align: start;
    }

    .finance-value {
        font-size: 32px;
    }

    .dashboard-main-column,
    .dashboard-side-column,
    .dashboard-workspace {
        gap: 12px;
    }

    .dashboard-workspace,
    .dashboard-main-column,
    .dashboard-side-column {
        display: contents;
    }

    .dashboard-main-column > .card,
    .dashboard-side-column > .card {
        padding: 16px;
        border-radius: 18px;
    }

    .attention-card {
        order: 1;
    }

    .wallet-card {
        order: 2;
    }

    .recent-card {
        order: 3;
    }

    .asset-card {
        order: 4;
    }

    .health-card {
        order: 5;
    }

    .dashboard-section-header,
    .table-header {
        display: grid;
        gap: 10px;
    }

    .dashboard-section-header .attention-count,
    .table-header .btn {
        justify-self: start;
    }

    .recent-card {
        padding: 0 !important;
        overflow: hidden;
    }

    .dashboard-table {
        display: none;
    }

    .mobile-payment-feed {
        display: grid;
        gap: 8px;
        padding: 0 16px 16px;
    }

    .mobile-payment-item {
        width: 100%;
        min-height: 68px;
        display: grid;
        grid-template-columns: 38px minmax(0, 1fr) auto;
        gap: 10px;
        align-items: center;
        border: 1px solid var(--m-border);
        border-radius: 16px;
        background: var(--m-surface);
        padding: 10px;
        text-align: left;
    }

    .mobile-payment-logo.asset-logo {
        width: 38px;
        height: 38px;
    }

    .mobile-payment-logo.asset-logo svg {
        width: 24px;
        height: 24px;
    }

    .mobile-payment-item strong,
    .mobile-payment-item b {
        display: block;
        color: var(--m-text);
        font-size: var(--m-sm);
    }

    .mobile-payment-item small {
        display: block;
        margin-top: 2px;
        color: var(--m-muted);
        font-size: var(--m-xs);
    }

    .mobile-payment-item > span:last-child {
        display: grid;
        justify-items: end;
        gap: 5px;
        min-width: 92px;
    }

    .attention-item,
    .health-row {
        grid-template-columns: auto minmax(0, 1fr);
        border-radius: 16px;
    }

    .health-action {
        grid-column: 2;
        justify-self: start;
    }

    .asset-breakdown-row,
    .wallet-row {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 8px 10px;
        min-height: 0;
        padding: 12px;
        border-radius: 16px;
        align-items: center;
    }

    .asset-breakdown-row > div,
    .wallet-row > div {
        grid-column: 1 / -1;
        align-items: flex-start;
    }

    .asset-breakdown-row b,
    .wallet-row b {
        justify-self: end;
        text-align: right;
    }
}

@media (max-width: 420px) {
    .dashboard-actions {
        grid-template-columns: 1fr;
    }

    .dashboard-actions .btn-primary {
        grid-column: auto;
    }

    .finance-card {
        flex-basis: min(88vw, 330px);
    }

    .mobile-payment-item {
        grid-template-columns: 38px minmax(0, 1fr);
    }

    .mobile-payment-item > span:last-child {
        grid-column: 2;
        justify-items: start;
        min-width: 0;
    }
}
</style>
