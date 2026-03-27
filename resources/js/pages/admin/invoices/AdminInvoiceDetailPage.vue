<template>
    <section>
        <PageHeader :title="`Invoice #${invoiceId}`" subtitle="Invoice lifecycle, forwarding and delivery context.">
            <template #actions>
                <button type="button" class="secondary-btn" :disabled="loading" @click="loadInvoice">Refresh data</button>
                <button type="button" class="primary-btn" :disabled="actionLoading" @click="refreshInvoice">
                    {{ actionLoading ? 'Rechecking...' : 'Refresh/Recheck invoice' }}
                </button>
                <button type="button" class="secondary-btn" @click="router.push({ name: 'admin.invoices' })">Back</button>
            </template>
        </PageHeader>

        <LoadingState v-if="loading" text="Loading invoice details..." />

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadInvoice">Retry</button>
        </div>

        <template v-else-if="invoice">
            <article class="panel">
                <h3 class="panel-title">Base data</h3>
                <div class="kv-grid">
                    <div><strong>ID:</strong> {{ invoice.id }}</div>
                    <div><strong>public_id:</strong> {{ invoice.public_id }}</div>
                    <div><strong>external_id:</strong> {{ invoice.external_id || '—' }}</div>
                    <div><strong>status:</strong> {{ invoice.status }}</div>
                    <div><strong>coin:</strong> {{ invoice.coin || '—' }}</div>
                    <div><strong>pay_address:</strong> {{ invoice.pay_address || '—' }}</div>
                    <div><strong>amount_coin:</strong> {{ invoice.amount_coin ?? '—' }}</div>
                    <div><strong>expected_usd:</strong> {{ invoice.expected_usd ?? '—' }}</div>
                    <div><strong>received_conf_coin:</strong> {{ invoice.received_conf_coin ?? '—' }}</div>
                    <div><strong>received_all_coin:</strong> {{ invoice.received_all_coin ?? '—' }}</div>
                    <div><strong>paid_usd:</strong> {{ invoice.paid_usd ?? '—' }}</div>
                    <div><strong>rate_usd:</strong> {{ invoice.rate_usd ?? '—' }}</div>
                </div>
            </article>

            <article class="panel">
                <h3 class="panel-title">Forwarding / settlement</h3>
                <div class="kv-grid">
                    <div><strong>forward_status:</strong> {{ invoice.forward_status || '—' }}</div>
                    <div><strong>forwarded_coin:</strong> {{ invoice.forwarded_coin ?? '—' }}</div>
                    <div><strong>forwarding_coin:</strong> {{ invoice.forwarding_coin ?? '—' }}</div>
                    <div><strong>merchant_payout_coin:</strong> {{ invoice.merchant_payout_coin ?? '—' }}</div>
                    <div><strong>merchant_payout_usd:</strong> {{ invoice.merchant_payout_usd ?? '—' }}</div>
                    <div><strong>fee_coin:</strong> {{ invoice.fee_coin ?? '—' }}</div>
                    <div><strong>fee_usd:</strong> {{ invoice.fee_usd ?? '—' }}</div>
                </div>
            </article>

            <article class="panel">
                <h3 class="panel-title">TX-related fields</h3>
                <div class="kv-grid">
                    <div><strong>first_txid:</strong> {{ invoice.first_txid || '—' }}</div>
                    <div><strong>first_amount_coin:</strong> {{ invoice.first_amount_coin ?? '—' }}</div>
                    <div><strong>forward_txids:</strong></div>
                </div>
                <pre class="json-box">{{ formatJson(invoice.forward_txids || []) }}</pre>
            </article>

            <article class="panel">
                <h3 class="panel-title">Merchant relation</h3>
                <div class="kv-grid">
                    <div><strong>merchant_id:</strong> {{ invoice.merchant?.id ?? '—' }}</div>
                    <div><strong>merchant_name:</strong> {{ invoice.merchant?.name || '—' }}</div>
                    <div><strong>merchant_status:</strong> {{ invoice.merchant?.status || '—' }}</div>
                </div>
            </article>

            <article class="panel">
                <h3 class="panel-title">Timestamps</h3>
                <div class="kv-grid">
                    <div><strong>expires_at:</strong> {{ formatDate(invoice.expires_at) }}</div>
                    <div><strong>fixated_at:</strong> {{ formatDate(invoice.fixated_at) }}</div>
                    <div><strong>paid_at:</strong> {{ formatDate(invoice.paid_at) }}</div>
                    <div><strong>created_at:</strong> {{ formatDate(invoice.created_at) }}</div>
                </div>
            </article>

            <article class="panel">
                <h3 class="panel-title">Webhook deliveries (short)</h3>
                <TableCard v-if="invoice.webhook_deliveries?.length">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Event</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="delivery in invoice.webhook_deliveries" :key="delivery.id">
                            <td>
                                <RouterLink :to="{ name: 'admin.webhook-deliveries.detail', params: { id: delivery.id } }">
                                    {{ delivery.id }}
                                </RouterLink>
                            </td>
                            <td>{{ delivery.event || '—' }}</td>
                            <td>{{ delivery.status || '—' }}</td>
                            <td>{{ delivery.attempts ?? '—' }}</td>
                            <td>{{ formatDate(delivery.created_at) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </TableCard>
                <EmptyState v-else title="No webhook deliveries" />
            </article>

            <article class="panel" v-if="invoice.metadata && Object.keys(invoice.metadata).length">
                <h3 class="panel-title">Metadata</h3>
                <pre class="json-box">{{ formatJson(invoice.metadata) }}</pre>
            </article>
        </template>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { extractApiErrorMessage, getAdminInvoice, refreshAdminInvoice } from '../../../api/admin';
import EmptyState from '../../../components/admin/EmptyState.vue';
import LoadingState from '../../../components/admin/LoadingState.vue';
import PageHeader from '../../../components/admin/PageHeader.vue';
import TableCard from '../../../components/admin/TableCard.vue';

const route = useRoute();
const router = useRouter();
const invoiceId = computed(() => route.params.id);

const loading = ref(true);
const actionLoading = ref(false);
const error = ref('');
const invoice = ref(null);

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const formatJson = (value) => JSON.stringify(value, null, 2);

const loadInvoice = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await getAdminInvoice(invoiceId.value);
        invoice.value = response.data?.data || null;
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load invoice details.');
    } finally {
        loading.value = false;
    }
};

const refreshInvoice = async () => {
    actionLoading.value = true;
    error.value = '';

    try {
        await refreshAdminInvoice(invoiceId.value);
        await loadInvoice();
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to refresh invoice.');
    } finally {
        actionLoading.value = false;
    }
};

loadInvoice();
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

.json-box {
    margin: 10px 0 0;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 10px;
    padding: 12px;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 12px;
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

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    border-bottom: 1px solid #f1f5f9;
    padding: 10px;
    text-align: left;
    white-space: nowrap;
    font-size: 13px;
}
</style>
