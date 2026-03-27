<template>
  <section class="portal-shell">
    <header class="page-header">
      <div>
        <h2 class="page-title">Invoices</h2>
        <p class="page-subtitle">Track status, filters and hosted invoice links.</p>
      </div>
    </header>

    <form class="filters-row" @submit.prevent="applyFilters">
      <input v-model="filters.search" placeholder="Search public_id / external_id" />
      <input v-model="filters.status" placeholder="Status" />
      <input v-model="filters.coin" placeholder="Coin" />
      <button type="submit">Apply</button>
      <button type="button" @click="resetFilters">Reset</button>
    </form>

    <p v-if="loading" class="loading-state">Loading invoices...</p>
    <p v-else-if="error" class="empty-state error">{{ error }}</p>

    <div v-else class="table-shell">
      <div class="table-wrap">
        <table class="table-base">
          <thead>
            <tr>
              <th>public_id</th>
              <th>external_id</th>
              <th>status</th>
              <th>coin</th>
              <th>amount_coin</th>
              <th>expected_usd</th>
              <th>received_conf_coin</th>
              <th>forward_status</th>
              <th>created_at</th>
              <th>hosted_url</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="invoice in invoices" :key="invoice.id" class="click-row" @click="goToDetail(invoice.id)">
              <td>{{ invoice.public_id }}</td>
              <td>{{ invoice.external_id || '—' }}</td>
              <td><span class="status-badge status-badge-muted">{{ invoice.status }}</span></td>
              <td>{{ invoice.coin }}</td>
              <td>{{ invoice.amount_coin }}</td>
              <td>{{ invoice.expected_usd }}</td>
              <td>{{ invoice.received_conf_coin }}</td>
              <td>{{ invoice.forward_status || '—' }}</td>
              <td>{{ formatDate(invoice.created_at) }}</td>
              <td>
                <a
                  v-if="invoice.hosted_url"
                  :href="invoice.hosted_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  @click.stop
                >
                  open
                </a>
                <span v-else>—</span>
              </td>
            </tr>
            <tr v-if="!invoices.length">
              <td colspan="10" class="empty-row">No invoices found.</td>
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

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const error = ref('');
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
});

const formatDate = (dateString) => (dateString ? new Date(dateString).toLocaleString() : '—');

const syncFiltersFromQuery = () => {
  filters.search = typeof route.query.search === 'string' ? route.query.search : '';
  filters.status = typeof route.query.status === 'string' ? route.query.status : '';
  filters.coin = typeof route.query.coin === 'string' ? route.query.coin : '';
};

const loadInvoices = async () => {
  loading.value = true;
  error.value = '';

  try {
    const params = {
      search: route.query.search || undefined,
      status: route.query.status || undefined,
      coin: route.query.coin || undefined,
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
      page: 1,
    },
  });
};

const resetFilters = async () => {
  filters.search = '';
  filters.status = '';
  filters.coin = '';
  await router.push({ name: 'merchant.invoices' });
};

const changePage = async (page) => {
  await router.push({
    name: 'merchant.invoices',
    query: {
      search: filters.search || undefined,
      status: filters.status || undefined,
      coin: filters.coin || undefined,
      page,
    },
  });
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

.error {
  color: #b91c1c;
}
</style>
