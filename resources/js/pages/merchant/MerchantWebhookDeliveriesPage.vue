<template>
    <section>
        <header class="page-header">
            <div>
                <h2 class="page-title">Webhook Deliveries</h2>
                <p class="page-subtitle">Inspect recent webhook attempts and debug failures from current API data.</p>
            </div>

            <div class="header-actions">
                <RouterLink class="secondary-btn" to="/merchant/webhook-settings">Webhook settings</RouterLink>
                <button class="action-btn" type="button" :disabled="loading" @click="refreshDeliveries">
                    {{ loading ? 'Refreshing...' : 'Refresh' }}
                </button>
            </div>
        </header>

        <div class="filters-row">
            <input v-model.trim="filters.search" type="text" placeholder="Search event / url / error" />
            <select v-model="filters.status">
                <option value="">All statuses</option>
                <option value="pending">pending</option>
                <option value="delivering">delivering</option>
                <option value="delivered">delivered</option>
                <option value="failed">failed</option>
            </select>
            <label class="toggle">
                <input v-model="filters.onlyFailed" type="checkbox" />
                <span>Only failed</span>
            </label>
            <button type="button" class="secondary-btn" @click="resetFilters">Reset</button>
        </div>

        <p v-if="notice" class="notice" :class="{ 'notice-error': noticeType === 'error' }">{{ notice }}</p>

        <p v-if="loading" class="muted">Loading webhook deliveries...</p>

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="action-btn" @click="loadDeliveries(currentPage)">Retry</button>
        </div>

        <div v-else-if="!deliveries.length" class="state-card">
            <p class="muted">No webhook deliveries yet.</p>
            <p class="hint">Configure webhook URL/secret, then create and pay a test invoice to generate delivery attempts.</p>
            <RouterLink class="secondary-btn" to="/merchant/webhook-settings">Open webhook settings</RouterLink>
        </div>

        <div v-else-if="!filteredDeliveries.length" class="state-card">
            <p class="muted">No deliveries match current filters.</p>
            <button type="button" class="secondary-btn" @click="resetFilters">Clear filters</button>
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
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <template v-for="delivery in filteredDeliveries" :key="delivery.id">
                        <tr>
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
                                <div class="action-row">
                                    <button
                                        type="button"
                                        class="secondary-btn mini"
                                        @click="toggleExpanded(delivery.id)"
                                    >
                                        {{ expandedIds.has(delivery.id) ? 'Hide' : 'Details' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="expandedIds.has(delivery.id)">
                            <td colspan="7" class="expanded-cell">
                                <p v-if="loadingDetailIds.has(delivery.id)" class="muted">Loading details...</p>
                                <div class="expanded-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Event</div>
                                        <div class="detail-value">{{ detailValue(delivery.id, 'event', delivery.event) }}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Status</div>
                                        <div class="detail-value">{{ detailValue(delivery.id, 'status', delivery.status) }}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Invoice ID</div>
                                        <div class="detail-value">{{ detailValue(delivery.id, 'invoice_id', delivery.invoice_id) }}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Attempts</div>
                                        <div class="detail-value">{{ detailValue(delivery.id, 'attempts', delivery.attempts) }}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Delivered at</div>
                                        <div class="detail-value">{{ formatDate(detailValue(delivery.id, 'delivered_at', delivery.delivered_at)) }}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Created at</div>
                                        <div class="detail-value">{{ formatDate(detailValue(delivery.id, 'created_at', delivery.created_at)) }}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Updated at</div>
                                        <div class="detail-value">{{ formatDate(detailValue(delivery.id, 'updated_at', null)) }}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Next retry at</div>
                                        <div class="detail-value">{{ formatDate(detailValue(delivery.id, 'next_retry_at', null)) }}</div>
                                    </div>
                                    <div class="detail-item wide">
                                        <div class="detail-label">URL</div>
                                        <div class="detail-value mono break">{{ detailValue(delivery.id, 'url', delivery.url) }}</div>
                                        <button
                                            v-if="detailValue(delivery.id, 'url', delivery.url) !== '—'"
                                            type="button"
                                            class="secondary-btn mini"
                                            @click="copyValue(detailValue(delivery.id, 'url', delivery.url), 'URL copied')"
                                        >
                                            Copy URL
                                        </button>
                                    </div>
                                    <div class="detail-item wide">
                                        <div class="detail-label">Last error</div>
                                        <div class="detail-value break">{{ detailValue(delivery.id, 'last_error', delivery.last_error) }}</div>
                                        <button
                                            v-if="detailValue(delivery.id, 'last_error', delivery.last_error) !== '—'"
                                            type="button"
                                            class="secondary-btn mini"
                                            @click="copyValue(detailValue(delivery.id, 'last_error', delivery.last_error), 'Error copied')"
                                        >
                                            Copy error
                                        </button>
                                    </div>
                                    <div class="detail-item wide">
                                        <div class="detail-label">Payload</div>
                                        <pre class="detail-pre">{{ payloadText(delivery.id) }}</pre>
                                    </div>
                                    <div class="detail-item wide">
                                        <div class="detail-label">Payload preview</div>
                                        <pre class="detail-pre">{{ payloadPreviewText(delivery.id) }}</pre>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
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
import { getMerchantWebhookDeliveries, getMerchantWebhookDeliveryDetail } from '../../api/merchant.js';
import { copyTextToClipboard } from '../../utils/clipboard.js';

const PER_PAGE = 15;

const loading = ref(true);
const error = ref('');
const notice = ref('');
const noticeType = ref('success');
const deliveries = ref([]);
const expandedIds = ref(new Set());
const loadingDetailIds = ref(new Set());
const deliveryDetails = ref({});
const filters = ref({
    search: '',
    status: '',
    onlyFailed: false,
});

const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: PER_PAGE,
    total: 0,
});

const currentPage = computed(() => meta.value.current_page || 1);
const lastPage = computed(() => meta.value.last_page || 1);
const total = computed(() => meta.value.total || 0);

const filteredDeliveries = computed(() => {
    const search = filters.value.search.toLowerCase();

    return deliveries.value.filter((delivery) => {
        const status = String(delivery.status || '').toLowerCase();

        if (filters.value.onlyFailed && status !== 'failed') {
            return false;
        }

        if (filters.value.status && status !== filters.value.status) {
            return false;
        }

        if (!search) {
            return true;
        }

        const haystack = [
            delivery.event,
            delivery.status,
            delivery.url,
            delivery.last_error,
            String(delivery.invoice_id ?? ''),
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return haystack.includes(search);
    });
});

const formatDate = (dateString) => {
    if (!dateString) {
        return '—';
    }

    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
};

const statusBadgeClass = (status) => {
    if (status === 'delivered') {
        return 'status-badge-success';
    }

    if (status === 'failed') {
        return 'status-badge-danger';
    }

    if (status === 'delivering') {
        return 'status-badge-info';
    }

    return 'status-badge-muted';
};

const copyValue = async (value, okMessage) => {
    const result = await copyTextToClipboard(value);
    if (result.ok) {
        noticeType.value = 'success';
        notice.value = okMessage;
        return;
    }

    noticeType.value = 'error';
    notice.value = result.message || 'Copy failed.';
};

const detailValue = (id, key, fallback = '—') => {
    const value = deliveryDetails.value[id]?.[key];

    if (value === null || value === undefined || value === '') {
        return fallback ?? '—';
    }

    return value;
};

const payloadText = (id) => {
    const payload = detailValue(id, 'payload', null);
    if (!payload) {
        return '—';
    }

    if (typeof payload === 'string') {
        return payload;
    }

    try {
        return JSON.stringify(payload, null, 2);
    } catch {
        return '—';
    }
};

const payloadPreviewText = (id) => {
    const preview = detailValue(id, 'payload_preview', null);
    return preview || '—';
};

const loadDeliveries = async (page = 1) => {
    loading.value = true;
    error.value = '';
    notice.value = '';

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
        expandedIds.value = new Set();
        loadingDetailIds.value = new Set();
        deliveryDetails.value = {};
    } catch {
        error.value = 'Failed to load webhook deliveries.';
    } finally {
        loading.value = false;
    }
};

const toggleExpanded = async (id) => {
    const next = new Set(expandedIds.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);

        if (!deliveryDetails.value[id]) {
            const nextLoading = new Set(loadingDetailIds.value);
            nextLoading.add(id);
            loadingDetailIds.value = nextLoading;

            try {
                const response = await getMerchantWebhookDeliveryDetail(id);
                deliveryDetails.value = {
                    ...deliveryDetails.value,
                    [id]: response.data?.data || {},
                };
            } catch {
                noticeType.value = 'error';
                notice.value = `Failed to load delivery #${id} details.`;
            } finally {
                const doneLoading = new Set(loadingDetailIds.value);
                doneLoading.delete(id);
                loadingDetailIds.value = doneLoading;
            }
        }
    }

    expandedIds.value = next;
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

const resetFilters = () => {
    filters.value = {
        search: '',
        status: '',
        onlyFailed: false,
    };
};

onMounted(() => {
    loadDeliveries();
});
</script>

<style scoped>
.page-header {
    margin-bottom: 14px;
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

.header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.filters-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}

.filters-row input,
.filters-row select {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 9px 10px;
    background: #fff;
    color: #0f172a;
}

.toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #334155;
    font-size: 13px;
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

.action-row {
    display: flex;
    gap: 8px;
}

.expanded-cell {
    background: #f8fafc;
}

.expanded-grid {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}

.detail-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px;
    background: #fff;
    display: grid;
    gap: 6px;
}

.detail-item.wide {
    grid-column: 1 / -1;
}

.detail-label {
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.detail-value {
    color: #0f172a;
    font-size: 13px;
}

.detail-pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 12px;
    color: #0f172a;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}

.break {
    word-break: break-word;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}

.hint {
    margin: 8px 0 0;
    color: #64748b;
    font-size: 13px;
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

.status-badge-info {
    background: #dbeafe;
    color: #1d4ed8;
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
    text-decoration: none;
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

.secondary-btn.mini {
    padding: 5px 8px;
    font-size: 12px;
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

.notice {
    color: #0369a1;
    margin: 0 0 12px;
}

.notice-error {
    color: #b91c1c;
}
</style>
