<template>
  <div class="portal-layout merchant-layout">
    <aside class="portal-sidebar" :class="{ 'is-open': sidebarOpen }" aria-label="Merchant navigation">
      <div class="portal-brand-wrap">
        <h1 class="portal-brand">Merchant Portal</h1>
        <p class="portal-meta">{{ merchantLabel }}</p>
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
          {{ item.label }}
        </RouterLink>
      </nav>

      <button class="portal-logout-btn" type="button" :disabled="authStore.loading" @click="handleLogout">
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
              <p class="portal-topbar-title">{{ currentSectionTitle }}</p>
            <p class="portal-topbar-subtitle">Operational workspace</p>
          </div>
        </div>

        <p class="portal-topbar-user" :title="userLabel">{{ userLabel }}</p>
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
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const sidebarOpen = ref(false);

const navigationItems = computed(() => ([
    { label: 'Dashboard', to: '/merchant', canView: authStore.hasCapability('portal.view') },
    { label: 'Balances', to: '/merchant/balances', canView: authStore.hasCapability('balances.read') },
    { label: 'Wallets', to: '/merchant/wallets', canView: authStore.hasCapability('wallets.read') },
    { label: 'Invoices', to: '/merchant/invoices', canView: authStore.hasCapability('invoices.read') },
    { label: 'Test Invoice', to: '/merchant/test-invoice', canView: authStore.hasCapability('invoices.read') },
    { label: 'Webhook Deliveries', to: '/merchant/webhook-deliveries', canView: authStore.hasCapability('webhooks.read') },
    { label: 'Webhook Settings', to: '/merchant/webhook-settings', canView: authStore.hasCapability('webhooks.read') },
    { label: 'API Keys', to: '/merchant/api-keys', canView: authStore.hasCapability('api_keys.read') },
]).filter((item) => item.canView));

const merchantLabel = computed(() => authStore.merchant?.name || 'Merchant');
const userLabel = computed(() => authStore.user?.name || authStore.user?.email || 'Merchant User');
const currentSectionTitle = computed(() => {
    const match = navigationItems.value.find((item) => {
        if (item.to === '/merchant') {
            return route.path === '/merchant';
        }

        return route.path.startsWith(item.to);
    });

    return match?.label || 'Merchant Portal';
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
  await authStore.logout();
  await router.push({ name: 'merchant.login' });
};
</script>

<style scoped>
@media (min-width: 1024px) {
  .portal-sidebar-overlay {
    display: none;
  }
}
</style>
