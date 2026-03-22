<template>
  <div class="merchant-layout">
    <aside class="sidebar">
      <div>
        <h1 class="brand">Merchant Portal</h1>
        <p class="merchant-name">{{ authStore.merchant?.name || 'Merchant' }}</p>
      </div>

      <nav class="nav">
          <RouterLink class="nav-link" to="/merchant" exact-active-class="router-link-active" active-class="">
              Dashboard
          </RouterLink>
          <RouterLink class="nav-link" to="/merchant/balances">Balances</RouterLink>
          <RouterLink class="nav-link" to="/merchant/wallets">Wallets</RouterLink>
          <RouterLink class="nav-link" to="/merchant/invoices">Invoices</RouterLink>
          <RouterLink class="nav-link" to="/merchant/webhook-settings">Webhook Settings</RouterLink>
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
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const authStore = useAuthStore();

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
