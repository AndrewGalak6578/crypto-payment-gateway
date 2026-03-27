<template>
  <section class="portal-shell">
    <header class="page-header">
      <div>
        <h2 class="page-title">Dashboard</h2>
        <p class="page-subtitle">Operational summary across balances, wallets and recent invoices.</p>
      </div>
    </header>

    <p v-if="loading" class="loading-state">Loading dashboard...</p>
    <p v-else-if="error" class="empty-state error">{{ error }}</p>

    <template v-else>
      <div class="stats-grid">
        <article class="card-surface stats-card">
          <h3>Paid Invoices</h3>
          <strong>{{ dashboard.stats?.paid_invoices_count ?? 0 }}</strong>
        </article>
        <article class="card-surface stats-card">
          <h3>Pending / Fixated</h3>
          <strong>{{ dashboard.stats?.pending_invoices_count ?? 0 }}</strong>
        </article>
        <article class="card-surface stats-card">
          <h3>Failed Webhooks</h3>
          <strong>{{ dashboard.stats?.failed_webhook_deliveries_count ?? 0 }}</strong>
        </article>
      </div>

      <div class="panel-grid">
        <article class="card-surface panel">
          <h3>Balances</h3>
          <ul class="list" v-if="dashboard.balances?.length">
            <li v-for="balance in dashboard.balances" :key="balance.coin">
              <span>{{ balance.coin }}</span>
              <strong>{{ balance.amount }}</strong>
            </li>
          </ul>
          <p v-else class="empty-state inline-state">No balances.</p>
        </article>

        <article class="card-surface panel">
          <h3>Wallets</h3>
          <ul class="list" v-if="dashboard.wallets?.length">
            <li v-for="wallet in dashboard.wallets" :key="wallet.id">
              <span>{{ wallet.coin }}</span>
              <strong>{{ wallet.wallet }}</strong>
            </li>
          </ul>
          <p v-else class="empty-state inline-state">No wallets.</p>
        </article>
      </div>

      <article class="table-shell">
        <div class="table-wrap">
          <table class="table-base">
            <thead>
              <tr>
                <th>Public ID</th>
                <th>Status</th>
                <th>Coin</th>
                <th>Amount</th>
                <th>Expected USD</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="invoice in dashboard.recent_invoices || []" :key="invoice.id">
                <td>
                  <RouterLink :to="`/merchant/invoices/${invoice.id}`">{{ invoice.public_id }}</RouterLink>
                </td>
                <td>
                  <span class="status-badge status-badge-muted">{{ invoice.status }}</span>
                </td>
                <td>{{ invoice.coin }}</td>
                <td>{{ invoice.amount_coin }}</td>
                <td>{{ invoice.expected_usd }}</td>
                <td>{{ formatDate(invoice.created_at) }}</td>
              </tr>
              <tr v-if="!(dashboard.recent_invoices || []).length">
                <td colspan="6" class="empty-row">No recent invoices yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </template>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api from '../../api/axios';

const loading = ref(true);
const error = ref('');

const dashboard = reactive({
  stats: null,
  balances: [],
  recent_invoices: [],
  wallets: [],
});

const formatDate = (dateString) => {
  if (!dateString) {
    return '—';
  }

  return new Date(dateString).toLocaleString();
};

const loadDashboard = async () => {
  loading.value = true;
  error.value = '';

  try {
    const response = await api.get('/api/merchant/dashboard');
    Object.assign(dashboard, response.data?.data ?? {});
  } catch {
    error.value = 'Failed to load dashboard.';
  } finally {
    loading.value = false;
  }
};

onMounted(loadDashboard);
</script>

<style scoped>
.panel-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
}

.stats-card,
.panel {
  padding: 14px;
}

.stats-card h3,
.panel h3 {
  margin: 0;
  font-size: 13px;
  color: #475569;
}

.stats-card strong {
  display: block;
  margin-top: 8px;
  font-size: 24px;
  color: #0f172a;
}

.panel h3 {
  margin-bottom: 6px;
}

.list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.list li {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid #f1f5f9;
}

.list li:last-child {
  border-bottom: 0;
}

.inline-state {
  padding: 10px;
  margin: 8px 0 0;
}

.error {
  color: #b91c1c;
}

.empty-row {
  color: #64748b;
}
</style>
