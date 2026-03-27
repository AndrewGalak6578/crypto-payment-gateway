<template>
    <div class="admin-layout">
        <aside class="sidebar" :class="{ 'is-open': sidebarOpen }">
            <div class="sidebar-header">
                <div class="brand-mark">OA</div>
                <div class="brand-copy">
                    <p class="brand-kicker">Internal Operations</p>
                    <h1 class="brand">Ops Admin</h1>
                </div>
            </div>

            <div class="sidebar-user">
                <p class="sidebar-user-label">Signed in as</p>
                <strong class="sidebar-user-name">{{ adminLabel }}</strong>
                <span class="sidebar-user-role">{{ adminRoleLabel }}</span>
            </div>

            <nav class="nav">
                <RouterLink
                    v-for="item in navigationItems"
                    :key="item.to"
                    class="nav-link"
                    :to="item.to"
                    exact-active-class="router-link-active"
                    active-class=""
                    @click="sidebarOpen = false"
                >
                    <span class="nav-link-label">{{ item.label }}</span>
                </RouterLink>
            </nav>

            <div class="sidebar-footer">
                <button
                    class="logout-btn"
                    type="button"
                    :disabled="adminAuthStore.loading"
                    @click="handleLogout"
                >
                    {{ adminAuthStore.loading ? 'Signing out...' : 'Logout' }}
                </button>
            </div>
        </aside>

        <div
            v-if="sidebarOpen"
            class="sidebar-backdrop"
            @click="sidebarOpen = false"
        />

        <main class="content">
            <header class="topbar">
                <div class="topbar-left">
                    <button
                        class="menu-btn"
                        type="button"
                        @click="sidebarOpen = !sidebarOpen"
                    >
                        Menu
                    </button>

                    <div class="topbar-copy">
                        <p class="topbar-kicker">Internal admin surface</p>
                        <h2 class="topbar-title">{{ currentSectionTitle }}</h2>
                    </div>
                </div>

                <div class="topbar-right">
                    <div class="topbar-user">
                        <span class="topbar-user-name">{{ adminLabel }}</span>
                        <span class="topbar-user-role">{{ adminRoleLabel }}</span>
                    </div>
                </div>
            </header>

            <div class="content-shell">
                <RouterView />
            </div>
        </main>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAdminAuthStore } from '../stores/adminAuth';

const router = useRouter();
const route = useRoute();
const adminAuthStore = useAdminAuthStore();
const sidebarOpen = ref(false);

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

const adminRoleLabel = computed(() => {
    const role = adminAuthStore.admin?.role;

    if (!role) {
        return 'Admin';
    }

    return String(role)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
});

const currentSectionTitle = computed(() => {
    const match = navigationItems.find((item) => {
        if (item.to === '/admin') {
            return route.path === '/admin';
        }

        return route.path.startsWith(item.to);
    });

    return match?.label || 'Admin Panel';
});

watch(
    () => route.fullPath,
    () => {
        sidebarOpen.value = false;
    }
);

const handleLogout = async () => {
    await adminAuthStore.logout();
    await router.push({ name: 'admin.login' });
};
</script>

<style scoped>
.admin-layout {
    min-height: 100vh;
    background:
        radial-gradient(circle at top left, rgba(148, 163, 184, 0.08), transparent 28%),
        linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
}

.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 40;
    width: 280px;
    display: flex;
    flex-direction: column;
    gap: 22px;
    padding: 22px 18px 18px;
    background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
    border-right: 1px solid rgba(51, 65, 85, 0.85);
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.32);
    transform: translateX(-100%);
    transition: transform 0.22s ease;
}

.sidebar.is-open {
    transform: translateX(0);
}

.sidebar-header {
    display: flex;
    align-items: center;
    gap: 12px;
}

.brand-mark {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #eff6ff;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.08em;
    flex-shrink: 0;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18);
}

.brand-copy {
    min-width: 0;
}

.brand-kicker {
    margin: 0 0 4px;
    font-size: 11px;
    line-height: 1;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
}

.brand {
    margin: 0;
    color: #f8fafc;
    font-size: 20px;
    line-height: 1.1;
    font-weight: 700;
}

.sidebar-user {
    display: grid;
    gap: 4px;
    padding: 14px;
    border: 1px solid rgba(51, 65, 85, 0.9);
    border-radius: 14px;
    background: rgba(15, 23, 42, 0.48);
}

.sidebar-user-label {
    margin: 0;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
}

.sidebar-user-name {
    color: #f8fafc;
    font-size: 14px;
    line-height: 1.4;
    word-break: break-word;
}

.sidebar-user-role {
    color: #94a3b8;
    font-size: 12px;
}

.nav {
    display: grid;
    gap: 6px;
}

.nav-link {
    display: flex;
    align-items: center;
    min-height: 42px;
    padding: 10px 12px;
    border: 1px solid transparent;
    border-radius: 12px;
    color: #cbd5e1;
    text-decoration: none;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease,
        color 0.15s ease,
        transform 0.15s ease;
}

.nav-link:hover {
    background: rgba(30, 41, 59, 0.82);
    border-color: rgba(51, 65, 85, 0.95);
    color: #f8fafc;
}

.nav-link.router-link-active {
    background: linear-gradient(180deg, rgba(37, 99, 235, 0.16), rgba(37, 99, 235, 0.08));
    border-color: rgba(59, 130, 246, 0.28);
    color: #f8fafc;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.nav-link-label {
    font-size: 14px;
    font-weight: 500;
}

.sidebar-footer {
    margin-top: auto;
    padding-top: 8px;
    border-top: 1px solid rgba(30, 41, 59, 0.95);
}

.logout-btn {
    width: 100%;
    min-height: 42px;
    border: 1px solid rgba(51, 65, 85, 0.95);
    border-radius: 12px;
    background: rgba(15, 23, 42, 0.42);
    color: #f8fafc;
    padding: 10px 12px;
    cursor: pointer;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease,
        opacity 0.15s ease;
}

.logout-btn:hover {
    background: rgba(30, 41, 59, 0.9);
    border-color: rgba(71, 85, 105, 1);
}

.logout-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.sidebar-backdrop {
    position: fixed;
    inset: 0;
    z-index: 30;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(2px);
}

.content {
    min-width: 0;
    padding: 14px;
}

.topbar {
    position: sticky;
    top: 0;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
    padding: 14px 16px;
    border: 1px solid rgba(226, 232, 240, 0.95);
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.88);
    backdrop-filter: blur(10px);
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.menu-btn {
    min-height: 38px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: #ffffff;
    color: #0f172a;
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.topbar-copy {
    min-width: 0;
}

.topbar-kicker {
    margin: 0 0 4px;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.topbar-title {
    margin: 0;
    color: #0f172a;
    font-size: 20px;
    line-height: 1.2;
    font-weight: 700;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.topbar-user {
    display: grid;
    justify-items: end;
    gap: 2px;
}

.topbar-user-name {
    color: #0f172a;
    font-size: 14px;
    font-weight: 600;
}

.topbar-user-role {
    color: #64748b;
    font-size: 12px;
}

.content-shell {
    display: grid;
    gap: 16px;
    min-width: 0;
}

@media (max-width: 991px) {
    .topbar-user {
        display: none;
    }
}

@media (min-width: 992px) {
    .admin-layout {
        display: grid;
        grid-template-columns: 280px minmax(0, 1fr);
    }

    .sidebar {
        position: sticky;
        top: 0;
        height: 100vh;
        transform: none;
        box-shadow: none;
    }

    .sidebar-backdrop,
    .menu-btn {
        display: none;
    }

    .content {
        padding: 24px 24px 24px 20px;
    }
}
</style>
