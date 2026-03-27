<template>
    <section>
        <PageHeader title="Webhook Deliveries" subtitle="Track global webhook delivery queue and retry failures." />

        <form class="filters-card" @submit.prevent="applyFilters">
            <input v-model.trim="filters.search" type="text" placeholder="Search by ID/invoice/url" />
            <input v-model.number="filters.merchant_id" type="number" min="1" placeholder="Merchant ID" />
            <input v-model.number="filters.invoice_id" type="number" min="1" placeholder="Invoice ID" />
            <input v-model.trim="filters.event" type="text" placeholder="Event" />
            <input v-model.trim="filters.status" type="text" placeholder="Status" />
            <button type="submit" class="primary-btn" :disabled="loading">Apply</button>
            <button type="button" class="secondary-btn" :disabled="loading" @click="resetFilters">Reset</button>
        </form>

        <LoadingState v-if="loading" text="Loading deliveries..." />

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadDeliveries">Retry</button>
        </div>

        <EmptyState v-else-if="!deliveries.length" title="No deliveries found" description="No rows match current filters." />

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
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="delivery in deliveries" :key="delivery.id">
                        <td>
                            <RouterLink :to="{ name: 'admin.webhook-deliveries.detail', params: { id: delivery.id } }">
                                {{ delivery.id }}
                            </RouterLink>
                        </td>
                        <td>{{ delivery.invoice_id ?? '—' }}</td>
                        <td>
                            <div>#{{ delivery.merchant_id ?? '—' }}</div>
                            <div class="muted small">{{ delivery.merchant_name || '—' }}</div>
                        </td>
                        <td>{{ delivery.event || '—' }}</td>
                        <td>
                            <StatusBadge :text="delivery.status" :variant="delivery.status === 'failed' ? 'danger' : delivery.status === 'delivered' ? 'success' : 'muted'" />
                        </td>
                        <td>{{ delivery.attempts ?? '—' }}</td>
                        <td class="truncate">{{ delivery.url || '—' }}</td>
                        <td class="truncate">{{ delivery.last_error || '—' }}</td>
                        <td>{{ formatDate(delivery.delivered_at) }}</td>
                        <td>{{ formatDate(delivery.created_at) }}</td>
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

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const error = ref('');
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

.truncate {
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
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
