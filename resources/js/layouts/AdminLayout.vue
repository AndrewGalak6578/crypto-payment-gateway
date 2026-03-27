<template>
    <div class="admin-layout">
        <aside class="sidebar">
            <div class="brand-wrap">
                <h1 class="brand">Ops Admin</h1>
                <p class="admin-meta">{{ adminLabel }}</p>
            </div>

            <nav class="nav">
                <RouterLink
                    v-for="item in navigationItems"
                    :key="item.to"
                    class="nav-link"
                    :to="item.to"
                    exact-active-class="router-link-active"
                    active-class=""
                >
                    <span>{{ item.label }}</span>
                </RouterLink>
            </nav>

            <button
                class="logout-btn"
                type="button"
                :disabled="adminAuthStore.loading"
                @click="handleLogout"
            >
                Logout
            </button>
        </aside>

        <main class="content">
            <header class="topbar">
                <div>
                    <p class="topbar-label">Internal admin surface</p>
                    <strong>{{ adminLabel }}</strong>
                </div>
                <RouterLink class="topbar-link" :to="{ name: 'admin.dashboard' }">
                    Dashboard
                </RouterLink>
            </header>

            <div class="content-body">
                <RouterView />
            </div>
        </main>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAdminAuthStore } from '../stores/adminAuth';

const router = useRouter();
const adminAuthStore = useAdminAuthStore();

const navigationItems = [
    { label: 'Dashboard', to: '/admin' },
    { label: 'Merchants', to: '/admin/merchants' },
    { label: 'Merchant Users', to: '/admin/merchant-users' },
    { label: 'Invoices', to: '/admin/invoices' },
    { label: 'Webhook Deliveries', to: '/admin/webhook-deliveries' },
    { label: 'API Keys', to: '/admin/api-keys' },
];

const adminLabel = computed(() => {
    return (
        adminAuthStore.admin?.name ||
        adminAuthStore.admin?.email ||
        'Admin User'
    );
});

const handleLogout = async () => {
    await adminAuthStore.logout();
    await router.push({ name: 'admin.login' });
};
</script>

<style scoped>
.admin-layout {
    min-height: 100vh;
    background: linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
}

.sidebar {
    background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
    color: #cbd5e1;
    padding: 22px;
    display: flex;
    flex-direction: column;
    gap: 18px;
    border-right: 1px solid #1f2937;
}

.brand-wrap {
    border: 1px solid #1e293b;
    border-radius: 12px;
    padding: 12px;
    background: rgba(15, 23, 42, 0.4);
}

.brand {
    margin: 0;
    color: #f8fafc;
    font-size: 20px;
    letter-spacing: 0.3px;
}

.admin-meta {
    margin: 7px 0 0;
    color: #94a3b8;
    font-size: 13px;
}

.nav {
    display: grid;
    gap: 7px;
}

.nav-link {
    color: #cbd5e1;
    text-decoration: none;
    border: 1px solid transparent;
    border-radius: 9px;
    padding: 9px 11px;
    transition: all 0.15s ease;
}

.nav-link:hover {
    border-color: #334155;
    background: #0b1220;
}

.nav-link.router-link-active {
    background: #1e293b;
    border-color: #334155;
    color: #f8fafc;
}

.logout-btn {
    margin-top: auto;
    border: 1px solid #334155;
    border-radius: 9px;
    background: transparent;
    color: #f8fafc;
    padding: 10px 12px;
    cursor: pointer;
}

.logout-btn:hover {
    background: #111827;
}

.logout-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.content {
    padding: 14px;
}

.topbar {
    border: 1px solid #dbe5f1;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 12px;
    padding: 11px 14px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    color: #1e293b;
}

.topbar-label {
    margin: 0 0 3px;
    color: #64748b;
    font-size: 12px;
}

.topbar-link {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 7px 10px;
    color: #1e293b;
    text-decoration: none;
    background: #fff;
    font-size: 13px;
}

.content-body {
    display: grid;
    gap: 14px;
}

@media (min-width: 992px) {
    .admin-layout {
        display: grid;
        grid-template-columns: 272px 1fr;
    }

    .content {
        padding: 20px;
    }
}
</style>
