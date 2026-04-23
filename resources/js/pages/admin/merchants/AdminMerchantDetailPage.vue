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
                <div class="quick-actions">
                    <a href="#merchant-wallets" class="secondary-btn quick-link">Go to wallets</a>
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

            <article id="merchant-wallets" class="panel">
                <div class="panel-row">
                    <div>
                        <h3 class="panel-subtitle">Wallets</h3>
                        <p class="muted">
                            Manage merchant forwarding wallets. Asset/network keys are primary; legacy coin is secondary.
                        </p>
                    </div>
                    <div class="wallet-toolbar">
                        <button type="button" class="secondary-btn" :disabled="loading || statusUpdating || walletsLoading" @click="loadWallets">
                            Refresh wallets
                        </button>
                        <button
                            type="button"
                            class="secondary-btn"
                            :disabled="walletMutationActive || walletsLoading || editingWalletId !== null"
                            @click="toggleCreateWalletForm"
                        >
                            {{ showCreateWalletForm ? 'Cancel create' : 'Create wallet' }}
                        </button>
                    </div>
                </div>

                <p v-if="walletNotice" class="wallet-notice" :class="{ 'wallet-notice-error': walletNoticeType === 'error' }">
                    {{ walletNotice }}
                </p>

                <div v-if="walletApiGap" class="state-card">
                    <p class="muted wallet-gap">
                        Wallet list is not exposed by current admin API.
                        <span v-if="merchant.wallet_summary?.count !== undefined">
                            Summary count: {{ merchant.wallet_summary?.count ?? 0 }}.
                        </span>
                        Create/Edit/Delete actions stay unavailable until admin wallet endpoints are added.
                    </p>
                </div>

                <form v-else-if="showCreateWalletForm" class="wallet-form-card" @submit.prevent="submitCreateWallet">
                    <div class="wallet-form-grid">
                        <label>
                            <span class="wallet-field-label">Asset / coin</span>
                            <select v-model="createWalletForm.coin" class="wallet-input" :disabled="creatingWallet || walletMutationActive">
                                <option value="" disabled>Select asset</option>
                                <option v-for="item in walletAssetOptions" :key="item.assetKey" :value="item.assetKey">
                                    {{ item.label }}
                                </option>
                            </select>
                        </label>
                        <label>
                            <span class="wallet-field-label">Wallet</span>
                            <input
                                v-model.trim="createWalletForm.wallet"
                                class="wallet-input mono"
                                type="text"
                                maxlength="255"
                                placeholder="Destination wallet/address"
                                :disabled="creatingWallet || walletMutationActive"
                            >
                        </label>
                        <label>
                            <span class="wallet-field-label">Fee rate (optional)</span>
                            <input
                                v-model.trim="createWalletForm.fee_rate"
                                class="wallet-input"
                                type="number"
                                min="0"
                                step="any"
                                placeholder="0"
                                :disabled="creatingWallet || walletMutationActive"
                            >
                        </label>
                    </div>
                    <p v-if="selectedCreateAssetHint" class="muted wallet-form-hint">{{ selectedCreateAssetHint }}</p>
                    <div class="wallet-form-actions">
                        <button type="submit" class="secondary-btn" :disabled="creatingWallet || walletMutationActive">
                            {{ creatingWallet ? 'Creating...' : 'Save wallet' }}
                        </button>
                    </div>
                </form>

                <div v-if="walletLoadError && !walletApiGap" class="state-card">
                    <p class="error">{{ walletLoadError }}</p>
                </div>

                <TableCard v-if="!walletApiGap && merchantWallets.length">
                    <table class="wallets-table">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Asset</th>
                            <th>Network</th>
                            <th>Wallet</th>
                            <th>Fee rate</th>
                            <th>Created</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="wallet in merchantWallets" :key="wallet.id">
                            <td>{{ wallet.id ?? '—' }}</td>
                            <td>
                                {{ displayAssetLabel(wallet) }}
                                <span class="muted mono">({{ displayAssetKey(wallet) }})</span>
                                <span v-if="wallet.coin" class="muted mono"> · coin: {{ String(wallet.coin).toUpperCase() }}</span>
                            </td>
                            <td>
                                {{ displayNetworkLabel(wallet) }}
                                <span class="muted mono">({{ displayNetworkKey(wallet) }})</span>
                            </td>
                            <td class="wallet-cell">
                                <template v-if="editingWalletId === wallet.id">
                                    <input
                                        v-model.trim="editWalletForm.wallet"
                                        class="wallet-input mono"
                                        type="text"
                                        maxlength="255"
                                        :disabled="savingEditWalletId === wallet.id || walletMutationActive"
                                    >
                                </template>
                                <span v-else class="mono">{{ wallet.wallet || '—' }}</span>
                            </td>
                            <td>
                                <template v-if="editingWalletId === wallet.id">
                                    <input
                                        v-model.trim="editWalletForm.fee_rate"
                                        class="wallet-input"
                                        type="number"
                                        min="0"
                                        step="any"
                                        :disabled="savingEditWalletId === wallet.id || walletMutationActive"
                                    >
                                </template>
                                <span v-else>{{ wallet.fee_rate ?? '—' }}</span>
                            </td>
                            <td>{{ formatDate(wallet.created_at) }}</td>
                            <td>{{ formatDate(wallet.updated_at) }}</td>
                            <td>
                                <div class="wallet-actions">
                                    <button
                                        type="button"
                                        class="secondary-btn compact-btn"
                                        :disabled="!wallet.wallet || copyingWalletId === wallet.id"
                                        @click="copyWalletAddress(wallet)"
                                    >
                                        {{ copyingWalletId === wallet.id ? 'Copying...' : 'Copy' }}
                                    </button>
                                    <template v-if="editingWalletId === wallet.id">
                                        <button
                                            type="button"
                                            class="secondary-btn compact-btn"
                                            :disabled="savingEditWalletId === wallet.id || walletMutationActive"
                                            @click="submitEditWallet(wallet)"
                                        >
                                            {{ savingEditWalletId === wallet.id ? 'Saving...' : 'Save' }}
                                        </button>
                                        <button
                                            type="button"
                                            class="secondary-btn compact-btn"
                                            :disabled="savingEditWalletId === wallet.id || walletMutationActive"
                                            @click="cancelEditWallet"
                                        >
                                            Cancel
                                        </button>
                                    </template>
                                    <template v-else>
                                        <button
                                            type="button"
                                            class="secondary-btn compact-btn"
                                            :disabled="walletMutationActive"
                                            @click="startEditWallet(wallet)"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            class="secondary-btn compact-btn"
                                            :disabled="walletMutationActive"
                                            @click="promptDeleteWallet(wallet)"
                                        >
                                            Delete
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </TableCard>
                <EmptyState
                    v-else-if="!walletApiGap"
                    title="No wallets found"
                    description="This merchant has no configured wallets in the admin payload."
                />
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
            :loading="confirmSubmitting"
            @close="confirmOpen = false"
            @confirm="confirmAction"
        />
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    displayAssetKey,
    displayAssetLabel,
    displayNetworkKey,
    displayNetworkLabel,
} from '../../../utils/assetDisplay';
import { assetOptionLabel, MERCHANT_ASSET_CATALOG } from '../../../utils/merchantAssetCatalog';
import { copyTextToClipboard } from '../../../utils/clipboard';
import {
    createAdminMerchantWallet,
    deleteAdminMerchantWallet,
    extractApiErrorMessage,
    getAdminMerchant,
    getAdminMerchantWallets,
    updateAdminMerchantWallet,
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
const walletNotice = ref('');
const walletNoticeType = ref('success');
const copyingWalletId = ref(null);
const walletsLoading = ref(false);
const walletLoadError = ref('');
const wallets = ref([]);
const creatingWallet = ref(false);
const showCreateWalletForm = ref(false);
const createWalletForm = ref({
    coin: '',
    wallet: '',
    fee_rate: '',
});
const editingWalletId = ref(null);
const savingEditWalletId = ref(null);
const editWalletForm = ref({
    wallet: '',
    fee_rate: '',
});
const deletingWalletId = ref(null);
const walletToDelete = ref(null);

const confirmOpen = ref(false);
const confirmTitle = ref('');
const confirmMessage = ref('');
const confirmLabel = ref('Confirm');
const confirmDanger = ref(false);
const confirmActionType = ref('');

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const invoiceAssetKey = (invoice) => String(invoice?.asset_key || invoice?.coin || '—').toLowerCase();
const invoiceNetworkKey = (invoice) => String(invoice?.network_key || '—').toLowerCase();
const paidRecentInvoices = computed(() => {
    return (merchant.value?.recent_invoices || []).filter((item) => item.status === 'paid').length;
});
const pendingRecentInvoices = computed(() => {
    return (merchant.value?.recent_invoices || []).filter((item) => ['pending', 'fixated'].includes(item.status)).length;
});
const merchantWallets = computed(() => [...wallets.value].sort((left, right) => Number(left?.id || 0) - Number(right?.id || 0)));
const walletApiGap = computed(() => false);
const walletAssetOptions = computed(() => {
    return MERCHANT_ASSET_CATALOG.map((item) => ({
        assetKey: item.assetKey,
        label: assetOptionLabel(item.assetKey),
        networkLabel: item.networkLabel,
    }));
});
const selectedCreateAsset = computed(() => {
    return walletAssetOptions.value.find((item) => item.assetKey === String(createWalletForm.value.coin || '').toLowerCase()) || null;
});
const selectedCreateAssetHint = computed(() => {
    if (!selectedCreateAsset.value) {
        return '';
    }

    return `Network: ${selectedCreateAsset.value.networkLabel}.`;
});
const walletMutationActive = computed(() => {
    return creatingWallet.value || savingEditWalletId.value !== null || deletingWalletId.value !== null;
});
const confirmSubmitting = computed(() => {
    return statusUpdating.value || deletingWalletId.value !== null;
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

const setWalletNotice = (type, message) => {
    walletNoticeType.value = type;
    walletNotice.value = message;
};

const asWalletList = (payload) => {
    if (!Array.isArray(payload)) {
        return [];
    }

    return payload;
};

const syncWalletSummary = () => {
    if (!merchant.value) {
        return;
    }

    const count = wallets.value.length;
    if (!merchant.value.wallet_summary || typeof merchant.value.wallet_summary !== 'object') {
        merchant.value.wallet_summary = { count };
    } else {
        merchant.value.wallet_summary.count = count;
    }
    merchant.value.wallets = wallets.value;
};

const setWalletsFromMerchantPayload = () => {
    const source = Array.isArray(merchant.value?.wallets)
        ? merchant.value.wallets
        : Array.isArray(merchant.value?.super_wallets)
            ? merchant.value.super_wallets
            : [];

    wallets.value = asWalletList(source);
    syncWalletSummary();
};

const resetCreateWalletForm = () => {
    createWalletForm.value = {
        coin: '',
        wallet: '',
        fee_rate: '',
    };
};

const loadWallets = async () => {
    if (!merchantId.value) {
        return;
    }

    walletsLoading.value = true;
    walletLoadError.value = '';

    try {
        const response = await getAdminMerchantWallets(merchantId.value);
        wallets.value = asWalletList(response?.data?.data);
        syncWalletSummary();
    } catch (requestError) {
        walletLoadError.value = extractApiErrorMessage(requestError, 'Failed to load wallets.');
    } finally {
        walletsLoading.value = false;
    }
};

const loadMerchant = async () => {
    loading.value = true;
    error.value = '';
    walletNotice.value = '';
    walletLoadError.value = '';

    try {
        const response = await getAdminMerchant(merchantId.value);
        merchant.value = response.data?.data || null;
        setWalletsFromMerchantPayload();
        await loadWallets();
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load merchant details.');
    } finally {
        loading.value = false;
    }
};

const copyWalletAddress = async (wallet) => {
    if (!wallet?.wallet) {
        setWalletNotice('error', 'Nothing to copy.');
        return;
    }

    copyingWalletId.value = wallet.id ?? 'copying';
    const result = await copyTextToClipboard(wallet.wallet);

    if (result.ok) {
        setWalletNotice('success', `Wallet #${wallet.id ?? '—'} address copied.`);
        copyingWalletId.value = null;
        return;
    }

    setWalletNotice('error', result.message || 'Copy failed.');
    copyingWalletId.value = null;
};

const handleStatusAction = (nextStatus) => {
    confirmActionType.value = 'status';
    walletToDelete.value = null;
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

const toggleCreateWalletForm = () => {
    if (showCreateWalletForm.value) {
        showCreateWalletForm.value = false;
        resetCreateWalletForm();
        return;
    }

    showCreateWalletForm.value = true;
    walletNotice.value = '';
};

const submitCreateWallet = async () => {
    const coin = String(createWalletForm.value.coin || '').trim().toLowerCase();
    const walletAddress = String(createWalletForm.value.wallet || '').trim();
    const feeRateRaw = String(createWalletForm.value.fee_rate || '').trim();

    if (!coin) {
        setWalletNotice('error', 'Asset/coin is required.');
        return;
    }

    if (!walletAddress) {
        setWalletNotice('error', 'Wallet is required.');
        return;
    }

    const payload = {
        coin,
        wallet: walletAddress,
        fee_rate: feeRateRaw === '' ? null : Number(feeRateRaw),
    };

    if (payload.fee_rate !== null && Number.isNaN(payload.fee_rate)) {
        setWalletNotice('error', 'Fee rate must be a valid number.');
        return;
    }
    if (payload.fee_rate !== null && payload.fee_rate < 0) {
        setWalletNotice('error', 'Fee rate cannot be negative.');
        return;
    }

    creatingWallet.value = true;

    try {
        await createAdminMerchantWallet(merchantId.value, payload);
        await loadWallets();
        showCreateWalletForm.value = false;
        resetCreateWalletForm();
        setWalletNotice('success', 'Wallet created.');
    } catch (requestError) {
        setWalletNotice('error', extractApiErrorMessage(requestError, 'Failed to create wallet.'));
    } finally {
        creatingWallet.value = false;
    }
};

const startEditWallet = (wallet) => {
    showCreateWalletForm.value = false;
    editingWalletId.value = wallet.id;
    editWalletForm.value = {
        wallet: wallet.wallet || '',
        fee_rate: wallet.fee_rate ?? '',
    };
    walletNotice.value = '';
};

const cancelEditWallet = () => {
    editingWalletId.value = null;
    editWalletForm.value = {
        wallet: '',
        fee_rate: '',
    };
};

const submitEditWallet = async (wallet) => {
    const walletAddress = String(editWalletForm.value.wallet || '').trim();
    const feeRateRaw = String(editWalletForm.value.fee_rate || '').trim();

    if (!walletAddress) {
        setWalletNotice('error', 'Wallet is required.');
        return;
    }

    const payload = {
        wallet: walletAddress,
        fee_rate: feeRateRaw === '' ? null : Number(feeRateRaw),
    };

    if (payload.fee_rate !== null && Number.isNaN(payload.fee_rate)) {
        setWalletNotice('error', 'Fee rate must be a valid number.');
        return;
    }
    if (payload.fee_rate !== null && payload.fee_rate < 0) {
        setWalletNotice('error', 'Fee rate cannot be negative.');
        return;
    }

    savingEditWalletId.value = wallet.id;

    try {
        await updateAdminMerchantWallet(merchantId.value, wallet.id, payload);
        await loadWallets();
        cancelEditWallet();
        setWalletNotice('success', `Wallet #${wallet.id} updated.`);
    } catch (requestError) {
        setWalletNotice('error', extractApiErrorMessage(requestError, 'Failed to update wallet.'));
    } finally {
        savingEditWalletId.value = null;
    }
};

const promptDeleteWallet = (wallet) => {
    walletToDelete.value = wallet;
    confirmActionType.value = 'wallet_delete';
    confirmDanger.value = true;
    confirmTitle.value = `Delete wallet #${wallet.id}`;
    confirmLabel.value = 'Delete';
    confirmMessage.value = 'Delete this wallet for the current merchant? This action cannot be undone.';
    confirmOpen.value = true;
};

const confirmDeleteWallet = async () => {
    if (!walletToDelete.value?.id) {
        return;
    }

    deletingWalletId.value = walletToDelete.value.id;

    try {
        await deleteAdminMerchantWallet(merchantId.value, walletToDelete.value.id);
        await loadWallets();
        if (editingWalletId.value === walletToDelete.value.id) {
            cancelEditWallet();
        }
        setWalletNotice('success', `Wallet #${walletToDelete.value.id} deleted.`);
        confirmOpen.value = false;
        walletToDelete.value = null;
    } catch (requestError) {
        setWalletNotice('error', extractApiErrorMessage(requestError, 'Failed to delete wallet.'));
    } finally {
        deletingWalletId.value = null;
    }
};

const confirmAction = async () => {
    if (confirmActionType.value === 'status') {
        await confirmStatusChange();
        return;
    }

    if (confirmActionType.value === 'wallet_delete') {
        await confirmDeleteWallet();
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

.quick-actions {
    margin-top: 12px;
}

.quick-link {
    text-decoration: none;
    display: inline-block;
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

.wallet-toolbar {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.wallet-form-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    padding: 12px;
    margin-bottom: 12px;
}

.wallet-form-grid {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
}

.wallet-field-label {
    display: block;
    color: #334155;
    font-size: 12px;
    margin-bottom: 6px;
}

.wallet-input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 10px;
    background: #fff;
    color: #0f172a;
    font: inherit;
}

.wallet-form-hint {
    margin-top: 8px;
}

.wallet-form-actions {
    margin-top: 10px;
    display: flex;
    justify-content: flex-start;
}

.wallet-actions {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.wallet-cell {
    white-space: normal;
    min-width: 240px;
}

.wallet-cell .mono {
    overflow-wrap: anywhere;
    word-break: break-word;
}

.wallet-notice {
    margin: 0 0 10px;
    color: #0369a1;
    font-size: 13px;
}

.wallet-notice-error {
    color: #b91c1c;
}

.wallet-gap {
    margin: 0;
    line-height: 1.4;
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

.compact-btn {
    padding: 6px 8px;
    font-size: 12px;
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

.wallets-table th:nth-child(4),
.wallets-table td:nth-child(4) {
    min-width: 260px;
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
