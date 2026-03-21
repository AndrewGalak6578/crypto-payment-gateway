<template>
  <section>
    <div class="header-row">
      <h2 class="page-title">Invoice Detail</h2>
      <RouterLink to="/merchant/invoices">Back to invoices</RouterLink>
    </div>

    <p v-if="loading" class="muted">Loading invoice...</p>
    <p v-else-if="error" class="error">{{ error }}</p>

    <article v-else class="panel">
      <dl class="detail-grid">
        <template v-for="field in fields" :key="field.key">
          <dt>{{ field.label }}</dt>
          <dd>
            <template v-if="field.key === 'hosted_url' && invoice.hosted_url">
              <a :href="invoice.hosted_url" target="_blank" rel="noopener noreferrer">{{ invoice.hosted_url }}</a>
            </template>
            <template v-else-if="field.key === 'forward_txids'">
              <span v-if="Array.isArray(invoice.forward_txids) && invoice.forward_txids.length">
                {{ invoice.forward_txids.join(', ') }}
              </span>
              <span v-else>—</span>
            </template>
            <template v-else>
              {{ printableValue(invoice[field.key]) }}
            </template>
          </dd>
        </template>
      </dl>

      <div class="metadata-block">
        <h3>metadata</h3>
        <pre>{{ metadataText }}</pre>
      </div>
    </article>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../../api/axios';

const route = useRoute();

const loading = ref(false);
const error = ref('');
const invoice = ref({});

const fields = [
  { key: 'public_id', label: 'public_id' },
  { key: 'external_id', label: 'external_id' },
  { key: 'status', label: 'status' },
  { key: 'coin', label: 'coin' },
  { key: 'pay_address', label: 'pay_address' },
  { key: 'amount_coin', label: 'amount_coin' },
  { key: 'expected_usd', label: 'expected_usd' },
  { key: 'rate_usd', label: 'rate_usd' },
  { key: 'received_conf_coin', label: 'received_conf_coin' },
  { key: 'received_all_coin', label: 'received_all_coin' },
  { key: 'paid_usd', label: 'paid_usd' },
  { key: 'fee_coin', label: 'fee_coin' },
  { key: 'merchant_payout_coin', label: 'merchant_payout_coin' },
  { key: 'fee_usd', label: 'fee_usd' },
  { key: 'merchant_payout_usd', label: 'merchant_payout_usd' },
  { key: 'forward_status', label: 'forward_status' },
  { key: 'forwarded_coin', label: 'forwarded_coin' },
  { key: 'forward_txids', label: 'forward_txids' },
  { key: 'expires_at', label: 'expires_at' },
  { key: 'fixated_at', label: 'fixated_at' },
  { key: 'paid_at', label: 'paid_at' },
  { key: 'created_at', label: 'created_at' },
  { key: 'hosted_url', label: 'hosted_url' },
];

const metadataText = computed(() => JSON.stringify(invoice.value?.metadata || {}, null, 2));

const printableValue = (value) => {
  if (value === null || value === undefined || value === '') {
    return '—';
  }

  return value;
};

const loadInvoice = async () => {
  loading.value = true;
  error.value = '';

  try {
    const response = await api.get(`/api/merchant/invoices/${route.params.id}`);
    invoice.value = response.data?.data || {};
  } catch {
    error.value = 'Failed to load invoice.';
  } finally {
    loading.value = false;
  }
};

watch(
  () => route.params.id,
  async () => {
    await loadInvoice();
  },
  { immediate: true },
);
</script>

<style scoped>
.page-title {
  margin: 0;
  color: #0f172a;
}

.header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}

.panel {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px;
}

.detail-grid {
  display: grid;
  grid-template-columns: minmax(130px, 220px) 1fr;
  gap: 10px;
}

dt {
  color: #64748b;
  font-size: 13px;
}

dd {
  margin: 0;
  color: #0f172a;
  font-size: 14px;
  word-break: break-word;
}

.metadata-block {
  margin-top: 18px;
}

.metadata-block pre {
  margin: 8px 0 0;
  background: #0f172a;
  color: #e2e8f0;
  border-radius: 8px;
  padding: 12px;
  overflow: auto;
  font-size: 12px;
}

.muted {
  color: #64748b;
}

.error {
  color: #b91c1c;
}

@media (max-width: 768px) {
  .detail-grid {
    grid-template-columns: 1fr;
  }
}
</style>
