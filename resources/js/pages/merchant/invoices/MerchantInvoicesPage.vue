<template>
  <section class="portal-shell">
    <header class="page-header">
      <div>
        <h2 class="page-title">Invoices</h2>
        <p class="page-subtitle">Track status, asset/network and hosted invoice links.</p>
      </div>
      <RouterLink class="action-link" to="/merchant/test-invoice">Create test invoice</RouterLink>
    </header>

    <form class="filters-row" @submit.prevent="applyFilters">
      <input v-model="filters.search" placeholder="Search public_id / external_id" />
      <select v-model="filters.status">
        <option value="">Any status</option>
        <option v-for="option in statusOptions" :key="option" :value="option">{{ option }}</option>
      </select>
      <input v-model="filters.coin" placeholder="Asset key / legacy coin" />
      <input v-model="filters.date_from" type="date" />
      <input v-model="filters.date_to" type="date" />
      <button type="submit">Apply</button>
      <button type="button" @click="resetFilters">Reset</button>
    </form>

    <p v-if="loading" class="loading-state">Loading invoices...</p>
    <p v-else-if="error" class="empty-state error">{{ error }}</p>
    <p v-if="!loading && !error && notice" class="empty-state notice">{{ notice }}</p>

    <div v-else class="table-shell">
      <div class="table-wrap">
        <table class="table-base">
          <thead>
            <tr>
              <th>public_id</th>
              <th>external_id</th>
              <th>status</th>
              <th>asset</th>
              <th>network</th>
              <th>coin (secondary)</th>
              <th>amount_coin</th>
              <th>expected_usd</th>
              <th>received_conf_coin</th>
              <th>forward_status</th>
              <th>created_at</th>
              <th>actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="invoice in invoices" :key="invoice.id" class="click-row" @click="goToDetail(invoice.id)">
              <td>{{ invoice.public_id }}</td>
              <td>{{ invoice.external_id || '—' }}</td>
              <td>
                <span class="status-badge" :class="statusClass(invoice.status)">{{ invoice.status }}</span>
              </td>
              <td>{{ displayAssetLabel(invoice) }} <span class="muted mono">({{ displayAssetKey(invoice) }})</span></td>
              <td>{{ displayNetworkLabel(invoice) }} <span class="muted mono">({{ displayNetworkKey(invoice) }})</span></td>
              <td>{{ invoice.coin || '—' }}</td>
              <td>{{ invoice.amount_coin }}</td>
              <td>{{ invoice.expected_usd }}</td>
              <td>{{ invoice.received_conf_coin }}</td>
              <td><span class="status-badge status-badge-muted">{{ invoice.forward_status || '—' }}</span></td>
              <td>{{ formatDate(invoice.created_at) }}</td>
              <td>
                <div class="action-row">
                  <a
                    v-if="invoice.hosted_url"
                    :href="invoice.hosted_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    @click.stop
                  >
                    hosted
                  </a>
                  <button
                    v-if="invoice.hosted_url"
                    type="button"
                    class="copy-btn"
                    @click.stop="copyHostedLink(invoice.hosted_url)"
                  >
                    copy
                  </button>
                  <RouterLink :to="`/merchant/invoices/${invoice.id}`" @click.stop>detail</RouterLink>
                </div>
              </td>
            </tr>
            <tr v-if="!invoices.length">
              <td colspan="12" class="empty-row">No invoices found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="pagination">
      <button type="button" :disabled="meta.current_page <= 1 || loading" @click="changePage(meta.current_page - 1)">
        Prev
      </button>
      <span>Page {{ meta.current_page }} / {{ meta.last_page }}</span>
      <button type="button" :disabled="meta.current_page >= meta.last_page || loading" @click="changePage(meta.current_page + 1)">
        Next
      </button>
    </div>
  </section>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../../../api/axios';
import {
  displayAssetKey,
  displayAssetLabel,
  displayNetworkKey,
  displayNetworkLabel,
} from '../../../utils/assetDisplay';
import { copyTextToClipboard } from '../../../utils/clipboard';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const error = ref('');
const notice = ref('');
const invoices = ref([]);
const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
});

const filters = reactive({
  search: '',
  status: '',
  coin: '',
  date_from: '',
  date_to: '',
});

const statusOptions = ['pending', 'fixated', 'paid', 'expired'];

const formatDate = (dateString) => (dateString ? new Date(dateString).toLocaleString() : '—');

const syncFiltersFromQuery = () => {
  filters.search = typeof route.query.search === 'string' ? route.query.search : '';
  filters.status = typeof route.query.status === 'string' ? route.query.status : '';
  filters.coin = typeof route.query.coin === 'string' ? route.query.coin : '';
  filters.date_from = typeof route.query.date_from === 'string' ? route.query.date_from : '';
  filters.date_to = typeof route.query.date_to === 'string' ? route.query.date_to : '';
};

const loadInvoices = async () => {
  loading.value = true;
  error.value = '';
  notice.value = '';

  try {
    const params = {
      search: route.query.search || undefined,
      status: route.query.status || undefined,
      coin: route.query.coin || undefined,
      date_from: route.query.date_from || undefined,
      date_to: route.query.date_to || undefined,
      page: route.query.page || 1,
    };

    const response = await api.get('/api/merchant/invoices', { params });
    invoices.value = response.data?.data?.data || [];

    Object.assign(meta, {
      current_page: response.data?.meta?.current_page || response.data?.data?.current_page || 1,
      last_page: response.data?.meta?.last_page || response.data?.data?.last_page || 1,
      per_page: response.data?.meta?.per_page || response.data?.data?.per_page || 15,
      total: response.data?.meta?.total || response.data?.data?.total || 0,
    });
  } catch {
    error.value = 'Failed to load invoices.';
  } finally {
    loading.value = false;
  }
};

const applyFilters = async () => {
  await router.push({
    name: 'merchant.invoices',
    query: {
      search: filters.search || undefined,
      status: filters.status || undefined,
      coin: filters.coin || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      page: 1,
    },
  });
};

const resetFilters = async () => {
  filters.search = '';
  filters.status = '';
  filters.coin = '';
  filters.date_from = '';
  filters.date_to = '';
  await router.push({ name: 'merchant.invoices' });
};

const changePage = async (page) => {
  await router.push({
    name: 'merchant.invoices',
    query: {
      search: filters.search || undefined,
      status: filters.status || undefined,
      coin: filters.coin || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      page,
    },
  });
};

const statusClass = (status) => {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'paid') {
    return 'status-badge-success';
  }
  if (normalized === 'expired') {
    return 'status-badge-danger';
  }
  if (normalized === 'fixated') {
    return 'status-badge-info';
  }
  return 'status-badge-muted';
};

const copyHostedLink = async (value) => {
  const result = await copyTextToClipboard(value);
  if (result.ok) {
    error.value = '';
    notice.value = 'Hosted link copied.';
    return;
  }

  notice.value = '';
  error.value = result.message || 'Failed to copy to clipboard.';
};

const goToDetail = (id) => {
  router.push({ name: 'merchant.invoices.detail', params: { id } });
};

watch(
  () => route.query,
  async () => {
    syncFiltersFromQuery();
    await loadInvoices();
  },
  { immediate: true },
);
</script>

<style scoped>
.filters-row input,
.filters-row select,
.filters-row button,
.pagination button {
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 8px 10px;
  background: #fff;
}

.filters-row button,
.pagination button {
  cursor: pointer;
}

.click-row {
  cursor: pointer;
}

.click-row:hover {
  background: #f8fafc;
}

.pagination {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.empty-row {
  color: #64748b;
}

.action-link {
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 8px 10px;
  text-decoration: none;
  color: #0f172a;
  background: #fff;
}

.action-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.copy-btn {
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  padding: 4px 7px;
  cursor: pointer;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 12px;
}

.muted {
  color: #64748b;
}

.status-badge-success {
  background: #dcfce7;
  color: #166534;
}

.status-badge-danger {
  background: #fee2e2;
  color: #991b1b;
}

.status-badge-info {
  background: #dbeafe;
  color: #1d4ed8;
}

.error {
  color: #b91c1c;
}

.notice {
  color: #0369a1;
}
</style>
