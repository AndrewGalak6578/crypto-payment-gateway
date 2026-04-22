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
      <article class="card-surface checklist-card">
        <div class="checklist-header">
          <h3>Setup checklist</h3>
          <p>Quick onboarding for first end-to-end payment flow.</p>
        </div>
        <ul class="checklist">
          <li v-for="item in checklist" :key="item.key" class="checklist-item">
            <div class="checklist-state" :class="stateClass(item.state)">{{ stateLabel(item.state) }}</div>
            <div class="checklist-body">
              <strong>{{ item.label }}</strong>
              <p>{{ item.description }}</p>
            </div>
            <RouterLink v-if="item.canOpen !== false" :to="item.to" class="checklist-action">
              {{ item.actionLabel }}
            </RouterLink>
            <span v-else class="checklist-action disabled">No access</span>
          </li>
        </ul>
      </article>

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
            <li v-for="(balance, index) in dashboard.balances" :key="`${displayAssetKey(balance)}:${index}`">
              <span>{{ displayAssetNetwork(balance) }}</span>
              <strong>{{ balance.amount }}</strong>
            </li>
          </ul>
          <p v-else class="empty-state inline-state">No balances.</p>
        </article>

        <article class="card-surface panel">
          <h3>Wallets</h3>
          <ul class="list" v-if="dashboard.wallets?.length">
            <li v-for="wallet in dashboard.wallets" :key="wallet.id">
              <span>{{ displayAssetNetwork(wallet) }}</span>
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
                <th>Asset</th>
                <th>Network</th>
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
                <td>{{ displayAssetLabel(invoice) }} <span class="muted mono">({{ displayAssetKey(invoice) }})</span></td>
                <td>{{ displayNetworkLabel(invoice) }} <span class="muted mono">({{ displayNetworkKey(invoice) }})</span></td>
                <td>{{ invoice.amount_coin }}</td>
                <td>{{ invoice.expected_usd }}</td>
                <td>{{ formatDate(invoice.created_at) }}</td>
              </tr>
              <tr v-if="!(dashboard.recent_invoices || []).length">
                <td colspan="7" class="empty-row">No recent invoices yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </template>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api from '../../api/axios';
import {
  displayAssetKey,
  displayAssetLabel,
  displayAssetNetwork,
  displayNetworkKey,
  displayNetworkLabel,
} from '../../utils/assetDisplay';
import { useAuthStore } from '../../stores/auth';

const authStore = useAuthStore();
const loading = ref(true);
const error = ref('');

const dashboard = reactive({
  stats: null,
  balances: [],
  recent_invoices: [],
  wallets: [],
});

const checklistState = reactive({
  hasWallet: false,
  hasApiKey: null,
  hasWebhookConfigured: null,
  hasTestInvoice: false,
  hasSuccessfulWebhookDelivery: null,
});

const checklist = computed(() => [
  {
    key: 'wallet',
    label: 'Wallet configured',
    description: 'Set at least one forwarding wallet for settlements.',
    state: checklistState.hasWallet ? 'done' : 'todo',
    actionLabel: 'Open wallets',
    to: '/merchant/wallets',
    canOpen: authStore.hasCapability('wallets.read'),
  },
  {
    key: 'api-key',
    label: 'API key created',
    description: 'Required for external API integrations and server-to-server invoice creation.',
    state: toChecklistState(checklistState.hasApiKey),
    actionLabel: 'Open API keys',
    to: '/merchant/api-keys',
    canOpen: authStore.hasCapability('api_keys.read'),
  },
  {
    key: 'webhook',
    label: 'Webhook configured',
    description: 'Set webhook URL and secret for status notifications.',
    state: toChecklistState(checklistState.hasWebhookConfigured),
    actionLabel: 'Open webhook settings',
    to: '/merchant/webhook-settings',
    canOpen: authStore.hasCapability('webhooks.read'),
  },
  {
    key: 'test-invoice',
    label: 'Test invoice created',
    description: 'Create a test invoice and validate hosted payment page.',
    state: checklistState.hasTestInvoice ? 'done' : 'todo',
    actionLabel: 'Create test invoice',
    to: '/merchant/test-invoice',
    canOpen: authStore.hasCapability('invoices.read'),
  },
  {
    key: 'webhook-delivery',
    label: 'Successful webhook delivery received',
    description: 'At least one webhook delivery completed successfully.',
    state: toChecklistState(checklistState.hasSuccessfulWebhookDelivery),
    actionLabel: 'Open webhook deliveries',
    to: '/merchant/webhook-deliveries',
    canOpen: authStore.hasCapability('webhooks.read'),
  },
]);

const toChecklistState = (value) => {
  if (value === null) {
    return 'unknown';
  }

  return value ? 'done' : 'todo';
};

const stateLabel = (state) => {
  if (state === 'done') {
    return 'Done';
  }

  if (state === 'unknown') {
    return 'Unknown';
  }

  return 'Todo';
};

const stateClass = (state) => ({
  'is-done': state === 'done',
  'is-unknown': state === 'unknown',
  'is-todo': state === 'todo',
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
    const payload = response.data?.data ?? {};
    Object.assign(dashboard, payload);

    checklistState.hasWallet = Array.isArray(payload.wallets) && payload.wallets.length > 0;
    checklistState.hasTestInvoice = Array.isArray(payload.recent_invoices) && payload.recent_invoices.length > 0;

    if (authStore.hasCapability('api_keys.read')) {
      const apiKeysResponse = await api.get('/api/merchant/api-keys');
      const keys = Array.isArray(apiKeysResponse.data?.data) ? apiKeysResponse.data.data : [];
      checklistState.hasApiKey = keys.some((item) => !item.revoked_at);
    } else {
      checklistState.hasApiKey = null;
    }

    if (authStore.hasCapability('webhooks.read')) {
      const [settingsResponse, deliveriesResponse] = await Promise.all([
        api.get('/api/merchant/webhook-settings'),
        api.get('/api/merchant/webhook-deliveries', { params: { per_page: 30 } }),
      ]);

      const settings = settingsResponse.data?.data ?? {};
      checklistState.hasWebhookConfigured = Boolean(settings.webhook_url) && Boolean(settings.has_webhook_secret);

      const deliveries = Array.isArray(deliveriesResponse.data?.data?.data)
        ? deliveriesResponse.data.data.data
        : Array.isArray(deliveriesResponse.data?.data)
          ? deliveriesResponse.data.data
          : [];
      checklistState.hasSuccessfulWebhookDelivery = deliveries.some((item) => item.status === 'delivered');
    } else {
      checklistState.hasWebhookConfigured = null;
      checklistState.hasSuccessfulWebhookDelivery = null;
    }
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
  align-items: flex-start;
}

.list li:last-child {
  border-bottom: 0;
}

.list li strong {
  min-width: 0;
  overflow-wrap: anywhere;
  text-align: right;
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

.checklist-card {
  padding: 14px;
}

.checklist-header h3 {
  margin: 0;
  font-size: 16px;
  color: #0f172a;
}

.checklist-header p {
  margin: 6px 0 0;
  font-size: 13px;
  color: #64748b;
}

.checklist {
  list-style: none;
  margin: 12px 0 0;
  padding: 0;
  display: grid;
  gap: 10px;
}

.checklist-item {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px;
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 10px;
  align-items: center;
}

.checklist-state {
  border-radius: 999px;
  padding: 3px 9px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}

.checklist-state.is-done {
  color: #166534;
  background: #dcfce7;
}

.checklist-state.is-todo {
  color: #9a3412;
  background: #ffedd5;
}

.checklist-state.is-unknown {
  color: #475569;
  background: #e2e8f0;
}

.checklist-body strong {
  display: block;
  font-size: 14px;
  color: #0f172a;
}

.checklist-body p {
  margin: 3px 0 0;
  font-size: 12px;
  color: #64748b;
}

.checklist-action {
  text-decoration: none;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 12px;
  color: #0f172a;
  background: #fff;
}

.checklist-action.disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

.muted {
  color: #64748b;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 12px;
}

@media (max-width: 768px) {
  .checklist-item {
    grid-template-columns: 1fr;
    align-items: flex-start;
  }

  .checklist-action {
    width: 100%;
    text-align: center;
  }
}
</style>
