<template>
    <section class="page-stack team-page">
        <header class="page-header team-header">
            <div>
                <p class="page-kicker">Access control</p>
                <h2 class="page-title">Team</h2>
                <p class="page-subtitle">Manage merchant users, roles, and the permissions each role unlocks.</p>
            </div>
            <div class="page-actions">
                <button class="btn btn-secondary" type="button" :disabled="loading" @click="loadUsers">
                    {{ loading ? 'Refreshing...' : 'Refresh' }}
                </button>
                <button class="btn btn-primary" type="button" :disabled="!canWriteTeam" @click="focusCreateForm">
                    Add team member
                </button>
            </div>
        </header>

        <div v-if="toast.message" class="team-toast" :class="`team-toast-${toast.type}`" role="status" aria-live="polite">
            <span></span>
            <div>
                <strong>{{ toast.type === 'danger' ? 'Action failed' : 'Team updated' }}</strong>
                <p>{{ toast.message }}</p>
            </div>
            <button type="button" aria-label="Dismiss notification" @click="clearToast">×</button>
        </div>

        <section class="mobile-team-hero">
            <div>
                <p>Team access</p>
                <strong>{{ activeUsers }} active user{{ activeUsers === 1 ? '' : 's' }}</strong>
                <small>{{ ownerUsers }} owner{{ ownerUsers === 1 ? '' : 's' }} protected</small>
            </div>
            <button class="btn btn-primary" type="button" :disabled="!canWriteTeam" @click="openMobilePanel('invite')">Add member</button>
        </section>

        <nav class="mobile-team-tabs" aria-label="Team sections">
            <button type="button" :class="{ 'mobile-tab-active': activeMobilePanel === 'directory' }" @click="openMobilePanel('directory')">
                Members
                <span>{{ meta.total }}</span>
            </button>
            <button type="button" :class="{ 'mobile-tab-active': activeMobilePanel === 'invite' }" @click="openMobilePanel('invite')">
                Add
                <span>{{ canWriteTeam ? 'New' : 'View' }}</span>
            </button>
            <button type="button" :class="{ 'mobile-tab-active': activeMobilePanel === 'roles' }" @click="openMobilePanel('roles')">
                Roles
                <span>{{ roles.length }}</span>
            </button>
        </nav>

        <section class="mobile-team-summary">
            <article>
                <span>Active</span>
                <strong>{{ activeUsers }}</strong>
            </article>
            <article>
                <span>Disabled</span>
                <strong>{{ disabledUsers }}</strong>
            </article>
            <article>
                <span>Owners</span>
                <strong>{{ ownerUsers }}</strong>
            </article>
        </section>

        <section class="team-metric-grid">
            <article v-for="metric in metrics" :key="metric.label" class="card card-pad team-metric-card">
                <span :class="['metric-dot', metric.tone]"></span>
                <div>
                    <p>{{ metric.label }}</p>
                    <strong>{{ metric.value }}</strong>
                    <small>{{ metric.note }}</small>
                </div>
            </article>
        </section>

        <section class="team-workspace">
            <div class="team-main-column">
                <article class="card team-directory-card mobile-panel" :class="{ 'mobile-panel-active': activeMobilePanel === 'directory' }">
                    <div class="card-pad section-header">
                        <div>
                            <h3 class="card-title">Team directory</h3>
                            <p class="card-subtitle">Filter users, change roles, and suspend access when needed.</p>
                        </div>
                        <span class="status-badge status-info">{{ meta.total }} total</span>
                    </div>

                    <div class="team-mobile-filter-strip">
                        <input v-model.trim="filters.search" class="input" type="search" placeholder="Search name, email, or ID" @keyup.enter="applyFilters" />
                        <details class="team-mobile-filter-panel">
                            <summary>
                                <span>Filters</span>
                                <strong v-if="activeTeamFilterCount">{{ activeTeamFilterCount }}</strong>
                            </summary>
                            <div class="team-mobile-filter-body">
                                <select v-model="filters.status" class="input" @change="applyFilters">
                                    <option value="">All statuses</option>
                                    <option value="active">Active</option>
                                    <option value="disabled">Disabled</option>
                                </select>
                                <select v-model="filters.role_id" class="input" @change="applyFilters">
                                    <option value="">All roles</option>
                                    <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                                </select>
                                <div class="team-mobile-date-grid">
                                    <input v-model="filters.created_from" class="input" type="date" aria-label="Created from" @change="applyFilters" />
                                    <input v-model="filters.created_to" class="input" type="date" aria-label="Created to" @change="applyFilters" />
                                </div>
                                <div class="team-mobile-sort-grid">
                                    <select v-model="filters.sort" class="input" @change="applyFilters">
                                        <option value="created_at">Created date</option>
                                        <option value="name">Name</option>
                                        <option value="email">Email</option>
                                        <option value="status">Status</option>
                                        <option value="last_login_at">Last login</option>
                                    </select>
                                    <select v-model="filters.direction" class="input" @change="applyFilters">
                                        <option value="desc">Descending</option>
                                        <option value="asc">Ascending</option>
                                    </select>
                                </div>
                                <select v-model.number="filters.per_page" class="input" @change="applyFilters">
                                    <option :value="10">10 / page</option>
                                    <option :value="15">15 / page</option>
                                    <option :value="25">25 / page</option>
                                    <option :value="50">50 / page</option>
                                </select>
                                <div class="team-mobile-filter-actions">
                                    <button class="btn btn-secondary" type="button" @click="clearFilters">Reset</button>
                                    <button class="btn btn-primary" type="button" @click="applyFilters">Apply</button>
                                </div>
                            </div>
                        </details>
                    </div>

                    <div class="team-filter-bar">
                        <input v-model.trim="filters.search" class="input" type="search" placeholder="Search name, email, or ID" @keyup.enter="applyFilters" />
                        <select v-model="filters.status" class="input" @change="applyFilters">
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                        <select v-model="filters.role_id" class="input" @change="applyFilters">
                            <option value="">All roles</option>
                            <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                        </select>
                        <select v-model.number="filters.per_page" class="input" @change="applyFilters">
                            <option :value="10">10 / page</option>
                            <option :value="15">15 / page</option>
                            <option :value="25">25 / page</option>
                            <option :value="50">50 / page</option>
                        </select>
                        <input v-model="filters.created_from" class="input" type="date" aria-label="Created from" @change="applyFilters" />
                        <input v-model="filters.created_to" class="input" type="date" aria-label="Created to" @change="applyFilters" />
                        <select v-model="filters.sort" class="input" @change="applyFilters">
                            <option value="created_at">Created date</option>
                            <option value="name">Name</option>
                            <option value="email">Email</option>
                            <option value="status">Status</option>
                            <option value="last_login_at">Last login</option>
                        </select>
                        <select v-model="filters.direction" class="input" @change="applyFilters">
                            <option value="desc">Descending</option>
                            <option value="asc">Ascending</option>
                        </select>
                        <button class="btn btn-secondary" type="button" @click="clearFilters">Clear</button>
                    </div>

                    <div v-if="loading" class="team-loading">
                        <div v-for="index in 5" :key="index" class="team-skeleton"></div>
                    </div>

                    <div v-else class="team-user-list">
                        <article v-for="user in users" :key="user.id" class="team-user-card">
                            <div class="team-user-main">
                                <span class="user-avatar">{{ initials(user) }}</span>
                                <div>
                                    <div class="team-user-name-row">
                                        <strong>{{ user.name || user.email }}</strong>
                                        <RouterLink class="dossier-link" :to="{ name: 'merchant-v2.team-member', params: { userId: user.id } }">
                                            Dossier
                                        </RouterLink>
                                    </div>
                                    <p>{{ user.email }}</p>
                                    <small>ID #{{ user.id }} · Added {{ formatDate(user.created_at) }}</small>
                                </div>
                            </div>

                            <div class="team-user-status">
                                <span class="status-badge" :class="user.status === 'active' ? 'status-success' : 'status-neutral'">{{ user.status || 'unknown' }}</span>
                                <span class="role-pill">{{ user.role_name || roleName(user.role_id) || 'No role' }}</span>
                            </div>

                            <div class="team-user-actions">
                                <label>
                                    <span>Role</span>
                                    <select class="input" :value="user.role_id || ''" :disabled="!canWriteTeam || updatingUserId === user.id" @change="changeRole(user, $event.target.value)">
                                        <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                                    </select>
                                </label>
                                <button class="btn btn-secondary" type="button" :disabled="!canWriteTeam || updatingUserId === user.id" @click="toggleStatus(user)">
                                    {{ user.status === 'active' ? 'Disable' : 'Activate' }}
                                </button>
                                <button class="btn btn-secondary btn-danger-ghost" type="button" :disabled="!canWriteTeam || deletingUserId === user.id" @click="deleteUser(user)">
                                    {{ deletingUserId === user.id ? 'Deleting...' : 'Delete' }}
                                </button>
                            </div>
                        </article>

                        <div v-if="!users.length" class="empty-state">No team users found. Adjust filters or add a new team member.</div>
                    </div>

                    <div v-if="meta.last_page > 1" class="team-pagination">
                        <button class="btn btn-secondary" type="button" :disabled="meta.current_page <= 1 || loading" @click="goToPage(meta.current_page - 1)">Previous</button>
                        <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                        <button class="btn btn-secondary" type="button" :disabled="meta.current_page >= meta.last_page || loading" @click="goToPage(meta.current_page + 1)">Next</button>
                    </div>
                </article>

                <article class="card card-pad role-matrix-card mobile-panel" :class="{ 'mobile-panel-active': activeMobilePanel === 'roles' }">
                    <div class="section-header">
                        <div>
                            <h3 class="card-title">Role permissions</h3>
                            <p class="card-subtitle">Business-readable access map returned by the backend.</p>
                        </div>
                    </div>

                    <div class="role-grid">
                        <article v-for="role in roles" :key="role.id" class="role-card">
                            <div class="role-card-head">
                                <div>
                                    <strong>{{ role.name }}</strong>
                                    <p>{{ role.description || role.slug }}</p>
                                </div>
                                <span>{{ role.capability_count || role.capabilities?.length || 0 }}</span>
                            </div>
                            <div class="capability-chip-list">
                                <span v-for="capability in role.capabilities || []" :key="capability.code" class="capability-chip">{{ capabilityLabel(capability.code) }}</span>
                            </div>
                        </article>
                    </div>
                </article>
            </div>

            <aside class="team-side-column">
                <article ref="createFormCard" class="card card-pad add-user-card mobile-panel" :class="{ 'mobile-panel-active': activeMobilePanel === 'invite' }">
                    <div class="section-header">
                        <div>
                            <h3 class="card-title">Add team member</h3>
                            <p class="card-subtitle">Create a merchant portal login with a controlled role.</p>
                        </div>
                    </div>

                    <form class="team-form" @submit.prevent="createUser">
                        <label class="field">
                            <span class="field-label">Name</span>
                            <input v-model.trim="form.name" class="input" type="text" :readonly="!canWriteTeam" placeholder="Operations user" />
                            <span v-if="fieldErrors.name" class="field-error">{{ fieldErrors.name }}</span>
                        </label>
                        <label class="field">
                            <span class="field-label">Email</span>
                            <input v-model.trim="form.email" class="input" type="email" :readonly="!canWriteTeam" placeholder="ops@example.com" />
                            <span v-if="fieldErrors.email" class="field-error">{{ fieldErrors.email }}</span>
                        </label>
                        <label class="field">
                            <span class="field-label">Temporary password</span>
                            <input v-model="form.password" class="input" type="password" :readonly="!canWriteTeam" autocomplete="new-password" placeholder="Minimum 8 characters" />
                            <span v-if="fieldErrors.password" class="field-error">{{ fieldErrors.password }}</span>
                        </label>
                        <div class="form-split">
                            <label class="field">
                                <span class="field-label">Role</span>
                                <select v-model="form.role_id" class="input" :disabled="!canWriteTeam">
                                    <option value="">Select role</option>
                                    <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                                </select>
                                <span v-if="fieldErrors.role_id" class="field-error">{{ fieldErrors.role_id }}</span>
                            </label>
                            <label class="field">
                                <span class="field-label">Status</span>
                                <select v-model="form.status" class="input" :disabled="!canWriteTeam">
                                    <option value="active">Active</option>
                                    <option value="disabled">Disabled</option>
                                </select>
                            </label>
                        </div>

                        <div class="role-preview">
                            <span>Selected access</span>
                            <strong>{{ selectedRole?.name || 'No role selected' }}</strong>
                            <p>{{ selectedRole?.description || 'Choose a role to preview its permissions.' }}</p>
                            <div v-if="selectedRole" class="capability-chip-list">
                                <span v-for="capability in selectedRole.capabilities || []" :key="capability.code" class="capability-chip">{{ capabilityLabel(capability.code) }}</span>
                            </div>
                        </div>

                        <button class="btn btn-primary btn-block" type="submit" :disabled="!canWriteTeam || saving">
                            {{ saving ? 'Creating...' : 'Create user' }}
                        </button>
                        <p v-if="!canWriteTeam" class="field-help">Your role can view team access, but cannot change it.</p>
                    </form>
                </article>

                <article class="card card-pad team-safety-card">
                    <h3 class="card-title">Access safeguards</h3>
                    <div class="safety-list">
                        <div><span class="safety-dot"></span><p>At least one active owner must remain.</p></div>
                        <div><span class="safety-dot"></span><p>Write actions require <code>merchant_users.write</code>.</p></div>
                        <div><span class="safety-dot"></span><p>Roles are scoped to merchant access only.</p></div>
                    </div>
                </article>
            </aside>
        </section>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useAuthStore } from '../../../stores/auth';
import { merchantApi } from '../../services/merchantApi';

const authStore = useAuthStore();
const loading = ref(true);
const saving = ref(false);
const users = ref([]);
const roles = ref([]);
const updatingUserId = ref(null);
const deletingUserId = ref(null);
const createFormCard = ref(null);
const activeMobilePanel = ref('directory');
let refreshTimer = null;
let toastTimer = null;

const toast = reactive({ message: '', type: 'success' });
const filters = reactive({
    search: '',
    status: '',
    role_id: '',
    created_from: '',
    created_to: '',
    sort: 'created_at',
    direction: 'desc',
    per_page: 15,
    page: 1,
});
const meta = reactive({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
const form = reactive({ name: '', email: '', password: '', role_id: '', status: 'active' });
const fieldErrors = reactive({});

const canWriteTeam = computed(() => authStore.hasCapability('merchant_users.write'));
const activeUsers = computed(() => users.value.filter((user) => user.status === 'active').length);
const disabledUsers = computed(() => users.value.filter((user) => user.status === 'disabled').length);
const ownerUsers = computed(() => users.value.filter((user) => user.role_slug === 'merchant.owner' && user.status === 'active').length);
const selectedRole = computed(() => roles.value.find((role) => String(role.id) === String(form.role_id)) || null);
const activeTeamFilterCount = computed(() => [
    filters.search,
    filters.status,
    filters.role_id,
    filters.created_from,
    filters.created_to,
    filters.sort !== 'created_at' ? filters.sort : '',
    filters.direction !== 'desc' ? filters.direction : '',
    filters.per_page !== 15 ? filters.per_page : '',
].filter(Boolean).length);
const metrics = computed(() => [
    { label: 'Total users', value: meta.total, note: `${users.value.length} shown on this page`, tone: 'metric-blue' },
    { label: 'Active access', value: activeUsers.value, note: 'Can sign in now', tone: 'metric-green' },
    { label: 'Disabled', value: disabledUsers.value, note: 'Access suspended', tone: 'metric-gray' },
    { label: 'Protected owners', value: ownerUsers.value, note: 'Last owner cannot be removed', tone: 'metric-amber' },
]);

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
const roleName = (roleId) => roles.value.find((role) => String(role.id) === String(roleId))?.name;
const initials = (user) => {
    const source = user.name || user.email || 'U';
    return source.split(/[ @._-]+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase()).join('') || 'U';
};
const capabilityLabel = (code) => code.replaceAll('_', ' ').replace('.', ' / ');

const resetFieldErrors = () => Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key]);
const setFieldErrors = (errors = {}) => {
    resetFieldErrors();
    Object.entries(errors).forEach(([key, messages]) => {
        fieldErrors[key] = Array.isArray(messages) ? messages[0] : messages;
    });
};
const resetForm = () => {
    form.name = '';
    form.email = '';
    form.password = '';
    form.role_id = roles.value[0]?.id || '';
    form.status = 'active';
    resetFieldErrors();
};
const requestParams = () => ({
    search: filters.search || undefined,
    status: filters.status || undefined,
    role_id: filters.role_id || undefined,
    created_from: filters.created_from || undefined,
    created_to: filters.created_to || undefined,
    sort: filters.sort,
    direction: filters.direction,
    per_page: filters.per_page,
    page: filters.page,
});

const clearToast = () => {
    toast.message = '';
    if (toastTimer) {
        window.clearTimeout(toastTimer);
        toastTimer = null;
    }
};

const showToast = (message, type = 'success') => {
    toast.message = message;
    toast.type = type;
    if (toastTimer) window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(clearToast, 3200);
};

const loadUsers = async ({ silent = false } = {}) => {
    if (!silent) loading.value = true;

    try {
        const response = await merchantApi.users(requestParams());
        const payload = response.data?.data;
        users.value = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : [];
        roles.value = response.data?.roles || [];

        const responseMeta = response.data?.meta || payload || {};
        meta.current_page = Number(responseMeta.current_page || 1);
        meta.last_page = Number(responseMeta.last_page || 1);
        meta.per_page = Number(responseMeta.per_page || filters.per_page);
        meta.total = Number(responseMeta.total || users.value.length);

        if (!form.role_id && roles.value.length) form.role_id = roles.value[0].id;
    } catch (exception) {
        if (!silent) showToast(exception?.response?.data?.message || 'Failed to load team users.', 'danger');
    } finally {
        if (!silent) loading.value = false;
    }
};

const applyFilters = () => {
    filters.page = 1;
    loadUsers();
};
const clearFilters = () => {
    filters.search = '';
    filters.status = '';
    filters.role_id = '';
    filters.created_from = '';
    filters.created_to = '';
    filters.sort = 'created_at';
    filters.direction = 'desc';
    filters.page = 1;
    loadUsers();
};
const goToPage = (page) => {
    filters.page = page;
    loadUsers();
};
const openMobilePanel = (panel) => {
    activeMobilePanel.value = panel;
};
const focusCreateForm = () => {
    openMobilePanel('invite');
    createFormCard.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const createUser = async () => {
    if (!canWriteTeam.value) return;
    saving.value = true;
    resetFieldErrors();

    try {
        const response = await merchantApi.createUser({ ...form });
        const created = response.data?.data;
        if (created) {
            users.value = [created, ...users.value].slice(0, filters.per_page);
            meta.total += 1;
        }
        showToast('Team member created.');
        resetForm();
    } catch (exception) {
        setFieldErrors(exception?.response?.data?.errors || {});
        showToast(exception?.response?.data?.message || 'Failed to create team member.', 'danger');
    } finally {
        saving.value = false;
    }
};

const changeRole = async (user, roleId) => {
    if (!canWriteTeam.value || !roleId || String(roleId) === String(user.role_id)) return;
    updatingUserId.value = user.id;

    try {
        const response = await merchantApi.updateUserRole(user.id, { role_id: roleId });
        Object.assign(user, response.data?.data || {});
        showToast(`${user.name || user.email} role updated.`);
    } catch (exception) {
        showToast(exception?.response?.data?.message || 'Failed to update role.', 'danger');
        loadUsers();
    } finally {
        updatingUserId.value = null;
    }
};

const toggleStatus = async (user) => {
    if (!canWriteTeam.value) return;
    updatingUserId.value = user.id;

    try {
        const nextStatus = user.status === 'active' ? 'disabled' : 'active';
        const response = await merchantApi.updateUserStatus(user.id, { status: nextStatus });
        Object.assign(user, response.data?.data || {});
        showToast(`${user.name || user.email} ${nextStatus === 'active' ? 'activated' : 'disabled'}.`);
    } catch (exception) {
        showToast(exception?.response?.data?.message || 'Failed to update status.', 'danger');
    } finally {
        updatingUserId.value = null;
    }
};

const deleteUser = async (user) => {
    if (!canWriteTeam.value) return;
    if (!window.confirm(`Delete ${user.name || user.email}? This cannot be undone.`)) return;
    deletingUserId.value = user.id;

    try {
        await merchantApi.deleteUser(user.id);
        users.value = users.value.filter((item) => item.id !== user.id);
        meta.total = Math.max(0, meta.total - 1);
        showToast(`${user.name || user.email} deleted.`);
    } catch (exception) {
        showToast(exception?.response?.data?.message || 'Failed to delete user.', 'danger');
    } finally {
        deletingUserId.value = null;
    }
};

onMounted(() => {
    loadUsers();
    refreshTimer = window.setInterval(() => loadUsers({ silent: true }), 500);
});

onBeforeUnmount(() => {
    if (refreshTimer) window.clearInterval(refreshTimer);
    if (toastTimer) window.clearTimeout(toastTimer);
});
</script>

<style scoped>
.team-metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.team-toast {
    position: fixed;
    right: 24px;
    top: calc(var(--m-topbar-height, 70px) + 18px);
    z-index: 90;
    width: min(380px, calc(100vw - 32px));
    display: grid;
    grid-template-columns: 10px 1fr auto;
    gap: 12px;
    align-items: start;
    padding: 14px;
    border: 1px solid rgba(214, 221, 232, 0.95);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.96);
    box-shadow: 0 18px 46px rgba(16, 24, 40, 0.18);
    backdrop-filter: blur(14px);
    animation: team-toast-in 180ms ease-out;
    overflow: hidden;
}

.team-toast::before {
    content: '';
    position: absolute;
    inset: -40px auto auto -42px;
    width: 118px;
    height: 118px;
    border-radius: 999px;
    background: rgba(22, 163, 74, 0.18);
    filter: blur(18px);
    pointer-events: none;
}

.team-toast::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.16);
    pointer-events: none;
}

.team-toast > * {
    position: relative;
    z-index: 1;
}

.team-toast > span {
    width: 10px;
    height: 10px;
    margin-top: 5px;
    border-radius: 999px;
    background: var(--color-success-500);
}

.team-toast-danger > span {
    background: var(--color-danger-500);
}

.team-toast-danger::before {
    background: rgba(240, 68, 56, 0.14);
}

.team-toast-danger::after {
    box-shadow: inset 0 0 0 1px rgba(240, 68, 56, 0.14);
}

.team-toast strong {
    display: block;
    color: var(--color-text);
    font-size: 13px;
    line-height: 1.2;
}

.team-toast p {
    margin: 4px 0 0;
    color: var(--color-text-muted);
    font-size: 13px;
    line-height: 1.35;
}

.team-toast button {
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 999px;
    background: var(--color-surface-subtle);
    color: var(--color-text-muted);
    cursor: pointer;
}

.team-metric-card {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.team-metric-card p,
.mobile-team-hero p,
.role-preview span {
    margin: 0;
    color: var(--color-text-muted);
    font-size: 12px;
    font-weight: 650;
}

.team-metric-card strong,
.mobile-team-hero strong {
    display: block;
    margin-top: 3px;
    color: var(--color-text);
    font-size: 22px;
    line-height: 1.15;
}

.team-metric-card small,
.mobile-team-hero small {
    display: block;
    margin-top: 4px;
    color: var(--color-text-subtle);
    font-size: 12px;
}

.metric-dot,
.safety-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    margin-top: 5px;
    flex: 0 0 auto;
}

.metric-blue {
    background: var(--color-brand-500);
}

.metric-green,
.safety-dot {
    background: var(--color-success-500);
}

.metric-gray {
    background: var(--color-text-subtle);
}

.metric-amber {
    background: var(--color-warning-500);
}

.team-workspace {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 380px;
    gap: 20px;
    align-items: start;
}

.team-main-column,
.team-side-column,
.team-form,
.safety-list {
    display: grid;
    gap: 20px;
}

.team-side-column {
    position: sticky;
    top: calc(var(--topbar-height) + 24px);
}

.team-filter-bar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) repeat(3, minmax(130px, 150px)) auto;
    gap: 10px;
    padding: 0 20px 16px;
    border-bottom: 1px solid var(--color-border);
}

.team-mobile-filter-strip {
    display: none;
}

.team-filter-bar input[type='date'],
.team-filter-bar select:nth-of-type(4),
.team-filter-bar select:nth-of-type(5) {
    grid-row: 2;
}

.team-filter-bar button {
    grid-row: span 2;
}

.team-user-list,
.team-loading {
    display: grid;
    gap: 1px;
    background: var(--color-border);
    border-top: 1px solid var(--color-border);
}

.team-user-card {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) 210px minmax(300px, 390px);
    gap: 16px;
    align-items: center;
    padding: 16px 20px;
    background: var(--color-surface);
}

.team-user-main {
    display: flex;
    gap: 12px;
    align-items: center;
    min-width: 0;
}

.user-avatar {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    background: var(--color-brand-50);
    color: var(--color-brand-700);
    font-size: 13px;
    font-weight: 800;
    flex: 0 0 auto;
}

.team-user-name-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.team-user-main strong {
    display: block;
    color: var(--color-text);
    font-size: 14px;
}

.team-user-main p,
.team-user-main small {
    margin: 3px 0 0;
    color: var(--color-text-muted);
    font-size: 12px;
    overflow-wrap: anywhere;
}

.team-user-status {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.role-pill {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    background: var(--color-surface-subtle);
    color: var(--color-text);
    border: 1px solid var(--color-border);
    font-size: 12px;
    font-weight: 700;
}

.team-user-actions {
    display: grid;
    grid-template-columns: minmax(130px, 1fr) auto auto;
    gap: 8px;
    align-items: end;
}

.dossier-link {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 0 9px;
    border: 1px solid rgba(36, 107, 254, 0.18);
    border-radius: 999px;
    background: linear-gradient(180deg, #ffffff 0%, var(--color-brand-50) 100%);
    color: var(--color-brand-700);
    font-size: 11px;
    font-weight: 800;
    text-decoration: none;
    box-shadow: 0 5px 12px rgba(36, 107, 254, 0.08);
}

.dossier-link::before {
    content: '';
    width: 4px;
    height: 4px;
    margin-right: 6px;
    border-radius: 999px;
    background: var(--color-brand-500);
}

.dossier-link:hover {
    border-color: rgba(36, 107, 254, 0.28);
    color: var(--color-brand-500);
    transform: translateY(-1px);
}

.team-user-actions label {
    display: grid;
    gap: 5px;
}

.team-user-actions span {
    color: var(--color-text-muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.btn-danger-ghost {
    color: var(--color-danger-700);
    border-color: var(--color-danger-50);
}

.team-skeleton {
    height: 76px;
    background: linear-gradient(90deg, var(--color-surface) 0%, var(--color-surface-hover) 50%, var(--color-surface) 100%);
    background-size: 180% 100%;
    animation: team-shimmer 1.2s ease-in-out infinite;
}

.team-pagination {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    border-top: 1px solid var(--color-border);
    color: var(--color-text-muted);
    font-size: 13px;
}

.role-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.role-card {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: 14px;
    background: var(--color-surface-subtle);
}

.role-card-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}

.role-card-head strong,
.role-preview strong {
    color: var(--color-text);
    font-size: 14px;
}

.role-card-head p,
.role-preview p {
    margin: 3px 0 0;
    color: var(--color-text-muted);
    font-size: 12px;
    line-height: 1.4;
}

.role-card-head > span {
    width: 30px;
    height: 30px;
    border-radius: 999px;
    display: grid;
    place-items: center;
    background: var(--color-brand-50);
    color: var(--color-brand-700);
    font-size: 12px;
    font-weight: 800;
}

.capability-chip-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.capability-chip {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 0 8px;
    border-radius: 999px;
    background: #fff;
    border: 1px solid var(--color-border);
    color: var(--color-text-muted);
    font-size: 11px;
    font-weight: 700;
}

.team-form,
.safety-list {
    gap: 14px;
}

.form-split {
    display: grid;
    grid-template-columns: 1fr 120px;
    gap: 10px;
}

.field-error {
    color: var(--color-danger-700);
    font-size: 12px;
}

.role-preview {
    padding: 14px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
    background: var(--color-surface-subtle);
}

.role-preview .capability-chip-list {
    margin-top: 10px;
}

.btn-block {
    width: 100%;
}

.safety-list > div {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.safety-list p {
    margin: 0;
    color: var(--color-text-muted);
    font-size: 13px;
    line-height: 1.45;
}

.safety-list code {
    font-size: 12px;
}

.mobile-team-hero {
    display: none;
}

.mobile-team-tabs,
.mobile-team-summary {
    display: none;
}

@keyframes team-shimmer {
    to {
        background-position: -180% 0;
    }
}

@keyframes team-toast-in {
    from {
        opacity: 0;
        transform: translateY(-10px) scale(0.98);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@media (max-width: 1199px) {
    .team-workspace {
        grid-template-columns: 1fr;
    }

    .team-side-column {
        position: static;
        grid-row: 1;
    }

    .team-user-card {
        grid-template-columns: minmax(220px, 1fr) 190px;
    }

    .team-user-actions {
        grid-column: 1 / -1;
    }
}

@media (max-width: 767px) {
    .team-page {
        gap: 14px;
        padding-bottom: 18px;
    }

    .team-header {
        display: none;
    }

    .mobile-team-hero {
        position: relative;
        display: flex;
        justify-content: space-between;
        gap: 14px;
        align-items: center;
        padding: 20px;
        border-radius: 26px;
        background:
            radial-gradient(circle at 18% 8%, rgba(36, 107, 254, 0.22), transparent 28%),
            radial-gradient(circle at 92% 18%, rgba(22, 163, 74, 0.16), transparent 30%),
            linear-gradient(145deg, #ffffff 0%, #f5f9ff 56%, #f8fafc 100%);
        border: 1px solid rgba(214, 221, 232, 0.9);
        box-shadow: 0 18px 38px rgba(16, 24, 40, 0.11);
        overflow: hidden;
    }

    .mobile-team-hero::after {
        content: '';
        position: absolute;
        right: -38px;
        bottom: -50px;
        width: 126px;
        height: 126px;
        border-radius: 999px;
        background: rgba(36, 107, 254, 0.08);
        pointer-events: none;
    }

    .mobile-team-hero > * {
        position: relative;
        z-index: 1;
    }

    .mobile-team-hero .btn {
        flex: 0 0 auto;
        min-height: 42px;
        padding: 0 14px;
        border-radius: 16px;
        box-shadow: 0 10px 22px rgba(36, 107, 254, 0.22);
    }

    .mobile-team-tabs {
        position: sticky;
        top: 10px;
        z-index: 4;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 7px;
        padding: 7px;
        border: 1px solid rgba(214, 221, 232, 0.95);
        border-radius: 22px;
        background: rgba(247, 248, 250, 0.9);
        box-shadow: 0 10px 28px rgba(16, 24, 40, 0.08);
        backdrop-filter: blur(12px);
    }

    .mobile-team-tabs button {
        position: relative;
        overflow: hidden;
        min-width: 0;
        min-height: 52px;
        border: 0;
        border-radius: 17px;
        background: transparent;
        color: var(--color-text-muted);
        font-size: 13px;
        font-weight: 800;
        display: grid;
        place-items: center;
        gap: 2px;
        transition: color 160ms ease, transform 160ms ease, box-shadow 160ms ease;
    }

    .mobile-team-tabs button::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: linear-gradient(135deg, #155eef 0%, #246bfe 56%, #16a34a 140%);
        opacity: 0;
        transition: opacity 160ms ease;
        z-index: -1;
    }

    .mobile-team-tabs button.mobile-tab-active {
        color: #ffffff !important;
        transform: translateY(-1px);
        box-shadow: 0 10px 22px rgba(36, 107, 254, 0.26);
    }

    .mobile-team-tabs button.mobile-tab-active::before {
        opacity: 1;
    }

    .mobile-team-tabs span {
        font-size: 10px;
        font-weight: 750;
        opacity: 0.76;
    }

    .mobile-team-summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 9px;
    }

    .mobile-team-summary article {
        position: relative;
        overflow: hidden;
        min-width: 0;
        min-height: 84px;
        padding: 13px 10px;
        border: 1px solid rgba(229, 233, 240, 0.95);
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
        box-shadow: 0 8px 18px rgba(16, 24, 40, 0.06);
        display: grid;
        place-items: center;
        align-content: center;
        text-align: center;
    }

    .mobile-team-summary article::before {
        content: '';
        display: block;
        width: 24px;
        height: 3px;
        margin: 0 auto 9px;
        border-radius: 999px;
        background: var(--color-brand-500);
    }

    .mobile-team-summary article:nth-child(2)::before {
        background: var(--color-warning-500);
    }

    .mobile-team-summary article:nth-child(3)::before {
        background: var(--color-success-500);
    }

    .mobile-team-summary span {
        display: block;
        color: #667085;
        font-size: 12px;
        font-weight: 650;
        line-height: 1.15;
    }

    .mobile-team-summary strong {
        display: block;
        margin-top: 6px;
        color: var(--color-text);
        font-size: 24px;
        font-weight: 760;
        line-height: 1.05;
    }

    .team-metric-grid {
        display: none;
    }

    .team-workspace,
    .team-main-column,
    .team-side-column {
        display: contents;
    }

    .mobile-panel {
        display: none;
    }

    .mobile-panel.mobile-panel-active {
        display: block;
        animation: mobile-panel-in 180ms ease-out;
    }

    .team-directory-card,
    .add-user-card,
    .role-matrix-card,
    .team-safety-card {
        border-radius: 24px;
        border-color: rgba(214, 221, 232, 0.95);
        box-shadow: 0 14px 32px rgba(16, 24, 40, 0.09);
        overflow: hidden;
    }

    .team-directory-card .section-header,
    .role-matrix-card .section-header,
    .add-user-card .section-header {
        align-items: flex-start;
        gap: 10px;
        padding: 18px 16px 14px;
    }

    .team-directory-card .card-title,
    .role-matrix-card .card-title,
    .add-user-card .card-title {
        font-size: 18px;
        font-weight: 760;
        line-height: 1.18;
    }

    .team-directory-card .card-subtitle,
    .role-matrix-card .card-subtitle,
    .add-user-card .card-subtitle {
        margin-top: 5px;
        color: #667085;
        font-size: 13px;
        line-height: 1.4;
    }

    .team-directory-card .section-header .status-badge {
        margin-top: 2px;
    }

    .team-filter-bar {
        display: none;
    }

    .team-mobile-filter-strip {
        display: grid;
        gap: 9px;
        padding: 0 16px 16px;
        background: var(--color-surface);
    }

    .team-mobile-filter-strip > .input {
        min-height: 44px;
        border-radius: 14px;
    }

    .team-mobile-filter-panel {
        border: 1px solid rgba(214, 221, 232, 0.95);
        border-radius: 16px;
        background:
            radial-gradient(circle at top right, rgba(36, 107, 254, 0.08), transparent 34%),
            var(--color-surface-subtle);
        overflow: hidden;
    }

    .team-mobile-filter-panel summary {
        min-height: 44px;
        padding: 0 13px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        color: var(--color-text);
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
        list-style: none;
    }

    .team-mobile-filter-panel summary::-webkit-details-marker {
        display: none;
    }

    .team-mobile-filter-panel summary strong {
        min-width: 22px;
        height: 22px;
        border-radius: 999px;
        display: inline-grid;
        place-items: center;
        background: var(--color-brand-600);
        color: #ffffff;
        font-size: 11px;
        font-weight: 850;
    }

    .team-mobile-filter-body {
        display: grid;
        gap: 10px;
        padding: 0 10px 10px;
    }

    .team-mobile-filter-body .input,
    .team-mobile-filter-body .btn {
        min-height: 42px;
        border-radius: 13px;
    }

    .team-mobile-date-grid,
    .team-mobile-sort-grid,
    .team-mobile-filter-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .team-user-list,
    .team-loading {
        background: transparent;
        border-top: 0;
        gap: 12px;
        padding: 0 16px 16px;
    }

    .team-user-card {
        grid-template-columns: 1fr;
        gap: 13px;
        padding: 16px;
        border: 1px solid rgba(214, 221, 232, 0.95);
        border-radius: 22px;
        box-shadow: 0 10px 24px rgba(16, 24, 40, 0.07);
        background:
            radial-gradient(circle at 8% 0%, rgba(36, 107, 254, 0.08), transparent 26%),
            linear-gradient(180deg, #ffffff 0%, #ffffff 62%, #f9fafb 100%);
    }

    .team-user-main {
        align-items: flex-start;
    }

    .user-avatar {
        width: 46px;
        height: 46px;
        border-radius: 16px;
        background: linear-gradient(135deg, #eef5ff 0%, #dceaff 100%);
        box-shadow: inset 0 0 0 1px rgba(36, 107, 254, 0.12);
    }

    .team-user-main strong {
        font-size: 15px;
    }

    .team-user-main p {
        font-size: 13px;
    }

    .team-user-status {
        padding-top: 2px;
    }

    .team-user-actions {
        grid-template-columns: 1fr;
        padding-top: 4px;
    }

    .team-user-actions .btn,
    .team-user-actions .input {
        width: 100%;
        min-height: 44px;
        border-radius: 14px;
    }

    .team-pagination {
        justify-content: space-between;
        padding: 14px 16px 16px;
        border-top: 0;
        font-size: 12px;
    }

    .team-pagination .btn {
        min-height: 40px;
        padding: 0 12px;
    }

    .add-user-card {
        padding: 0;
    }

    .team-form {
        gap: 13px;
        padding: 0 16px 16px;
    }

    .team-form .input,
    .team-form .btn {
        min-height: 46px;
        border-radius: 14px;
    }

    .form-split {
        grid-template-columns: 1fr;
    }

    .role-preview {
        border-radius: 18px;
        padding: 14px;
        background:
            radial-gradient(circle at top left, rgba(36, 107, 254, 0.1), transparent 34%),
            #f8fbff;
    }

    .role-matrix-card {
        padding: 0;
    }

    .role-grid {
        grid-template-columns: 1fr;
        gap: 10px;
        padding: 0 16px 16px;
    }

    .role-card {
        border-radius: 22px;
        padding: 16px;
        box-shadow: 0 8px 18px rgba(16, 24, 40, 0.06);
        background:
            radial-gradient(circle at 0% 0%, rgba(36, 107, 254, 0.08), transparent 30%),
            linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
    }

    .role-card-head {
        align-items: flex-start;
    }

    .capability-chip-list {
        gap: 7px;
    }

    .capability-chip {
        min-height: 26px;
        max-width: 100%;
        white-space: normal;
    }

    .team-safety-card {
        display: block;
        padding: 16px;
    }

    .safety-list {
        gap: 12px;
    }

    .team-toast {
        top: calc(var(--m-topbar-height, 70px) + 10px);
        right: 12px;
        left: 14px;
        width: auto;
        border-radius: 22px;
        box-shadow: 0 18px 42px rgba(16, 24, 40, 0.2);
    }
}

@keyframes mobile-panel-in {
    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
