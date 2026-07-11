<template>
    <div class="merchant-v2 app-shell">
        <aside class="app-sidebar" :class="{ 'is-open': sidebarOpen }" aria-label="Merchant navigation">
            <section class="merchant-card">
                <p class="merchant-card__label">Merchant</p>
                <h1 class="merchant-card__name">{{ merchantName }}</h1>
            </section>

            <nav class="primary-nav">
                <RouterLink
                    v-for="item in navItems"
                    :key="item.to"
                    class="nav-link"
                    :to="item.to"
                    @click="closeSidebar"
                >
                    <span>{{ item.label }}</span>
                    <span aria-hidden="true">{{ item.mark }}</span>
                </RouterLink>
            </nav>

            <section class="sidebar-status">
                <strong>Integration status</strong>
                <p>Use Dashboard to track setup health, failed webhooks, and payment issues.</p>
            </section>

            <button class="sidebar-logout" type="button" :disabled="authStore.loading" @click="logout">
                {{ authStore.loading ? 'Signing out...' : 'Sign out' }}
            </button>
        </aside>

        <button v-if="sidebarOpen" class="sidebar-backdrop" type="button" aria-label="Close navigation" @click="closeSidebar"></button>

        <div class="app-main">
            <header class="app-topbar">
                <button class="topbar-menu" type="button" aria-label="Open navigation" @click="sidebarOpen = true">☰</button>
                <div class="command-search">Search payments, references, webhooks...</div>
                <RouterLink class="btn btn-primary" :to="{ name: 'merchant-v2.create-payment' }">Create payment</RouterLink>
                <span class="topbar-user" :title="userLabel">{{ userLabel }}</span>
            </header>

            <main class="page-content">
                <RouterView />
            </main>
        </div>

        <nav class="mobile-bottom-nav" aria-label="Mobile merchant navigation">
            <RouterLink :to="{ name: 'merchant-v2.dashboard' }">Home</RouterLink>
            <RouterLink :to="{ name: 'merchant-v2.payments' }">Payments</RouterLink>
            <RouterLink :to="{ name: 'merchant-v2.create-payment' }">Create</RouterLink>
            <RouterLink :to="{ name: 'merchant-v2.settlements' }">Settle</RouterLink>
            <RouterLink :to="{ name: 'merchant-v2.developers' }">More</RouterLink>
        </nav>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';

const authStore = useAuthStore();
const router = useRouter();
const route = useRoute();
const sidebarOpen = ref(false);

const navItems = computed(() => [
    { label: 'Dashboard', to: '/merchant/dashboard', mark: '01' },
    { label: 'Payments', to: '/merchant/payments', mark: '02' },
    { label: 'Create payment', to: '/merchant/payments/new', mark: '+' },
    { label: 'Developers', to: '/merchant/developers', mark: '03' },
    { label: 'Settlements', to: '/merchant/settlements', mark: '04' },
    { label: 'Team', to: '/merchant/team', mark: '05' },
    { label: 'Settings', to: '/merchant/settings', mark: '06' },
]);

const merchantName = computed(() => authStore.merchant?.name || 'Merchant');
const userLabel = computed(() => authStore.user?.name || authStore.user?.email || 'Merchant user');

const closeSidebar = () => {
    sidebarOpen.value = false;
};

watch(() => route.fullPath, closeSidebar);

const logout = async () => {
    await authStore.logout();
    await router.push({ name: 'merchant.login' });
};
</script>
