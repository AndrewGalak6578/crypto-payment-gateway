<template>
  <section>
    <h2 class="page-title">Dashboard</h2>

    <p v-if="loading" class="muted">Loading dashboard...</p>
    <p v-else-if="error" class="error">{{ error }}</p>

    <template v-else>
      <div class="stats-grid">
        <article class="card">
          <h3>Paid Invoices</h3>
          <strong>{{ dashboard.stats?.paid_invoices_count ?? 0 }}</strong>
        </article>
        <article class="card">
          <h3>Pending / Fixated</h3>
          <strong>{{ dashboard.stats?.pending_invoices_count ?? 0 }}</strong>
        </article>
        <article class="card">
          <h3>Failed Webhooks</h3>
          <strong>{{ dashboard.stats?.failed_webhook_deliveries_count ?? 0 }}</strong>
        </article>
      </div>

      <div class="panel-grid">
        <article class="panel">
          <h3>Balances</h3>
          <ul class="list" v-if="dashboard.balances?.length">
            <li v-for="balance in dashboard.balances" :key="balance.coin">
              <span>{{ balance.coin }}</span>
              <strong>{{ balance.amount }}</strong>
            </li>
          </ul>
          <p v-else class="muted">No balances.</p>
        </article>

        <article class="panel">
          <h3>Wallets</h3>
          <ul class="list" v-if="dashboard.wallets?.length">
            <li v-for="wallet in dashboard.wallets" :key="wallet.id">
              <span>{{ wallet.coin }}</span>
              <strong>{{ wallet.wallet }}</strong>
            </li>
          </ul>
          <p v-else class="muted">No wallets.</p>
        </article>
      </div>

      <article class="panel">
        <h3>Recent Invoices</h3>
        <div class="table-wrap">
          <table>
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
                <td>{{ invoice.status }}</td>
                <td>{{ invoice.coin }}</td>
                <td>{{ invoice.amount_coin }}</td>
                <td>{{ invoice.expected_usd }}</td>
                <td>{{ formatDate(invoice.created_at) }}</td>
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
.page-title {
  margin: 0 0 16px;
  color: #0f172a;
}

.stats-grid,
.panel-grid {
  display: grid;
  gap: 12px;
  margin-bottom: 12px;
}

.stats-grid {
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.panel-grid {
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.card,
.panel {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px;
}

.card h3,
.panel h3 {
  margin: 0;
  font-size: 14px;
  color: #475569;
}

.card strong {
  display: block;
  margin-top: 8px;
  font-size: 24px;
  color: #0f172a;
}

.list {
  margin: 10px 0 0;
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

.table-wrap {
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
}

th,
td {
  text-align: left;
  font-size: 13px;
  padding: 9px;
  border-bottom: 1px solid #f1f5f9;
}

.muted {
  color: #64748b;
}

.error {
  color: #b91c1c;
}
</style>
