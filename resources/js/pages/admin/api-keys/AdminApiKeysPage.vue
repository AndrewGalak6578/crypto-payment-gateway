<template>
    <section>
        <PageHeader title="Merchant API Keys" subtitle="Metadata-only registry of merchant API keys with revoke control." />

        <form class="filters-card" @submit.prevent="applyFilters">
            <input v-model.trim="filters.search" type="text" placeholder="Search by key name / id / merchant" />
            <input v-model.number="filters.merchant_id" type="number" min="1" placeholder="Merchant ID" />
            <select v-model="filters.revoked">
                <option value="">All</option>
                <option value="0">Active only</option>
                <option value="1">Revoked only</option>
            </select>
            <button type="submit" class="primary-btn" :disabled="loading">Apply</button>
            <button type="button" class="secondary-btn" :disabled="loading" @click="resetFilters">Reset</button>
        </form>

        <LoadingState v-if="loading" text="Loading API keys..." />

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadKeys">Retry</button>
        </div>

        <EmptyState
            v-else-if="!apiKeys.length"
            title="No API keys found"
            description="Plaintext tokens are intentionally hidden; only metadata is shown."
        />

        <div v-else>
            <TableCard>
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Merchant</th>
                        <th>Name</th>
                        <th>Last used</th>
                        <th>Revoked at</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="apiKey in apiKeys" :key="apiKey.id">
                        <td>{{ apiKey.id }}</td>
                        <td>
                            <div>#{{ apiKey.merchant_id }}</div>
                            <div class="muted small">{{ apiKey.merchant_name || '—' }}</div>
                        </td>
                        <td>{{ apiKey.name || '—' }}</td>
                        <td>{{ formatDate(apiKey.last_used_at) }}</td>
                        <td>
                            <StatusBadge
                                :text="apiKey.revoked_at ? formatDate(apiKey.revoked_at) : 'active'"
                                :variant="apiKey.revoked_at ? 'warning' : 'success'"
                            />
                        </td>
                        <td>{{ formatDate(apiKey.created_at) }}</td>
                        <td>
                            <button
                                type="button"
                                class="secondary-btn"
                                :disabled="Boolean(revokeLoadingById[apiKey.id]) || Boolean(apiKey.revoked_at)"
                                @click="openRevokeConfirm(apiKey)"
                            >
                                {{ revokeLoadingById[apiKey.id] ? 'Revoking...' : 'Revoke' }}
                            </button>
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

        <ConfirmModal
            :open="confirm.open"
            :title="confirm.title"
            :message="confirm.message"
            :confirm-label="confirm.confirmLabel"
            :danger="confirm.danger"
            :loading="confirm.loading"
            @close="closeConfirm"
            @confirm="confirm.onConfirm"
        />
    </section>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    extractApiErrorMessage,
    getAdminMerchantApiKeys,
    revokeAdminMerchantApiKey,
} from '../../../api/admin';
import ConfirmModal from '../../../components/admin/ConfirmModal.vue';
import EmptyState from '../../../components/admin/EmptyState.vue';
import LoadingState from '../../../components/admin/LoadingState.vue';
import PageHeader from '../../../components/admin/PageHeader.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import TableCard from '../../../components/admin/TableCard.vue';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const error = ref('');
const apiKeys = ref([]);
const revokeLoadingById = ref({});
const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});

const filters = reactive({
    search: '',
    merchant_id: null,
    revoked: '',
});

const confirm = reactive({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirm',
    danger: false,
    loading: false,
    onConfirm: () => {},
});

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');

const syncFiltersFromQuery = () => {
    filters.search = typeof route.query.search === 'string' ? route.query.search : '';
    filters.revoked = typeof route.query.revoked === 'string' ? route.query.revoked : '';
    filters.merchant_id = route.query.merchant_id ? Number(route.query.merchant_id) : null;
};

const buildQuery = (page = 1) => ({
    search: filters.search || undefined,
    merchant_id: filters.merchant_id || undefined,
    revoked: filters.revoked || undefined,
    page,
});

const loadKeys = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await getAdminMerchantApiKeys({
            search: route.query.search || undefined,
            merchant_id: route.query.merchant_id || undefined,
            revoked: route.query.revoked || undefined,
            page: route.query.page || 1,
        });

        apiKeys.value = Array.isArray(response.data?.data.data) ? response.data.data.data : [];
        meta.value = {
            current_page: response.data?.meta?.current_page ?? 1,
            last_page: response.data?.meta?.last_page ?? 1,
            per_page: response.data?.meta?.per_page ?? 15,
            total: response.data?.meta?.total ?? 0,
        };
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load API keys.');
    } finally {
        loading.value = false;
    }
};

const applyFilters = async () => {
    await router.push({ name: 'admin.api-keys', query: buildQuery(1) });
};

const resetFilters = async () => {
    filters.search = '';
    filters.merchant_id = null;
    filters.revoked = '';
    await router.push({ name: 'admin.api-keys' });
};

const changePage = async (page) => {
    await router.push({ name: 'admin.api-keys', query: buildQuery(page) });
};

const closeConfirm = () => {
    confirm.open = false;
    confirm.loading = false;
    confirm.onConfirm = () => {};
};

const performRevoke = async (apiKey) => {
    revokeLoadingById.value = {
        ...revokeLoadingById.value,
        [apiKey.id]: true,
    };

    try {
        const response = await revokeAdminMerchantApiKey(apiKey.id);
        apiKey.revoked_at = response.data?.data?.revoked_at || apiKey.revoked_at;
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to revoke API key.');
    } finally {
        revokeLoadingById.value = {
            ...revokeLoadingById.value,
            [apiKey.id]: false,
        };
        closeConfirm();
    }
};

const openRevokeConfirm = (apiKey) => {
    if (apiKey.revoked_at) {
        return;
    }

    confirm.title = 'Revoke API key';
    confirm.message = `Revoke key "${apiKey.name}" for merchant #${apiKey.merchant_id}?`;
    confirm.confirmLabel = 'Revoke';
    confirm.danger = true;
    confirm.open = true;
    confirm.onConfirm = async () => {
        confirm.loading = true;
        await performRevoke(apiKey);
    };
};

watch(
    () => route.query,
    async () => {
        syncFiltersFromQuery();
        await loadKeys();
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

.primary-btn:disabled,
.secondary-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
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
