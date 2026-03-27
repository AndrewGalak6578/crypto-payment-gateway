<template>
    <div class="portal-layout admin-layout">
        <aside class="portal-sidebar" :class="{ 'is-open': sidebarOpen }" aria-label="Admin navigation">
            <div class="portal-brand-wrap">
                <h1 class="portal-brand">Ops Admin</h1>
                <p class="portal-meta">{{ adminLabel }}</p>
            </div>

            <nav class="portal-nav">
                <RouterLink
                    v-for="item in navigationItems"
                    :key="item.to"
                    class="portal-nav-link"
                    :to="item.to"
                    exact-active-class="router-link-active"
                    active-class=""
                    @click="closeSidebar"
                >
                    <span class="nav-link-label">{{ item.label }}</span>
                </RouterLink>
            </nav>

            <button
                class="portal-logout-btn"
                type="button"
                :disabled="adminAuthStore.loading"
                @click="handleLogout"
            >
                Logout
            </button>
        </aside>

        <button
            v-if="sidebarOpen"
            class="portal-sidebar-overlay"
            type="button"
            aria-label="Close menu"
            @click="closeSidebar"
        />

        <main class="portal-main">
            <header class="portal-topbar">
                <div class="portal-topbar-main">
                    <button
                        class="portal-topbar-menu"
                        type="button"
                        aria-label="Open menu"
                        @click="toggleSidebar"
                    >
                        ☰
                    </button>
                    <div>
                        <p class="portal-topbar-title">Admin Portal</p>
                        <p class="portal-topbar-subtitle">Internal operations panel</p>
                    </div>
                </div>
                <p class="portal-topbar-user">{{ adminLabel }}</p>
            </header>

            <div class="portal-shell">
                <RouterView />
            </div>
        </main>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
    return adminAuthStore.admin?.name || adminAuthStore.admin?.email || 'Admin User';
});

const closeSidebar = () => {
    sidebarOpen.value = false;
};

const toggleSidebar = () => {
    sidebarOpen.value = !sidebarOpen.value;
};

const handleEscape = (event) => {
    if (event.key === 'Escape') {
        closeSidebar();
    }
};

watch(
    () => route.fullPath,
    () => {
        closeSidebar();
    },
);

watch(sidebarOpen, (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : '';
});

onMounted(() => {
    window.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleEscape);
    document.body.style.overflow = '';
});

const handleLogout = async () => {
    await adminAuthStore.logout();
    await router.push({ name: 'admin.login' });
};
</script>

<style scoped>
@media (min-width: 1024px) {
    .portal-sidebar-overlay {
        display: none;
    }
}
</style>
