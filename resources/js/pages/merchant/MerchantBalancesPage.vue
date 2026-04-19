<template>
    <section>
        <header class="page-header">
            <div>
                <h2 class="page-title">Balances</h2>
                <p class="page-subtitle">Internal merchant balances (asset/network view with legacy fallback).</p>
            </div>
            <div class="quick-actions">
                <RouterLink class="action-link" to="/merchant/wallets">Manage wallets</RouterLink>
                <RouterLink class="action-link" to="/merchant/invoices">View invoices</RouterLink>
            </div>
        </header>

        <p v-if="loading" class="muted">Loading balances...</p>

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="action-btn" @click="loadBalances">Retry</button>
        </div>

        <div v-else-if="!balances.length" class="state-card">
            <p class="muted">No balances yet.</p>
        </div>

        <div v-else class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Asset</th>
                    <th>Network</th>
                    <th>Amount</th>
                    <th>Source</th>
                    <th>Updated</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="balance in balances" :key="balance.id">
                    <td>{{ displayAssetLabel(balance) }} <span class="muted mono">({{ displayAssetKey(balance) }})</span></td>
                    <td>{{ displayNetworkLabel(balance) }} <span class="muted mono">({{ displayNetworkKey(balance) }})</span></td>
                    <td>{{ balance.amount }}</td>
                    <td>
                        <span class="source-badge" :class="{ fallback: !balance.network_key }">
                            {{ balance.network_key ? 'API network_key' : 'Legacy coin fallback' }}
                        </span>
                    </td>
                    <td>{{ formatDate(balance.updated_at) }}</td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { getMerchantBalances } from "../../api/merchant.js";
import {
    displayAssetKey,
    displayAssetLabel,
    displayNetworkKey,
    displayNetworkLabel
} from "../../utils/assetDisplay.js";

const loading = ref(true);
const error = ref('');
const balances = ref([]);

const formatDate = (dateString) => {
    if (!dateString) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(dateString));
};

const loadBalances = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await getMerchantBalances();
        balances.value = Array.isArray(response.data?.data) ? response.data.data : [];
    } catch {
        error.value = 'Failed to load balances.';
    } finally {
        loading.value = false;
    }
};

onMounted(loadBalances);
</script>

<style scoped>
.page-header {
    margin-bottom: 16px;
}

.page-title {
    margin: 0;
    color: #0f172a;
}

.page-subtitle {
    margin: 6px 0 0;
    color: #64748b;
}

.quick-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.action-link {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 10px;
    text-decoration: none;
    color: #0f172a;
    background: #fff;
    font-size: 13px;
}

.state-card,
.table-wrap {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    border-bottom: 1px solid #f1f5f9;
    padding: 9px;
    text-align: left;
    font-size: 13px;
    white-space: nowrap;
}

tbody tr:last-child td {
    border-bottom: 0;
}

.action-btn {
    margin-top: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 12px;
    background: #fff;
    cursor: pointer;
}

.muted {
    color: #64748b;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 12px;
}

.source-badge {
    display: inline-flex;
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 700;
    background: #dcfce7;
    color: #166534;
}

.source-badge.fallback {
    background: #ffedd5;
    color: #9a3412;
}

.error {
    color: #b91c1c;
}
</style>
