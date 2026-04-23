<template>
    <section>
        <PageHeader title="Merchants" subtitle="Global merchant registry and operational status control.">
            <template #actions>
                <button type="button" class="secondary-btn" :disabled="creating" @click="toggleCreateForm">
                    {{ showCreateForm ? 'Cancel create' : 'Create merchant' }}
                </button>
            </template>
        </PageHeader>

        <article v-if="showCreateForm" class="create-card">
            <h3 class="create-title">Create merchant</h3>
            <form class="create-grid" @submit.prevent="createMerchant">
                <input v-model.trim="createForm.name" type="text" maxlength="255" placeholder="Name" required />
                <input v-model.trim="createForm.fee_percent" type="number" min="0" step="any" placeholder="Fee % (optional)" />
                <input v-model.trim="createForm.webhook_url" type="url" maxlength="255" placeholder="Webhook URL (optional)" />
                <input v-model.trim="createForm.webhook_secret" type="text" maxlength="255" placeholder="Webhook secret (optional)" />
                <select v-model="createForm.status">
                    <option value="">active (default)</option>
                    <option value="active">active</option>
                    <option value="disabled">disabled</option>
                </select>
                <button type="submit" class="primary-btn" :disabled="creating">
                    {{ creating ? 'Creating...' : 'Create merchant' }}
                </button>
            </form>
            <p v-if="createError" class="error create-message">{{ createError }}</p>
        </article>
        <p v-if="createSuccess" class="success create-feedback">{{ createSuccess }}</p>

        <form class="filters-card" @submit.prevent="applyFilters">
            <input v-model.trim="filters.search" type="text" placeholder="Search by ID or name" />
            <select v-model="filters.status">
                <option value="">All statuses</option>
                <option value="active">active</option>
                <option value="disabled">disabled</option>
            </select>
            <button type="submit" class="primary-btn" :disabled="loading">Apply</button>
            <button type="button" class="secondary-btn" :disabled="loading" @click="resetFilters">Reset</button>
        </form>

        <LoadingState v-if="loading" text="Loading merchants..." />

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadMerchants">Retry</button>
        </div>

        <EmptyState
            v-else-if="!merchants.length"
            title="No merchants found"
            description="Try changing filters or clearing search query."
        />

        <div v-else>
            <TableCard>
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Fee %</th>
                        <th>Webhook URL</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="merchant in merchants" :key="merchant.id">
                        <td>
                            <RouterLink :to="{ name: 'admin.merchants.detail', params: { id: merchant.id } }">
                                {{ merchant.id }}
                            </RouterLink>
                        </td>
                        <td class="wrap-cell">{{ merchant.name }}</td>
                        <td>
                            <StatusBadge
                                :text="merchant.status === 'disabled' ? 'suspended' : merchant.status"
                                :variant="merchant.status === 'active' ? 'success' : 'warning'"
                            />
                        </td>
                        <td>{{ merchant.fee_percent ?? '—' }}</td>
                        <td class="truncate" :title="merchant.webhook_url || ''">{{ merchant.webhook_url || '—' }}</td>
                        <td>{{ formatDate(merchant.created_at) }}</td>
                        <td>
                            <div class="row-actions">
                                <button
                                    type="button"
                                    class="secondary-btn"
                                    :disabled="Boolean(actionLoadingById[merchant.id])"
                                    @click="updateStatus(merchant, merchant.status === 'active' ? 'disabled' : 'active')"
                                >
                                    {{ actionLoadingById[merchant.id] ? 'Saving...' : merchant.status === 'active' ? 'Suspend' : 'Activate' }}
                                </button>
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
    createAdminMerchant,
    extractApiErrorMessage,
    getAdminMerchants,
    updateAdminMerchantStatus,
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
const merchants = ref([]);
const actionLoadingById = ref({});
const creating = ref(false);
const createError = ref('');
const createSuccess = ref('');
const showCreateForm = ref(false);
const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});

const filters = reactive({
    search: '',
    status: '',
});
const createForm = reactive({
    name: '',
    fee_percent: '',
    webhook_url: '',
    webhook_secret: '',
    status: '',
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

const resetCreateForm = () => {
    createForm.name = '';
    createForm.fee_percent = '';
    createForm.webhook_url = '';
    createForm.webhook_secret = '';
    createForm.status = '';
    createError.value = '';
};

const toggleCreateForm = () => {
    if (showCreateForm.value) {
        showCreateForm.value = false;
        resetCreateForm();
        return;
    }

    showCreateForm.value = true;
    createError.value = '';
};

const syncFiltersFromQuery = () => {
    filters.search = typeof route.query.search === 'string' ? route.query.search : '';
    filters.status = typeof route.query.status === 'string' ? route.query.status : '';
};

const loadMerchants = async () => {
    loading.value = true;
    error.value = '';

    try {
        const params = {
            search: route.query.search || undefined,
            status: route.query.status || undefined,
            page: route.query.page || 1,
        };

        const response = await getAdminMerchants(params);
        merchants.value = Array.isArray(response.data?.data?.data) ? response.data.data.data : [];
        meta.value = {
            current_page: response.data?.meta?.current_page ?? 1,
            last_page: response.data?.meta?.last_page ?? 1,
            per_page: response.data?.meta?.per_page ?? 15,
            total: response.data?.meta?.total ?? 0,
        };
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load merchants.');
    } finally {
        loading.value = false;
    }
};

const applyFilters = async () => {
    await router.push({
        name: 'admin.merchants',
        query: {
            search: filters.search || undefined,
            status: filters.status || undefined,
            page: 1,
        },
    });
};

const resetFilters = async () => {
    filters.search = '';
    filters.status = '';
    await router.push({ name: 'admin.merchants' });
};

const changePage = async (page) => {
    await router.push({
        name: 'admin.merchants',
        query: {
            search: filters.search || undefined,
            status: filters.status || undefined,
            page,
        },
    });
};

const createMerchant = async () => {
    createError.value = '';
    createSuccess.value = '';

    const name = String(createForm.name || '').trim();
    const feePercentRaw = String(createForm.fee_percent || '').trim();
    const webhookUrl = String(createForm.webhook_url || '').trim();
    const webhookSecret = String(createForm.webhook_secret || '').trim();
    const status = String(createForm.status || '').trim();

    if (!name) {
        createError.value = 'Name is required.';
        return;
    }

    const payload = {
        name,
        fee_percent: feePercentRaw === '' ? undefined : Number(feePercentRaw),
        webhook_url: webhookUrl || undefined,
        webhook_secret: webhookSecret || undefined,
        status: status || undefined,
    };

    if (payload.fee_percent !== undefined && Number.isNaN(payload.fee_percent)) {
        createError.value = 'Fee percent must be a valid number.';
        return;
    }

    if (payload.fee_percent !== undefined && payload.fee_percent < 0) {
        createError.value = 'Fee percent cannot be negative.';
        return;
    }

    creating.value = true;

    try {
        await createAdminMerchant(payload);
        createSuccess.value = 'Merchant created.';
        showCreateForm.value = false;
        resetCreateForm();

        const nextQuery = {
            search: filters.search || undefined,
            status: filters.status || undefined,
            page: 1,
        };
        await router.push({ name: 'admin.merchants', query: nextQuery });
        await loadMerchants();
    } catch (requestError) {
        createError.value = extractApiErrorMessage(requestError, 'Failed to create merchant.');
    } finally {
        creating.value = false;
    }
};

const closeConfirm = () => {
    confirm.open = false;
    confirm.loading = false;
    confirm.onConfirm = () => {};
};

const runStatusUpdate = async (merchant, nextStatus) => {
    actionLoadingById.value = {
        ...actionLoadingById.value,
        [merchant.id]: true,
    };
    error.value = '';

    try {
        await updateAdminMerchantStatus(merchant.id, { status: nextStatus });
        merchant.status = nextStatus;
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to update merchant status.');
    } finally {
        actionLoadingById.value = {
            ...actionLoadingById.value,
            [merchant.id]: false,
        };
        closeConfirm();
    }
};

const updateStatus = (merchant, nextStatus) => {
    const isDanger = nextStatus === 'disabled';

    confirm.title = isDanger ? 'Suspend merchant' : 'Activate merchant';
    confirm.message = isDanger
        ? `Suspend merchant "${merchant.name}"?`
        : `Activate merchant "${merchant.name}"?`;
    confirm.confirmLabel = isDanger ? 'Suspend' : 'Activate';
    confirm.danger = isDanger;
    confirm.open = true;
    confirm.loading = false;
    confirm.onConfirm = async () => {
        confirm.loading = true;
        await runStatusUpdate(merchant, nextStatus);
    };
};

watch(
    () => route.query,
    async () => {
        syncFiltersFromQuery();
        await loadMerchants();
    },
    { immediate: true },
);
</script>

<style scoped>
.create-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 14px;
}

.create-title {
    margin: 0 0 10px;
    color: #0f172a;
    font-size: 16px;
}

.create-grid {
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.create-message {
    margin: 8px 0 0;
    font-size: 13px;
}

.create-feedback {
    margin: 0 0 10px;
}

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

.filters-card input,
.filters-card select,
.primary-btn,
.secondary-btn {
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 9px 11px;
    background: #fff;
    font: inherit;
    color: #0f172a;
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

.table-wrap table,
table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    font-size: 13px;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
    padding: 10px;
    text-align: left;
    white-space: nowrap;
}

.row-actions {
    display: inline-flex;
    gap: 8px;
}

.truncate {
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wrap-cell {
    white-space: normal;
    overflow-wrap: anywhere;
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

.success {
    color: #166534;
}

@media (max-width: 1024px) {
    .create-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .filters-card {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .create-grid {
        grid-template-columns: 1fr;
    }

    .filters-card {
        grid-template-columns: 1fr;
    }
}
</style>
