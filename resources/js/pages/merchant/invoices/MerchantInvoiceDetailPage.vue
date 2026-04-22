<template>
  <section>
    <div class="header-row">
      <div>
        <h2 class="page-title">Invoice Detail</h2>
        <p class="page-subtitle">Primary asset/network view with payment and forwarding state.</p>
      </div>
      <RouterLink to="/merchant/invoices" class="secondary-btn">Back to invoices</RouterLink>
    </div>

    <p v-if="loading" class="muted">Loading invoice...</p>
    <p v-else-if="error" class="error state-message">{{ error }}</p>
    <p v-else-if="notice" class="notice state-message">{{ notice }}</p>

    <template v-if="!loading && !error">
      <article class="panel action-panel">
        <button type="button" class="secondary-btn" :disabled="refreshing" @click="reloadInvoice">
          {{ refreshing ? 'Refreshing...' : 'Refresh data' }}
        </button>
        <button type="button" class="secondary-btn" :disabled="!invoice.pay_address" @click="copyValue(invoice.pay_address, 'Pay address copied.')">
          Copy pay address
        </button>
        <button type="button" class="secondary-btn" :disabled="!invoice.hosted_url" @click="copyValue(invoice.hosted_url, 'Hosted link copied.')">
          Copy hosted link
        </button>
        <a
          v-if="invoice.hosted_url"
          class="secondary-btn"
          :href="invoice.hosted_url"
          target="_blank"
          rel="noopener noreferrer"
        >
          Open hosted page
        </a>
      </article>

      <article class="panel">
        <h3 class="section-title">Overview</h3>
        <dl class="detail-grid">
          <dt>Public ID</dt>
          <dd>{{ printableValue(invoice.public_id) }}</dd>
          <dt>Status</dt>
          <dd><span class="status-badge status-badge-muted">{{ printableValue(invoice.status) }}</span></dd>
          <dt>Asset (primary)</dt>
          <dd>{{ displayAssetLabel(invoice) }} <span class="muted mono">({{ displayAssetKey(invoice) }})</span></dd>
          <dt>Network (primary)</dt>
          <dd>{{ displayNetworkLabel(invoice) }} <span class="muted mono">({{ displayNetworkKey(invoice) }})</span></dd>
          <dt>Legacy coin</dt>
          <dd>{{ printableValue(invoice.coin) }}</dd>
          <dt>Pay address</dt>
          <dd class="break">{{ printableValue(invoice.pay_address) }}</dd>
          <dt>Amount</dt>
          <dd>{{ printableValue(invoice.amount_coin) }}</dd>
          <dt>Expected USD</dt>
          <dd>{{ printableValue(invoice.expected_usd) }}</dd>
          <dt>Received confirmed</dt>
          <dd>{{ printableValue(invoice.received_conf_coin) }}</dd>
          <dt>Received all</dt>
          <dd>{{ printableValue(invoice.received_all_coin) }}</dd>
          <dt>Forward status</dt>
          <dd>{{ printableValue(invoice.forward_status) }}</dd>
          <dt>Hosted URL</dt>
          <dd class="break">{{ printableValue(invoice.hosted_url) }}</dd>
        </dl>
      </article>

      <article class="panel">
        <h3 class="section-title">Timeline</h3>
        <ul class="timeline">
          <li v-for="item in timelineItems" :key="item.key" :class="timelineClass(item)">
            <div class="timeline-dot" />
            <div class="timeline-body">
              <strong>{{ item.label }}</strong>
              <p>{{ item.timeLabel }}</p>
            </div>
          </li>
        </ul>
      </article>

      <article class="panel">
        <h3 class="section-title">Forwarding transactions</h3>
        <div v-if="forwardTxids.length === 0" class="muted">No forwarding tx ids yet.</div>
        <ul v-else class="tx-list">
          <li v-for="txid in forwardTxids" :key="txid">
            <code class="txid">{{ txid }}</code>
            <button type="button" class="link-btn" @click="copyValue(txid, 'Txid copied.')">Copy</button>
          </li>
        </ul>
      </article>

      <article class="panel">
        <h3 class="section-title">Metadata</h3>
        <div v-if="metadataEntries.length === 0" class="muted">Metadata is empty.</div>
        <dl v-else class="metadata-grid">
          <template v-for="entry in metadataEntries" :key="entry.key">
            <dt>{{ entry.key }}</dt>
            <dd><pre>{{ entry.value }}</pre></dd>
          </template>
        </dl>
      </article>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../../api/axios';
import { refreshMerchantInvoice } from '../../../api/merchant.js';
import {
  displayAssetKey,
  displayAssetLabel,
  displayNetworkKey,
  displayNetworkLabel,
} from '../../../utils/assetDisplay';
import { copyTextToClipboard } from '../../../utils/clipboard';

const route = useRoute();

const loading = ref(false);
const refreshing = ref(false);
const error = ref('');
const notice = ref('');
const invoice = ref({});

const printableValue = (value) => {
  if (value === null || value === undefined || value === '') {
    return '—';
  }

  return value;
};

const formatDate = (dateString) => {
  if (!dateString) {
    return null;
  }

  return new Date(dateString).toLocaleString();
};

const timelineItems = computed(() => {
  const inv = invoice.value || {};
  return [
    {
      key: 'created',
      label: 'Created',
      timeLabel: formatDate(inv.created_at) || '—',
      done: Boolean(inv.created_at),
      active: true,
    },
    {
      key: 'pending',
      label: 'Pending',
      timeLabel: inv.status ? `Status observed: ${inv.status}` : '—',
      done: ['pending', 'fixated', 'paid'].includes(inv.status),
      active: inv.status === 'pending',
    },
    {
      key: 'fixated',
      label: 'Fixated',
      timeLabel: formatDate(inv.fixated_at) || 'Not reached',
      done: Boolean(inv.fixated_at),
      active: inv.status === 'fixated',
    },
    {
      key: 'paid',
      label: 'Paid',
      timeLabel: formatDate(inv.paid_at) || 'Not reached',
      done: Boolean(inv.paid_at) || inv.status === 'paid',
      active: inv.status === 'paid',
    },
    {
      key: 'forwarded',
      label: 'Forwarded',
      timeLabel: formatDate(inv.last_forwarded_at) || `Status: ${printableValue(inv.forward_status)}`,
      done: ['done', 'partial'].includes(inv.forward_status),
      active: inv.forward_status === 'processing',
    },
  ];
});

const forwardTxids = computed(() => {
  if (!Array.isArray(invoice.value?.forward_txids)) {
    return [];
  }

  return invoice.value.forward_txids.filter((item) => typeof item === 'string' && item.trim() !== '');
});

const metadataEntries = computed(() => {
  const metadata = invoice.value?.metadata;
  if (!metadata || typeof metadata !== 'object' || Array.isArray(metadata)) {
    return [];
  }

  return Object.entries(metadata).map(([key, value]) => ({
    key,
    value: JSON.stringify(value, null, 2),
  }));
});

const timelineClass = (item) => ({
  'is-done': item.done,
  'is-active': item.active,
});

const copyValue = async (value, successMessage) => {
  const result = await copyTextToClipboard(value);
  if (result.ok) {
    error.value = '';
    notice.value = successMessage;
    return;
  }

  notice.value = '';
  error.value = result.message || 'Failed to copy to clipboard.';
};

const loadInvoice = async () => {
  loading.value = true;
  error.value = '';
  notice.value = '';

  try {
    const response = await api.get(`/api/merchant/invoices/${route.params.id}`);
    invoice.value = response.data?.data || {};
  } catch {
    error.value = 'Failed to load invoice.';
  } finally {
    loading.value = false;
  }
};

const reloadInvoice = async () => {
  error.value = '';
  notice.value = '';
  refreshing.value = true;

  try {
    const response = await refreshMerchantInvoice(route.params.id);
    invoice.value = response.data?.data || {};
    notice.value = 'Invoice data refreshed.';
  } catch {
    error.value = 'Failed to refresh invoice.';
  } finally {
    refreshing.value = false;
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
.header-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}

.page-title {
  margin: 0;
}

.page-subtitle {
  margin: 6px 0 0;
  color: #64748b;
}

.panel {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px;
}

.action-panel {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.section-title {
  margin: 0 0 10px;
  font-size: 16px;
  color: #0f172a;
}

.detail-grid {
  display: grid;
  grid-template-columns: minmax(160px, 240px) 1fr;
  gap: 10px;
}

.detail-grid dd {
  min-width: 0;
  overflow-wrap: anywhere;
}

dt {
  color: #64748b;
  font-size: 13px;
}

dd {
  margin: 0;
  color: #0f172a;
  font-size: 14px;
}

.timeline {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
}

.timeline li {
  display: flex;
  gap: 10px;
  align-items: center;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 10px;
}

.timeline-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #cbd5e1;
}

.timeline li.is-done .timeline-dot {
  background: #16a34a;
}

.timeline li.is-active {
  border-color: #93c5fd;
}

.timeline-body strong {
  display: block;
}

.timeline-body p {
  margin: 3px 0 0;
  color: #64748b;
  font-size: 12px;
}

.tx-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 8px;
}

.tx-list li {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}

.txid {
  flex: 1;
  overflow-wrap: anywhere;
}

.metadata-grid {
  display: grid;
  grid-template-columns: minmax(160px, 220px) 1fr;
  gap: 8px;
  margin: 0;
}

.metadata-grid pre {
  margin: 0;
  background: #0f172a;
  color: #e2e8f0;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 12px;
  overflow: auto;
  overflow-wrap: anywhere;
}

.secondary-btn,
.link-btn {
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
  color: #0f172a;
  padding: 8px 10px;
  text-decoration: none;
  font: inherit;
  cursor: pointer;
}

.state-message {
  margin: 0 0 12px;
}

.break {
  word-break: break-all;
}

.muted {
  color: #64748b;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 12px;
}

.error {
  color: #b91c1c;
}

.notice {
  color: #0369a1;
}

@media (max-width: 768px) {
  .detail-grid,
  .metadata-grid {
    grid-template-columns: 1fr;
  }
}
</style>
