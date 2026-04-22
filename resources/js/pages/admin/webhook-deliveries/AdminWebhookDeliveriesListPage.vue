<template>
    <section>
        <PageHeader title="Webhook Deliveries" subtitle="Track global webhook delivery queue and retry failures." />

        <form class="filters-card" @submit.prevent="applyFilters">
            <input v-model.trim="filters.search" type="text" placeholder="Search by ID/invoice/url" />
            <input v-model.number="filters.merchant_id" type="number" min="1" placeholder="Merchant ID" />
            <input v-model.number="filters.invoice_id" type="number" min="1" placeholder="Invoice ID" />
            <input v-model.trim="filters.event" type="text" placeholder="Event" />
            <select v-model="filters.status">
                <option value="">Any status</option>
                <option v-for="status in statusOptions" :key="status" :value="status">{{ status }}</option>
            </select>
            <button type="submit" class="primary-btn" :disabled="loading">Apply</button>
            <button type="button" class="secondary-btn" :disabled="loading" @click="resetFilters">Reset</button>
        </form>

        <LoadingState v-if="loading" text="Loading deliveries..." />

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadDeliveries">Retry</button>
        </div>

        <EmptyState v-else-if="!deliveries.length" title="No deliveries found" description="No rows match current filters." />
        <p v-if="!loading && !error && notice" class="copy-notice" :class="{ 'copy-notice-error': noticeType === 'error' }">{{ notice }}</p>

        <div v-else>
            <TableCard>
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Invoice</th>
                        <th>Merchant</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>URL</th>
                        <th>Last error</th>
                        <th>Delivered</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="delivery in deliveries" :key="delivery.id">
                        <td>
                            <RouterLink :to="{ name: 'admin.webhook-deliveries.detail', params: { id: delivery.id } }">
                                {{ delivery.id }}
                            </RouterLink>
                        </td>
                        <td>
                            <RouterLink v-if="delivery.invoice_id" :to="{ name: 'admin.invoices.detail', params: { id: delivery.invoice_id } }">
                                {{ delivery.invoice_id }}
                            </RouterLink>
                            <span v-else>—</span>
                        </td>
                        <td>
                            <div>
                                <RouterLink v-if="delivery.merchant_id" :to="{ name: 'admin.merchants.detail', params: { id: delivery.merchant_id } }">
                                    #{{ delivery.merchant_id }}
                                </RouterLink>
                                <span v-else>—</span>
                            </div>
                            <div class="muted small">{{ delivery.merchant_name || '—' }}</div>
                        </td>
                        <td>{{ delivery.event || '—' }}</td>
                        <td>
                            <StatusBadge :text="delivery.status" :variant="statusVariant(delivery.status)" />
                        </td>
                        <td>{{ delivery.attempts ?? '—' }}</td>
                        <td class="mono wrap-cell">{{ delivery.url || '—' }}</td>
                        <td class="wrap-cell">{{ delivery.last_error || '—' }}</td>
                        <td>{{ formatDate(delivery.delivered_at) }}</td>
                        <td>{{ formatDate(delivery.created_at) }}</td>
                        <td>
                            <div class="actions">
                                <RouterLink :to="{ name: 'admin.webhook-deliveries.detail', params: { id: delivery.id } }">Detail</RouterLink>
                                <button type="button" class="copy-btn" :disabled="!delivery.url" @click="copyText(delivery.url)">Copy URL</button>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </TableCard>

            <div class="pagination">
                <button
                    type="button"
                    class="secondary-btn"
                    :disabled="loading || meta.current_page <= 1"
                    @click="changePage(meta.current_page - 1)"
                >
                    Prev
                </button>
                <span>Page {{ meta.current_page }} / {{ meta.last_page }} ({{ meta.total }})</span>
                <button
                    type="button"
                    class="secondary-btn"
                    :disabled="loading || meta.current_page >= meta.last_page"
                    @click="changePage(meta.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>
    </section>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { extractApiErrorMessage, getAdminWebhookDeliveries } from '../../../api/admin';
import EmptyState from '../../../components/admin/EmptyState.vue';
import LoadingState from '../../../components/admin/LoadingState.vue';
import PageHeader from '../../../components/admin/PageHeader.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import TableCard from '../../../components/admin/TableCard.vue';
import { copyTextToClipboard } from '../../../utils/clipboard';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const error = ref('');
const notice = ref('');
const noticeType = ref('success');
const deliveries = ref([]);
const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});

const filters = reactive({
    search: '',
    merchant_id: null,
    invoice_id: null,
    status: '',
    event: '',
});

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const statusOptions = ['pending', 'delivering', 'delivered', 'failed'];

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

const copyText = async (value) => {
    const result = await copyTextToClipboard(value);
    if (result.ok) {
        noticeType.value = 'success';
        notice.value = 'URL copied.';
        return;
    }

    noticeType.value = 'error';
    notice.value = result.message || 'Copy failed.';
};

const syncFiltersFromQuery = () => {
    filters.search = typeof route.query.search === 'string' ? route.query.search : '';
    filters.status = typeof route.query.status === 'string' ? route.query.status : '';
    filters.event = typeof route.query.event === 'string' ? route.query.event : '';
    filters.merchant_id = route.query.merchant_id ? Number(route.query.merchant_id) : null;
    filters.invoice_id = route.query.invoice_id ? Number(route.query.invoice_id) : null;
};

const buildQuery = (page = 1) => ({
    search: filters.search || undefined,
    status: filters.status || undefined,
    event: filters.event || undefined,
    merchant_id: filters.merchant_id || undefined,
    invoice_id: filters.invoice_id || undefined,
    page,
});

const loadDeliveries = async () => {
    loading.value = true;
    error.value = '';
    notice.value = '';

    try {
        const response = await getAdminWebhookDeliveries({
            search: route.query.search || undefined,
            status: route.query.status || undefined,
            event: route.query.event || undefined,
            merchant_id: route.query.merchant_id || undefined,
            invoice_id: route.query.invoice_id || undefined,
            page: route.query.page || 1,
        });

        deliveries.value = Array.isArray(response.data?.data.data) ? response.data.data.data : [];
        meta.value = {
            current_page: response.data?.meta?.current_page ?? 1,
            last_page: response.data?.meta?.last_page ?? 1,
            per_page: response.data?.meta?.per_page ?? 15,
            total: response.data?.meta?.total ?? 0,
        };
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load webhook deliveries.');
    } finally {
        loading.value = false;
    }
};

const applyFilters = async () => {
    await router.push({ name: 'admin.webhook-deliveries', query: buildQuery(1) });
};

const resetFilters = async () => {
    filters.search = '';
    filters.status = '';
    filters.event = '';
    filters.merchant_id = null;
    filters.invoice_id = null;
    await router.push({ name: 'admin.webhook-deliveries' });
};

const changePage = async (page) => {
    await router.push({ name: 'admin.webhook-deliveries', query: buildQuery(page) });
};

watch(
    () => route.query,
    async () => {
        syncFiltersFromQuery();
        await loadDeliveries();
    },
    { immediate: true },
);
</script>

<style scoped>
.filters-card {
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 14px;
}

input,
select,
.primary-btn,
.secondary-btn {
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 9px 11px;
    background: #fff;
    color: #0f172a;
    font: inherit;
}

.primary-btn {
    background: #0f172a;
    color: #fff;
    border-color: #0f172a;
}

.primary-btn,
.secondary-btn {
    cursor: pointer;
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
    vertical-align: top;
}

.actions {
    display: inline-flex;
    gap: 8px;
    align-items: center;
}

.copy-btn {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #fff;
    padding: 4px 7px;
    cursor: pointer;
}

.pagination {
    margin-top: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    color: #475569;
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

.muted {
    color: #64748b;
}

.small {
    font-size: 12px;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}

.wrap-cell {
    white-space: normal;
    word-break: break-word;
}

.copy-notice {
    margin: 0 0 10px;
    font-size: 13px;
    color: #0369a1;
}

.copy-notice-error {
    color: #b91c1c;
}

@media (max-width: 1100px) {
    .filters-card {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .filters-card {
        grid-template-columns: 1fr;
    }
}
</style>
