<template>
    <section>
        <PageHeader title="Merchant Users" subtitle="Manage merchant user access, roles and account status." />

        <article class="panel">
            <h3 class="panel-title">Create merchant user</h3>
            <form class="create-grid" @submit.prevent="createMerchantUser">
                <select v-model.number="createForm.merchant_id" required>
                    <option :value="0" disabled>Select merchant</option>
                    <option v-for="merchant in merchantOptions" :key="merchant.id" :value="merchant.id">
                        #{{ merchant.id }} - {{ merchant.name }}
                    </option>
                </select>

                <input v-model.trim="createForm.name" type="text" placeholder="Name" required />
                <input v-model.trim="createForm.email" type="email" placeholder="Email" required />
                <input v-model="createForm.password" type="password" placeholder="Password (min 8 chars)" required />

                <select v-if="roleOptions.length" v-model.number="createForm.role_id" required>
                    <option :value="0" disabled>Select role</option>
                    <option v-for="role in roleOptions" :key="role.id" :value="role.id">
                        {{ role.label }}
                    </option>
                </select>
                <input
                    v-else
                    v-model.number="createForm.role_id"
                    type="number"
                    min="1"
                    placeholder="Role ID"
                    required
                />

                <select v-model="createForm.status" required>
                    <option value="active">active</option>
                    <option value="disabled">disabled</option>
                </select>

                <button type="submit" class="primary-btn" :disabled="creating">
                    {{ creating ? 'Creating...' : 'Create user' }}
                </button>
            </form>
            <p v-if="createError" class="error">{{ createError }}</p>
            <p v-else-if="createSuccess" class="success">{{ createSuccess }}</p>
        </article>

        <form class="filters-card" @submit.prevent="applyFilters">
            <input v-model.trim="filters.search" type="text" placeholder="Search by ID/name/email" />
            <input v-model.number="filters.merchant_id" type="number" min="1" placeholder="Merchant ID" />
            <input v-model.number="filters.role_id" type="number" min="1" placeholder="Role ID" />
            <select v-model="filters.status">
                <option value="">All statuses</option>
                <option value="active">active</option>
                <option value="disabled">disabled</option>
            </select>
            <button type="submit" class="primary-btn" :disabled="loading">Apply</button>
            <button type="button" class="secondary-btn" :disabled="loading" @click="resetFilters">Reset</button>
        </form>

        <LoadingState v-if="loading" text="Loading merchant users..." />

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadUsers">Retry</button>
        </div>

        <EmptyState
            v-else-if="!users.length"
            title="No merchant users found"
            description="Adjust filters or create a new merchant user."
        />

        <div v-else>
            <TableCard>
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Merchant</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="user in users" :key="user.id">
                        <td>{{ user.id }}</td>
                        <td>
                            <div>#{{ user.merchant_id }}</div>
                            <div class="muted small">{{ user.merchant_name || '—' }}</div>
                        </td>
                        <td>{{ user.name }}</td>
                        <td>{{ user.email }}</td>
                        <td>
                            <div class="role-editor">
                                <input v-model.number="roleDraftByUser[user.id]" type="number" min="1" />
                                <button
                                    type="button"
                                    class="secondary-btn"
                                    :disabled="Boolean(roleLoadingByUser[user.id])"
                                    @click="changeRole(user)"
                                >
                                    {{ roleLoadingByUser[user.id] ? 'Saving...' : 'Save role' }}
                                </button>
                            </div>
                            <div class="muted small">{{ user.role_name || user.role_slug || '—' }}</div>
                        </td>
                        <td>
                            <StatusBadge :text="user.status" :variant="user.status === 'active' ? 'success' : 'warning'" />
                        </td>
                        <td>{{ formatDate(user.last_login_at) }}</td>
                        <td>{{ formatDate(user.created_at) }}</td>
                        <td>
                            <button
                                type="button"
                                class="secondary-btn"
                                :disabled="Boolean(statusLoadingByUser[user.id])"
                                @click="changeStatus(user, user.status === 'active' ? 'disabled' : 'active')"
                            >
                                {{ statusLoadingByUser[user.id] ? 'Saving...' : user.status === 'active' ? 'Disable' : 'Enable' }}
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
    createAdminMerchantUser,
    extractApiErrorMessage,
    getAdminMerchantUsers,
    getAdminMerchants,
    updateAdminMerchantUserRole,
    updateAdminMerchantUserStatus,
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
const creating = ref(false);
const error = ref('');
const createError = ref('');
const createSuccess = ref('');
const users = ref([]);
const roles = ref([]);
const merchantOptions = ref([]);
const roleOptions = ref([]);

const roleLoadingByUser = ref({});
const statusLoadingByUser = ref({});
const roleDraftByUser = ref({});

const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});

const filters = reactive({
    search: '',
    merchant_id: null,
    role_id: null,
    status: '',
});

const createForm = reactive({
    merchant_id: 0,
    name: '',
    email: '',
    password: '',
    role_id: 0,
    status: 'active',
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
    filters.status = typeof route.query.status === 'string' ? route.query.status : '';
    filters.merchant_id = route.query.merchant_id ? Number(route.query.merchant_id) : null;
    filters.role_id = route.query.role_id ? Number(route.query.role_id) : null;
};

const collectRoleOptions = (items) => {
    const known = new Map(roleOptions.value.map((role) => [role.id, role]));

    items.forEach((role) => {
        known.set(role.id, {
            id: role.id,
            label: role.name || role.slug,
        });
    });

    roleOptions.value = [...known.values()].sort((a, b) => a.id - b.id);
};

const loadMerchantsForCreate = async () => {
    try {
        const response = await getAdminMerchants({ per_page: 100 });
        merchantOptions.value = Array.isArray(response.data?.data?.data) ? response.data.data.data : [];
    } catch {
        merchantOptions.value = [];
    }
};

const loadUsers = async () => {
    loading.value = true;
    error.value = '';

    try {
        const params = {
            search: route.query.search || undefined,
            status: route.query.status || undefined,
            merchant_id: route.query.merchant_id || undefined,
            role_id: route.query.role_id || undefined,
            page: route.query.page || 1,
        };

        const response = await getAdminMerchantUsers(params);
        const rows = Array.isArray(response.data?.data?.data) ? response.data.data.data : [];
        const roles = Array.isArray(response.data?.roles) ? response.data.roles : [];

        users.value = rows;
        collectRoleOptions(roles);

        const nextDrafts = { ...roleDraftByUser.value };
        rows.forEach((user) => {
            nextDrafts[user.id] = user.role_id;
        });
        roleDraftByUser.value = nextDrafts;

        meta.value = {
            current_page: response.data?.meta?.current_page ?? 1,
            last_page: response.data?.meta?.last_page ?? 1,
            per_page: response.data?.meta?.per_page ?? 15,
            total: response.data?.meta?.total ?? 0,
        };
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load merchant users.');
    } finally {
        loading.value = false;
    }
};

const applyFilters = async () => {
    await router.push({
        name: 'admin.merchant-users',
        query: {
            search: filters.search || undefined,
            status: filters.status || undefined,
            merchant_id: filters.merchant_id || undefined,
            role_id: filters.role_id || undefined,
            page: 1,
        },
    });
};

const resetFilters = async () => {
    filters.search = '';
    filters.status = '';
    filters.merchant_id = null;
    filters.role_id = null;
    await router.push({ name: 'admin.merchant-users' });
};

const changePage = async (page) => {
    await router.push({
        name: 'admin.merchant-users',
        query: {
            search: filters.search || undefined,
            status: filters.status || undefined,
            merchant_id: filters.merchant_id || undefined,
            role_id: filters.role_id || undefined,
            page,
        },
    });
};

const createMerchantUser = async () => {
    createError.value = '';
    createSuccess.value = '';
    creating.value = true;

    try {
        await createAdminMerchantUser({
            merchant_id: createForm.merchant_id,
            name: createForm.name,
            email: createForm.email,
            password: createForm.password,
            role_id: createForm.role_id,
            status: createForm.status,
        });

        createForm.name = '';
        createForm.email = '';
        createForm.password = '';
        createSuccess.value = 'Merchant user created.';

        await loadUsers();
    } catch (requestError) {
        createError.value = extractApiErrorMessage(requestError, 'Failed to create merchant user.');
    } finally {
        creating.value = false;
    }
};

const closeConfirm = () => {
    confirm.open = false;
    confirm.loading = false;
    confirm.onConfirm = () => {};
};

const changeRole = async (user) => {
    const nextRoleId = Number(roleDraftByUser.value[user.id]);

    if (!nextRoleId || nextRoleId === user.role_id) {
        return;
    }

    roleLoadingByUser.value = {
        ...roleLoadingByUser.value,
        [user.id]: true,
    };

    try {
        const response = await updateAdminMerchantUserRole(user.id, { role_id: nextRoleId });
        user.role_id = response.data?.data?.role_id ?? nextRoleId;
        user.role_slug = response.data?.data?.role_slug || user.role_slug;
        user.role_name = response.data?.data?.role_name || user.role_name;

        collectRoleOptions([user]);
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to update role.');
    } finally {
        roleLoadingByUser.value = {
            ...roleLoadingByUser.value,
            [user.id]: false,
        };
    }
};

const applyStatusChange = async (user, nextStatus) => {
    statusLoadingByUser.value = {
        ...statusLoadingByUser.value,
        [user.id]: true,
    };

    try {
        await updateAdminMerchantUserStatus(user.id, { status: nextStatus });
        user.status = nextStatus;
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to update user status.');
    } finally {
        statusLoadingByUser.value = {
            ...statusLoadingByUser.value,
            [user.id]: false,
        };
        closeConfirm();
    }
};

const changeStatus = (user, nextStatus) => {
    const isDisabling = nextStatus === 'disabled';

    confirm.title = isDisabling ? 'Disable merchant user' : 'Enable merchant user';
    confirm.message = `Set status for ${user.email} to "${nextStatus}"?`;
    confirm.confirmLabel = isDisabling ? 'Disable' : 'Enable';
    confirm.danger = isDisabling;
    confirm.open = true;
    confirm.onConfirm = async () => {
        confirm.loading = true;
        await applyStatusChange(user, nextStatus);
    };
};

watch(
    () => route.query,
    async () => {
        syncFiltersFromQuery();
        await loadUsers();
    },
    { immediate: true },
);

loadMerchantsForCreate();
</script>

<style scoped>
.panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 14px;
}

.panel-title {
    margin: 0 0 10px;
    color: #0f172a;
    font-size: 16px;
}

.create-grid,
.filters-card {
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.filters-card {
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
    border-color: #0f172a;
    color: #fff;
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

.role-editor {
    display: inline-flex;
    gap: 6px;
    align-items: center;
}

.role-editor input {
    width: 92px;
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
    margin: 8px 0 0;
}

.success {
    color: #166534;
    margin: 8px 0 0;
}

.muted {
    color: #64748b;
}

.small {
    font-size: 12px;
}

@media (max-width: 1100px) {
    .create-grid,
    .filters-card {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .create-grid,
    .filters-card {
        grid-template-columns: 1fr;
    }
}
</style>
