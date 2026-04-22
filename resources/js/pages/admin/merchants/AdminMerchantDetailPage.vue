<template>
    <section>
        <PageHeader :title="`Merchant #${merchantId}`" subtitle="Merchant profile, users and recent invoice context." >
            <template #actions>
                <button type="button" class="secondary-btn" :disabled="loading" @click="loadMerchant">Refresh</button>
                <button type="button" class="secondary-btn" @click="router.push({ name: 'admin.merchants' })">Back</button>
            </template>
        </PageHeader>

        <LoadingState v-if="loading" text="Loading merchant details..." />

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadMerchant">Retry</button>
        </div>

        <template v-else-if="merchant">
            <article class="panel">
                <div class="panel-row">
                    <div>
                        <h2 class="panel-title">{{ merchant.name }}</h2>
                        <p class="muted">ID: {{ merchant.id }}</p>
                    </div>
                    <div class="status-actions">
                        <StatusBadge
                            :text="merchant.status === 'disabled' ? 'suspended' : merchant.status"
                            :variant="merchant.status === 'active' ? 'success' : 'warning'"
                        />
                        <button
                            type="button"
                            class="secondary-btn"
                            :disabled="statusUpdating"
                            @click="handleStatusAction(merchant.status === 'active' ? 'disabled' : 'active')"
                        >
                            {{ statusUpdating ? 'Saving...' : merchant.status === 'active' ? 'Suspend' : 'Activate' }}
                        </button>
                    </div>
                </div>

                <div class="kv-grid">
                    <div><strong>fee_percent:</strong> {{ merchant.fee_percent ?? '—' }}</div>
                    <div><strong>webhook_url:</strong> <span class="mono break">{{ merchant.webhook_url || '—' }}</span></div>
                    <div><strong>has_webhook_secret:</strong> {{ merchant.has_webhook_secret ? 'yes' : 'no' }}</div>
                    <div><strong>created_at:</strong> {{ formatDate(merchant.created_at) }}</div>
                    <div><strong>updated_at:</strong> {{ formatDate(merchant.updated_at) }}</div>
                    <div><strong>wallets summary:</strong> {{ merchant.wallet_summary?.count ?? 0 }}</div>
                </div>
                <div class="ops-grid">
                    <div class="ops-tile">
                        <div class="ops-label">Recent invoices</div>
                        <strong>{{ merchant.recent_invoices?.length ?? 0 }}</strong>
                    </div>
                    <div class="ops-tile">
                        <div class="ops-label">Paid (recent)</div>
                        <strong>{{ paidRecentInvoices }}</strong>
                    </div>
                    <div class="ops-tile">
                        <div class="ops-label">Pending/Fixated (recent)</div>
                        <strong>{{ pendingRecentInvoices }}</strong>
                    </div>
                </div>
            </article>

            <article class="panel">
                <h3 class="panel-subtitle">Merchant users</h3>
                <TableCard v-if="merchant.merchant_users?.length">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last login</th>
                            <th>Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="user in merchant.merchant_users" :key="user.id">
                            <td>{{ user.id }}</td>
                            <td>{{ user.name }}</td>
                            <td>{{ user.email }}</td>
                            <td>{{ user.role_name || user.role_slug || user.role_id }}</td>
                            <td>
                                <StatusBadge :text="user.status" :variant="user.status === 'active' ? 'success' : 'warning'" />
                            </td>
                            <td>{{ formatDate(user.last_login_at) }}</td>
                            <td>{{ formatDate(user.created_at) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </TableCard>
                <EmptyState v-else title="No merchant users" />
            </article>

            <article class="panel">
                <h3 class="panel-subtitle">Recent invoices</h3>
                <TableCard v-if="merchant.recent_invoices?.length">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Public ID</th>
                            <th>Status</th>
                            <th>Asset (primary)</th>
                            <th>Network</th>
                            <th>Coin (legacy)</th>
                            <th>Amount</th>
                            <th>Expected USD</th>
                            <th>Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="invoice in merchant.recent_invoices" :key="invoice.id">
                            <td>
                                <RouterLink :to="{ name: 'admin.invoices.detail', params: { id: invoice.id } }">
                                    {{ invoice.id }}
                                </RouterLink>
                            </td>
                            <td>{{ invoice.public_id }}</td>
                            <td>
                                <StatusBadge :text="invoice.status" :variant="statusVariant(invoice.status)" />
                            </td>
                            <td>{{ invoiceAssetKey(invoice) }}</td>
                            <td>{{ invoiceNetworkKey(invoice) }}</td>
                            <td>{{ invoice.coin }}</td>
                            <td>{{ invoice.amount_coin }}</td>
                            <td>{{ invoice.expected_usd }}</td>
                            <td>{{ formatDate(invoice.created_at) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </TableCard>
                <EmptyState v-else title="No recent invoices" />
            </article>
        </template>

        <ConfirmModal
            :open="confirmOpen"
            :title="confirmTitle"
            :message="confirmMessage"
            :confirm-label="confirmLabel"
            :danger="confirmDanger"
            :loading="statusUpdating"
            @close="confirmOpen = false"
            @confirm="confirmStatusChange"
        />
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    extractApiErrorMessage,
    getAdminMerchant,
    updateAdminMerchantStatus,
} from '../../../api/admin';
import ConfirmModal from '../../../components/admin/ConfirmModal.vue';
import EmptyState from '../../../components/admin/EmptyState.vue';
import LoadingState from '../../../components/admin/LoadingState.vue';
import PageHeader from '../../../components/admin/PageHeader.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import TableCard from '../../../components/admin/TableCard.vue';

const route = useRoute();
const router = useRouter();

const merchantId = computed(() => route.params.id);
const loading = ref(true);
const error = ref('');
const merchant = ref(null);
const statusUpdating = ref(false);
const pendingStatus = ref('');

const confirmOpen = ref(false);
const confirmTitle = ref('');
const confirmMessage = ref('');
const confirmLabel = ref('Confirm');
const confirmDanger = ref(false);

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const invoiceAssetKey = (invoice) => String(invoice?.asset_key || invoice?.coin || '—').toLowerCase();
const invoiceNetworkKey = (invoice) => String(invoice?.network_key || '—').toLowerCase();
const paidRecentInvoices = computed(() => {
    return (merchant.value?.recent_invoices || []).filter((item) => item.status === 'paid').length;
});
const pendingRecentInvoices = computed(() => {
    return (merchant.value?.recent_invoices || []).filter((item) => ['pending', 'fixated'].includes(item.status)).length;
});

const statusVariant = (status) => {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'paid') {
        return 'success';
    }
    if (normalized === 'expired') {
        return 'danger';
    }
    if (normalized === 'fixated') {
        return 'info';
    }
    return 'warning';
};

const loadMerchant = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await getAdminMerchant(merchantId.value);
        merchant.value = response.data?.data || null;
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load merchant details.');
    } finally {
        loading.value = false;
    }
};

const handleStatusAction = (nextStatus) => {
    pendingStatus.value = nextStatus;
    confirmDanger.value = nextStatus === 'disabled';
    confirmTitle.value = nextStatus === 'disabled' ? 'Suspend merchant' : 'Activate merchant';
    confirmLabel.value = nextStatus === 'disabled' ? 'Suspend' : 'Activate';
    confirmMessage.value = `Change merchant status to "${nextStatus}"?`;
    confirmOpen.value = true;
};

const confirmStatusChange = async () => {
    if (!merchant.value || !pendingStatus.value) {
        return;
    }

    statusUpdating.value = true;
    error.value = '';

    try {
        await updateAdminMerchantStatus(merchant.value.id, { status: pendingStatus.value });
        merchant.value.status = pendingStatus.value;
        confirmOpen.value = false;
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to update merchant status.');
    } finally {
        statusUpdating.value = false;
    }
};

loadMerchant();
</script>

<style scoped>
.panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 14px;
}

.panel-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.panel-title {
    margin: 0;
    color: #0f172a;
}

.panel-subtitle {
    margin: 0 0 10px;
    color: #0f172a;
}

.kv-grid {
    display: grid;
    gap: 8px;
    color: #334155;
    font-size: 14px;
}

.kv-grid > div {
    min-width: 0;
    overflow-wrap: anywhere;
}

.ops-grid {
    margin-top: 12px;
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
}

.ops-tile {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px;
    background: #f8fafc;
}

.ops-label {
    color: #64748b;
    font-size: 12px;
}

.status-actions {
    display: inline-flex;
    gap: 8px;
    align-items: center;
}

.secondary-btn {
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 9px 11px;
    background: #fff;
    color: #0f172a;
    cursor: pointer;
    font: inherit;
}

.secondary-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
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

.state-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
}

.error {
    color: #b91c1c;
    margin: 0 0 10px;
}

.muted {
    color: #64748b;
    margin: 6px 0 0;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    word-break: break-all;
}

.break {
    overflow-wrap: anywhere;
}
</style>
