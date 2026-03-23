<template>
  <div class="merchant-layout">
    <aside class="sidebar">
      <div>
        <h1 class="brand">Merchant Portal</h1>
        <p class="merchant-name">{{ authStore.merchant?.name || 'Merchant' }}</p>
      </div>

      <nav class="nav">
          <RouterLink v-for="item in navigationItems"
                      :key="item.to"
                      class="nav-link"
                      :to="item.to"
                      exact-active-class="router-link-active"
                      active-class=""
                      >
              {{ item.label }}
          </RouterLink>
      </nav>

      <button class="logout-btn" type="button" :disabled="authStore.loading" @click="handleLogout">
        Logout
      </button>
    </aside>

    <main class="content">
      <header class="topbar">
        <strong>{{ authStore.user?.name || authStore.user?.email || 'Merchant User' }}</strong>
      </header>
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import {computed} from "vue";
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const authStore = useAuthStore();

const navigationItems = computed(() => ([
    { label: 'Dashboard', to: '/merchant', canView: authStore.hasCapability('portal.view') },
    { label: 'Balances', to: '/merchant/balances', canView: authStore.hasCapability('balances.read') },
    { label: 'Wallets', to: '/merchant/wallets', canView: authStore.hasCapability('wallets.read') },
    { label: 'Invoices', to: '/merchant/invoices', canView: authStore.hasCapability('invoices.read') },
    { label: 'Webhook Deliveries', to: '/merchant/webhook-deliveries', canView: authStore.hasCapability('webhooks.read') },
    { label: 'Webhook Settings', to: '/merchant/webhook-settings', canView: authStore.hasCapability('webhooks.read') },
    { label: 'API Keys', to: '/merchant/api-keys', canView: authStore.hasCapability('api_keys.read') },
]).filter((item) => item.canView));

const handleLogout = async () => {
  await authStore.logout();
  await router.push({ name: 'merchant.login' });
};
</script>

<style scoped>
.merchant-layout {
  min-height: 100vh;
  background: #f8fafc;
}

.sidebar {
  background: #0f172a;
  color: #cbd5e1;
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.brand {
  margin: 0;
  color: #f8fafc;
  font-size: 20px;
}

.merchant-name {
  margin: 8px 0 0;
  color: #94a3b8;
  font-size: 14px;
}

.nav {
  display: grid;
  gap: 8px;
}

.nav-link {
  color: #cbd5e1;
  text-decoration: none;
  border: 1px solid transparent;
  border-radius: 8px;
  padding: 10px 12px;
}

.nav-link.router-link-active {
  background: #1e293b;
  border-color: #334155;
  color: #f8fafc;
}

.logout-btn {
  margin-top: auto;
  border: 1px solid #334155;
  border-radius: 8px;
  background: transparent;
  color: #f8fafc;
  padding: 10px 12px;
  cursor: pointer;
}

.logout-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.content {
  padding: 20px;
}

.topbar {
  margin-bottom: 16px;
  color: #334155;
}

@media (min-width: 992px) {
  .merchant-layout {
    display: grid;
    grid-template-columns: 250px 1fr;
  }

  .content {
    padding: 24px;
  }
}
</style>
