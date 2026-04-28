<template>
  <section>
    <div class="page-header">
      <h2>Merchant Users</h2>
      <p>Manage access for your merchant team.</p>
    </div>

    <article v-if="canManageUsers" class="panel">
      <h3 class="panel-title">Create merchant user</h3>
      <form class="create-grid" @submit.prevent="createMerchantUser">
        <input v-model.trim="createForm.name" type="text" placeholder="Name" required />
        <input v-model.trim="createForm.email" type="email" placeholder="Email" required />
        <input v-model="createForm.password" type="password" placeholder="Password (min 8 chars)" required />

        <select v-model.number="createForm.role_id" required>
          <option :value="0" disabled>Select role</option>
          <option v-for="role in roleOptions" :key="role.id" :value="role.id">
            {{ role.label }}
          </option>
        </select>

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

      <select v-model.number="filters.role_id">
        <option :value="0">All roles</option>
        <option v-for="role in roleOptions" :key="role.id" :value="role.id">
          {{ role.label }}
        </option>
      </select>

      <select v-model="filters.status">
        <option value="">All statuses</option>
        <option value="active">active</option>
        <option value="disabled">disabled</option>
      </select>

      <button type="submit" class="primary-btn" :disabled="loading">Apply</button>
      <button type="button" class="secondary-btn" :disabled="loading" @click="resetFilters">Reset</button>
    </form>

    <div v-if="loading" class="state-card">Loading merchant users...</div>

    <div v-else-if="error" class="state-card">
      <p class="error">{{ error }}</p>
      <button type="button" class="secondary-btn" @click="loadUsers">Retry</button>
    </div>

    <div v-else-if="!users.length" class="state-card">
      No merchant users found.
    </div>

    <div v-else>
      <div class="table-card">
        <table>
          <thead>
          <tr>
            <th>ID</th>
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
              <div>{{ user.name }}</div>
              <div v-if="user.id === authStore.user?.id" class="muted small">You</div>
            </td>
            <td>{{ user.email }}</td>
            <td>
              <template v-if="canManageUsers">
                <div class="role-editor">
                  <select v-model.number="roleDraftByUser[user.id]">
                    <option v-for="role in roleOptions" :key="role.id" :value="role.id">
                      {{ role.label }}
                    </option>
                  </select>
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
              </template>
              <span v-else>{{ user.role_name || user.role_slug || '—' }}</span>
            </td>
            <td>
              <span :class="['status-badge', user.status === 'active' ? 'is-success' : 'is-warning']">
                {{ user.status }}
              </span>
            </td>
            <td>{{ formatDate(user.last_login_at) }}</td>
            <td>{{ formatDate(user.created_at) }}</td>
            <td>
              <button
                v-if="canManageUsers"
                type="button"
                class="secondary-btn"
                :disabled="Boolean(statusLoadingByUser[user.id])"
                @click="changeStatus(user, user.status === 'active' ? 'disabled' : 'active')"
              >
                {{ statusLoadingByUser[user.id] ? 'Saving...' : user.status === 'active' ? 'Disable' : 'Enable' }}
              </button>
              <span v-else class="muted">No actions</span>
            </td>
          </tr>
          </tbody>
        </table>
      </div>

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
import {
  createMerchantPortalUser,
  getMerchantPortalUsers,
  updateMerchantPortalUserRole,
  updateMerchantPortalUserStatus,
} from '../../api/merchant';
import { extractApiErrorMessage } from '../../api/admin';
import { useAuthStore } from '../../stores/auth';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const canManageUsers = authStore.hasCapability('merchant_users.write');

const loading = ref(false);
const creating = ref(false);
const error = ref('');
const createError = ref('');
const createSuccess = ref('');
const users = ref([]);
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
  role_id: 0,
  status: '',
});

const createForm = reactive({
  name: '',
  email: '',
  password: '',
  role_id: 0,
  status: 'active',
});

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');

const collectRoleOptions = (items) => {
  roleOptions.value = items
    .map((role) => ({
      id: role.id,
      label: role.name || role.slug,
    }))
    .sort((a, b) => a.id - b.id);

  if (!createForm.role_id && roleOptions.value.length) {
    createForm.role_id = roleOptions.value[0].id;
  }
};

const syncFiltersFromQuery = () => {
  filters.search = typeof route.query.search === 'string' ? route.query.search : '';
  filters.status = typeof route.query.status === 'string' ? route.query.status : '';
  filters.role_id = route.query.role_id ? Number(route.query.role_id) : 0;
};

const loadUsers = async () => {
  loading.value = true;
  error.value = '';

  try {
    const response = await getMerchantPortalUsers({
      search: route.query.search || undefined,
      status: route.query.status || undefined,
      role_id: route.query.role_id || undefined,
      page: route.query.page || 1,
    });

    const rows = Array.isArray(response.data?.data?.data) ? response.data.data.data : [];
    const roles = Array.isArray(response.data?.roles) ? response.data.roles : [];

    users.value = rows;
    collectRoleOptions(roles);

    const nextDrafts = {};
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
    name: 'merchant.users',
    query: {
      search: filters.search || undefined,
      status: filters.status || undefined,
      role_id: filters.role_id || undefined,
      page: 1,
    },
  });
};

const resetFilters = async () => {
  filters.search = '';
  filters.status = '';
  filters.role_id = 0;
  await router.push({ name: 'merchant.users' });
};

const changePage = async (page) => {
  await router.push({
    name: 'merchant.users',
    query: {
      search: filters.search || undefined,
      status: filters.status || undefined,
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
    await createMerchantPortalUser({
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
    const response = await updateMerchantPortalUserRole(user.id, { role_id: nextRoleId });
    user.role_id = response.data?.data?.role_id ?? nextRoleId;
    user.role_slug = response.data?.data?.role_slug || user.role_slug;
    user.role_name = response.data?.data?.role_name || user.role_name;
  } catch (requestError) {
    error.value = extractApiErrorMessage(requestError, 'Failed to update role.');
    roleDraftByUser.value[user.id] = user.role_id;
  } finally {
    roleLoadingByUser.value = {
      ...roleLoadingByUser.value,
      [user.id]: false,
    };
  }
};

const changeStatus = async (user, nextStatus) => {
  statusLoadingByUser.value = {
    ...statusLoadingByUser.value,
    [user.id]: true,
  };

  try {
    await updateMerchantPortalUserStatus(user.id, { status: nextStatus });
    user.status = nextStatus;
  } catch (requestError) {
    error.value = extractApiErrorMessage(requestError, 'Failed to update user status.');
  } finally {
    statusLoadingByUser.value = {
      ...statusLoadingByUser.value,
      [user.id]: false,
    };
  }
};

watch(
  () => route.query,
  async () => {
    syncFiltersFromQuery();
    await loadUsers();
  },
  { immediate: true },
);
</script>

<style scoped>
.page-header {
  margin-bottom: 14px;
}

.page-header h2 {
  margin: 0;
  color: #0f172a;
}

.page-header p {
  margin: 6px 0 0;
  color: #64748b;
}

.panel,
.filters-card,
.state-card,
.table-card {
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
  grid-template-columns: repeat(3, minmax(0, 1fr));
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
  display: flex;
  gap: 8px;
  align-items: center;
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

.is-success {
  background: #dcfce7;
  color: #166534;
}

.is-warning {
  background: #fef3c7;
  color: #92400e;
}

.muted {
  color: #64748b;
}

.small {
  font-size: 12px;
}

.error {
  color: #b91c1c;
}

.success {
  color: #166534;
}

.pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

@media (max-width: 900px) {
  .create-grid,
  .filters-card {
    grid-template-columns: 1fr;
  }

  .table-card {
    overflow-x: auto;
  }

  .role-editor {
    min-width: 220px;
  }
}
</style>
