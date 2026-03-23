<template>
    <section>
        <header class="page-header">
            <div>
                <h2 class="page-title">Webhook Deliveries</h2>
                <p class="page-subtitle">Recent webhook delivery attempts for merchant invoice events.</p>
            </div>

            <button class="action-btn" type="button" :disabled="loading" @click="refreshDeliveries">
                {{ loading ? 'Refreshing...' : 'Refresh' }}
            </button>
        </header>

        <p v-if="loading" class="muted">Loading webhook deliveries...</p>

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="action-btn" @click="loadDeliveries(currentPage)">Retry</button>
        </div>

        <div v-else-if="!deliveries.length" class="state-card">
            <p class="muted">No webhook deliveries yet.</p>
        </div>

        <div v-else class="table-card">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Invoice ID</th>
                        <th>Attempts</th>
                        <th>Delivered</th>
                        <th>Created</th>
                        <th>Details</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="delivery in deliveries" :key="delivery.id">
                        <td>{{ delivery.event || '—' }}</td>
                        <td>
                            <span class="status-badge" :class="statusBadgeClass(delivery.status)">
                                {{ delivery.status || '—' }}
                            </span>
                        </td>
                        <td>{{ delivery.invoice_id ?? '—' }}</td>
                        <td>{{ delivery.attempts ?? '—' }}</td>
                        <td>{{ formatDate(delivery.delivered_at) }}</td>
                        <td>{{ formatDate(delivery.created_at) }}</td>
                        <td>
                            <div class="details-cell">
                                <p v-if="delivery.url"><strong>URL:</strong> {{ delivery.url }}</p>
                                <p v-if="delivery.last_error"><strong>Error:</strong> {{ delivery.last_error }}</p>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                <button
                    type="button"
                    class="secondary-btn"
                    :disabled="loading || currentPage <= 1"
                    @click="changePage(currentPage - 1)"
                >
                    Previous
                </button>

                <span class="pagination-status">Page {{ currentPage }} / {{ lastPage }}</span>
                <span class="pagination-total">Total: {{ total }}</span>

                <button
                    type="button"
                    class="secondary-btn"
                    :disabled="loading || currentPage >= lastPage"
                    @click="changePage(currentPage + 1)"
                >
                    Next
                </button>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { getMerchantWebhookDeliveries } from '../../api/merchant.js';

const PER_PAGE = 15;

const loading = ref(true);
const error = ref('');
const deliveries = ref([]);
const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: PER_PAGE,
    total: 0,
});

const currentPage = computed(() => meta.value.current_page || 1);
const lastPage = computed(() => meta.value.last_page || 1);
const total = computed(() => meta.value.total || 0);

const formatDate = (dateString) => {
    if (!dateString) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(dateString));
};

const statusBadgeClass = (status) => {
    if (status === 'delivered') {
        return 'status-badge-success';
    }

    if (status === 'failed') {
        return 'status-badge-danger';
    }

    return 'status-badge-muted';
};

const loadDeliveries = async (page = 1) => {
    loading.value = true;
    error.value = '';

    try {
        const response = await getMerchantWebhookDeliveries({
            page,
            per_page: PER_PAGE,
        });

        deliveries.value = Array.isArray(response.data?.data?.data) ? response.data.data.data : [];
        meta.value = {
            current_page: response.data?.meta?.current_page ?? page,
            last_page: response.data?.meta?.last_page ?? 1,
            per_page: response.data?.meta?.per_page ?? PER_PAGE,
            total: response.data?.meta?.total ?? 0,
        };
    } catch {
        error.value = 'Failed to load webhook deliveries.';
    } finally {
        loading.value = false;
    }
};

const changePage = async (page) => {
    if (page < 1 || page > lastPage.value || page === currentPage.value) {
        return;
    }

    await loadDeliveries(page);
};

const refreshDeliveries = async () => {
    await loadDeliveries(currentPage.value);
};

onMounted(() => {
    loadDeliveries();
});
</script>

<style scoped>
.page-header {
    margin-bottom: 16px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.page-title {
    margin: 0;
    color: #0f172a;
}

.page-subtitle {
    margin: 6px 0 0;
    color: #64748b;
}

.state-card,
.table-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    border-bottom: 1px solid #f1f5f9;
    padding: 9px;
    text-align: left;
    font-size: 13px;
    vertical-align: top;
}

tbody tr:last-child td {
    border-bottom: 0;
}

.details-cell {
    display: grid;
    gap: 6px;
    min-width: 260px;
    white-space: normal;
    word-break: break-word;
}

.details-cell p {
    margin: 0;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.status-badge-success {
    background: #dcfce7;
    color: #166534;
}

.status-badge-danger {
    background: #fef2f2;
    color: #b91c1c;
}

.status-badge-muted {
    background: #e2e8f0;
    color: #475569;
}

.pagination {
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: space-between;
    flex-wrap: wrap;
    margin-top: 14px;
}

.pagination-status,
.pagination-total {
    color: #475569;
}

.action-btn,
.secondary-btn {
    border-radius: 8px;
    padding: 9px 12px;
    cursor: pointer;
    font: inherit;
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

.error {
    color: #b91c1c;
}
</style>
