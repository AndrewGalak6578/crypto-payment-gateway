<template>
    <section>
        <PageHeader title="Invoices" subtitle="Cross-merchant invoice monitoring and status recheck actions." />

        <form class="filters-card" @submit.prevent="applyFilters">
            <input v-model.trim="filters.search" type="text" placeholder="Search by ID/public_id/external_id" />
            <input v-model.number="filters.merchant_id" type="number" min="1" placeholder="Merchant ID" />
            <input v-model.trim="filters.status" type="text" placeholder="Status" />
            <input v-model.trim="filters.coin" type="text" placeholder="Coin" />
            <input v-model="filters.date_from" type="date" />
            <input v-model="filters.date_to" type="date" />
            <button type="submit" class="primary-btn" :disabled="loading">Apply</button>
            <button type="button" class="secondary-btn" :disabled="loading" @click="resetFilters">Reset</button>
        </form>

        <LoadingState v-if="loading" text="Loading invoices..." />

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadInvoices">Retry</button>
        </div>

        <EmptyState v-else-if="!invoices.length" title="No invoices found" description="No rows match current filters." />

        <div v-else>
            <TableCard>
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Public ID</th>
                        <th>Merchant</th>
                        <th>Status</th>
                        <th>Coin</th>
                        <th>Amount coin</th>
                        <th>Expected USD</th>
                        <th>Received conf</th>
                        <th>Forward status</th>
                        <th>Created</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="invoice in invoices" :key="invoice.id">
                        <td>
                            <RouterLink :to="{ name: 'admin.invoices.detail', params: { id: invoice.id } }">
                                {{ invoice.id }}
                            </RouterLink>
                        </td>
                        <td>{{ invoice.public_id }}</td>
                        <td>
                            <div>#{{ invoice.merchant_id }}</div>
                            <div class="muted small">{{ invoice.merchant_name || '—' }}</div>
                        </td>
                        <td><StatusBadge :text="invoice.status" variant="info" /></td>
                        <td>{{ invoice.coin || '—' }}</td>
                        <td>{{ invoice.amount_coin ?? '—' }}</td>
                        <td>{{ invoice.expected_usd ?? '—' }}</td>
                        <td>{{ invoice.received_conf_coin ?? '—' }}</td>
                        <td>{{ invoice.forward_status || '—' }}</td>
                        <td>{{ formatDate(invoice.created_at) }}</td>
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
import { extractApiErrorMessage, getAdminInvoices } from '../../../api/admin';
import EmptyState from '../../../components/admin/EmptyState.vue';
import LoadingState from '../../../components/admin/LoadingState.vue';
import PageHeader from '../../../components/admin/PageHeader.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import TableCard from '../../../components/admin/TableCard.vue';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const error = ref('');
const invoices = ref([]);
const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});

const filters = reactive({
    search: '',
    merchant_id: null,
    status: '',
    coin: '',
    date_from: '',
    date_to: '',
});

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');

const syncFiltersFromQuery = () => {
    filters.search = typeof route.query.search === 'string' ? route.query.search : '';
    filters.status = typeof route.query.status === 'string' ? route.query.status : '';
    filters.coin = typeof route.query.coin === 'string' ? route.query.coin : '';
    filters.date_from = typeof route.query.date_from === 'string' ? route.query.date_from : '';
    filters.date_to = typeof route.query.date_to === 'string' ? route.query.date_to : '';
    filters.merchant_id = route.query.merchant_id ? Number(route.query.merchant_id) : null;
};

const buildQuery = (page = 1) => ({
    search: filters.search || undefined,
    status: filters.status || undefined,
    coin: filters.coin || undefined,
    merchant_id: filters.merchant_id || undefined,
    date_from: filters.date_from || undefined,
    date_to: filters.date_to || undefined,
    page,
});

const loadInvoices = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await getAdminInvoices({
            search: route.query.search || undefined,
            status: route.query.status || undefined,
            coin: route.query.coin || undefined,
            merchant_id: route.query.merchant_id || undefined,
            date_from: route.query.date_from || undefined,
            date_to: route.query.date_to || undefined,
            page: route.query.page || 1,
        });

        invoices.value = Array.isArray(response.data?.data.data) ? response.data.data.data : [];
        meta.value = {
            current_page: response.data?.meta?.current_page ?? 1,
            last_page: response.data?.meta?.last_page ?? 1,
            per_page: response.data?.meta?.per_page ?? 15,
            total: response.data?.meta?.total ?? 0,
        };
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load invoices.');
    } finally {
        loading.value = false;
    }
};

const applyFilters = async () => {
    await router.push({ name: 'admin.invoices', query: buildQuery(1) });
};

const resetFilters = async () => {
    filters.search = '';
    filters.status = '';
    filters.coin = '';
    filters.date_from = '';
    filters.date_to = '';
    filters.merchant_id = null;
    await router.push({ name: 'admin.invoices' });
};

const changePage = async (page) => {
    await router.push({ name: 'admin.invoices', query: buildQuery(page) });
};

watch(
    () => route.query,
    async () => {
        syncFiltersFromQuery();
        await loadInvoices();
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
