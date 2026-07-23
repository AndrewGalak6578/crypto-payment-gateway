<template>
    <section class="page-stack settlements-page">
        <header class="page-header settlements-header">
            <div>
                <p class="page-kicker">Funds</p>
                <h2 class="page-title">Settlements</h2>
                <p class="page-subtitle">Balances, destination wallets, and forwarding readiness for merchant payouts.</p>
            </div>
            <div class="page-actions settlements-actions">
                <button class="btn btn-secondary" type="button" :disabled="loading" @click="load">
                    {{ loading ? 'Refreshing...' : 'Refresh' }}
                </button>
                <button class="btn btn-primary" type="button" :disabled="!canWriteWallets" @click="startCreate">
                    Add wallet
                </button>
            </div>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div v-if="success" class="alert alert-success">{{ success }}</div>

        <section class="mobile-settlement-hero">
            <div>
                <span>Wallet readiness</span>
                <strong>{{ configuredWallets }} of {{ supportedAssets.length }} assets configured</strong>
                <small>{{ totalBalanceLabel }} internal balance across {{ balances.length }} asset{{ balances.length === 1 ? '' : 's' }}</small>
            </div>
            <div class="mobile-hero-stats">
                <div v-for="metric in metrics" :key="`mobile-${metric.label}`">
                    <span>{{ metric.label }}</span>
                    <strong>{{ metric.value }}</strong>
                </div>
            </div>
            <button class="btn btn-primary" type="button" :disabled="!canWriteWallets" @click="startCreate">
                Add wallet
            </button>
        </section>

        <section class="settlement-metric-grid">
            <article v-for="metric in metrics" :key="metric.label" class="card card-pad settlement-metric-card" :class="`metric-${metric.tone}`">
                <span>{{ metric.label }}</span>
                <strong>{{ metric.value }}</strong>
                <small>{{ metric.note }}</small>
            </article>
        </section>

        <section class="settlement-layout">
            <div class="settlement-main-column">
                <article class="card balances-card">
                    <div class="card-pad section-header">
                        <div>
                            <h3 class="card-title">Internal balances</h3>
                            <p class="card-subtitle">Funds credited when automatic forwarding was not available.</p>
                        </div>
                    </div>

                    <div class="balance-list">
                        <div v-for="balance in normalizedBalances" :key="balance.key" class="balance-row">
                            <AssetBadge :item="balance" />
                            <div>
                                <strong>{{ balance.amountLabel }}</strong>
                                <span>{{ balance.networkLabel }}</span>
                            </div>
                            <span class="balance-updated">{{ formatDate(balance.updated_at) }}</span>
                        </div>
                        <div v-if="!normalizedBalances.length" class="empty-state balance-empty">
                            No internal balances yet.
                        </div>
                    </div>
                </article>

                <article class="card wallets-card">
                    <div class="card-pad section-header">
                        <div>
                            <h3 class="card-title">Destination wallets</h3>
                            <p class="card-subtitle">Forward paid invoice funds to merchant-owned settlement addresses.</p>
                        </div>
                        <button class="btn btn-secondary compact-action" type="button" :disabled="!canWriteWallets" @click="startCreate">
                            Add wallet
                        </button>
                    </div>

                    <div class="wallet-list">
                        <article v-for="wallet in normalizedWallets" :key="wallet.id" class="wallet-row">
                            <div class="wallet-head">
                                <div class="wallet-asset">
                                    <AssetBadge :item="wallet" />
                                    <span>{{ wallet.networkLabel }}</span>
                                </div>
                                <div class="wallet-actions">
                                    <button class="delivery-action" type="button" @click="copyWallet(wallet)">
                                        {{ copiedWalletId === wallet.id ? 'Copied' : 'Copy' }}
                                    </button>
                                    <button class="delivery-action" type="button" :disabled="!canWriteWallets" @click="editWallet(wallet)">
                                        Edit
                                    </button>
                                    <button class="delivery-action danger-action" type="button" :disabled="!canWriteWallets || deletingWalletId === wallet.id" @click="deleteWallet(wallet)">
                                        {{ deletingWalletId === wallet.id ? 'Deleting...' : 'Delete' }}
                                    </button>
                                </div>
                            </div>
                            <code>{{ wallet.wallet }}</code>
                            <div class="wallet-meta">
                                <span>{{ wallet.fee_rate ? `${wallet.fee_rate} fee rate` : 'Default fee handling' }}</span>
                                <span>Updated {{ formatDate(wallet.updated_at) }}</span>
                            </div>
                        </article>
                        <div v-if="!normalizedWallets.length" class="empty-state wallet-empty">
                            No destination wallets configured.
                        </div>
                    </div>
                </article>

                <article class="card activity-card">
                    <div class="card-pad section-header">
                        <div>
                            <h3 class="card-title">Settlement activity</h3>
                            <p class="card-subtitle">Ledger of forwarding transactions, fallback credits, and settlement exceptions.</p>
                        </div>
                        <button class="btn btn-secondary compact-action" type="button" :disabled="activityLoading" @click="loadActivity">
                            {{ activityLoading ? 'Loading...' : 'Reload' }}
                        </button>
                    </div>

                    <div class="activity-toolbar">
                        <button
                            v-for="filter in activityStatusFilters"
                            :key="filter.value || 'all'"
                            class="activity-filter"
                            :class="{ 'is-active': activityFilters.status === filter.value }"
                            type="button"
                            @click="setActivityStatus(filter.value)"
                        >
                            {{ filter.label }}
                        </button>
                    </div>

                    <div class="activity-list">
                        <article v-for="entry in normalizedActivityEntries" :key="entry.id" class="activity-row" :class="`activity-${entry.status}`">
                            <div class="activity-main">
                                <AssetBadge :item="entry" />
                                <div>
                                    <strong>{{ entry.title }}</strong>
                                    <span>{{ entry.amountLabel }} · {{ entry.networkLabel }}</span>
                                </div>
                            </div>

                            <span class="activity-status" :class="`activity-status-${entry.status}`">{{ entry.statusLabel }}</span>

                            <div class="activity-details">
                                <div v-if="entry.txids?.length">
                                    <span>TXID</span>
                                    <code>{{ entry.txids.length === 1 ? shorten(entry.txids[0]) : `${entry.txids.length} txids` }}</code>
                                    <span class="tx-actions">
                                        <a v-if="entry.explorerUrl" class="text-action" :href="entry.explorerUrl" target="_blank" rel="noopener noreferrer">Open</a>
                                        <button class="text-action" type="button" @click="copyText(entry.txids.join('\n'), `txid:${entry.id}`)">
                                            {{ copiedTextKey === `txid:${entry.id}` ? 'Copied' : 'Copy' }}
                                        </button>
                                    </span>
                                </div>
                                <div v-if="entry.destination_wallet">
                                    <span>Destination</span>
                                    <code>{{ shorten(entry.destination_wallet) }}</code>
                                    <button class="text-action" type="button" @click="copyText(entry.destination_wallet, `wallet:${entry.id}`)">
                                        {{ copiedTextKey === `wallet:${entry.id}` ? 'Copied' : 'Copy' }}
                                    </button>
                                </div>
                                <RouterLink
                                    v-if="entry.invoice?.public_id"
                                    class="text-action invoice-link"
                                    :to="{ name: 'merchant-v2.payment-detail', params: { paymentId: entry.invoice.public_id }, query: { from: 'settlements' } }"
                                >
                                    {{ entry.invoice.public_id }}
                                </RouterLink>
                            </div>

                            <p v-if="entry.error_message" class="activity-error">{{ entry.error_message }}</p>
                            <time>{{ formatDateTime(entry.occurred_at || entry.created_at) }}</time>
                        </article>

                        <div v-if="!normalizedActivityEntries.length" class="empty-state activity-empty">
                            No settlement activity yet.
                        </div>
                    </div>

                    <div v-if="activityPagination.currentPage < activityPagination.lastPage" class="activity-load-more">
                        <button class="btn btn-secondary" type="button" :disabled="activityLoading" @click="loadMoreActivity">
                            {{ activityLoading ? 'Loading...' : 'Load more activity' }}
                        </button>
                    </div>
                </article>
            </div>

            <aside class="settlement-side-column">
                <article ref="walletFormCard" class="card card-pad wallet-form-card">
                    <div class="section-header">
                        <div>
                            <h3 class="card-title">{{ editingWalletId ? 'Edit destination' : 'Add destination wallet' }}</h3>
                            <p class="card-subtitle">Choose one supported asset and provide the payout address.</p>
                        </div>
                    </div>

                    <form v-if="canWriteWallets" class="wallet-form" @submit.prevent="submitWallet">
                        <div class="asset-choice-grid">
                            <button
                                v-for="asset in supportedAssets"
                                :key="asset.assetKey"
                                class="asset-choice"
                                :class="{
                                    'is-selected': walletForm.coin === asset.assetKey,
                                    'is-configured': walletForAsset(asset.assetKey),
                                }"
                                type="button"
                                @click="selectAssetForWallet(asset)"
                            >
                                <AssetLogo :item="{ asset_key: asset.assetKey }" />
                                <span>
                                    <strong>{{ asset.symbol }}</strong>
                                    <small>{{ walletForAsset(asset.assetKey) ? 'Configured' : asset.networkLabel }}</small>
                                </span>
                            </button>
                        </div>

                        <label class="field">
                            <span class="field-label">Wallet address</span>
                            <textarea v-model.trim="walletForm.wallet" class="input wallet-textarea" rows="4" placeholder="Destination address" required></textarea>
                            <span v-if="fieldErrors.wallet" class="field-error">{{ fieldErrors.wallet }}</span>
                        </label>

                        <label class="field">
                            <span class="field-label">Fee rate override</span>
                            <input v-model.trim="walletForm.fee_rate" class="input" type="number" min="0" step="0.00000001" placeholder="Optional" />
                            <p class="field-help">Optional network fee override used by forwarding logic.</p>
                            <span v-if="fieldErrors.fee_rate" class="field-error">{{ fieldErrors.fee_rate }}</span>
                        </label>

                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit" :disabled="savingWallet || !walletForm.wallet">
                                {{ savingWallet ? 'Saving...' : editingWalletId ? 'Save wallet' : 'Add wallet' }}
                            </button>
                            <button v-if="editingWalletId" class="btn btn-secondary" type="button" @click="resetForm">
                                Cancel
                            </button>
                        </div>
                    </form>

                    <div v-else class="permission-panel">
                        <strong>Wallet write access required</strong>
                        <p>Your role can view settlement data but cannot change destination wallets.</p>
                    </div>
                </article>

                <article class="card card-pad readiness-card">
                    <h3 class="card-title">Forwarding coverage</h3>
                    <p class="card-subtitle">Assets without a destination wallet fall back to internal merchant balances.</p>
                    <div class="coverage-list">
                        <div v-for="asset in coverageAssets" :key="asset.assetKey" class="coverage-row">
                            <AssetBadge :item="{ asset_key: asset.assetKey }" />
                            <span :class="asset.configured ? 'coverage-ready' : 'coverage-missing'">
                                {{ asset.configured ? 'Ready' : 'Missing wallet' }}
                            </span>
                        </div>
                    </div>
                </article>
            </aside>
        </section>
    </section>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { useAuthStore } from '../../../stores/auth';
import { MERCHANT_ASSET_CATALOG, findCatalogAsset } from '../../../utils/merchantAssetCatalog';
import { displayAssetNetwork } from '../../../utils/assetDisplay';
import AssetBadge from '../../components/payments/AssetBadge.vue';
import AssetLogo from '../../components/payments/AssetLogo.vue';
import { merchantApi } from '../../services/merchantApi';

const authStore = useAuthStore();
const loading = ref(true);
const error = ref('');
const success = ref('');
const balances = ref([]);
const wallets = ref([]);
const activityEntries = ref([]);
const activityLoading = ref(false);
const activityPagination = reactive({
    currentPage: 1,
    lastPage: 1,
    perPage: 12,
    total: 0,
});
const savingWallet = ref(false);
const deletingWalletId = ref(null);
const copiedWalletId = ref(null);
const copiedTextKey = ref(null);
const editingWalletId = ref(null);
const walletFormCard = ref(null);
const fieldErrors = reactive({});
let walletCopyTimer = null;
let textCopyTimer = null;

const walletForm = reactive({
    coin: 'btc',
    wallet: '',
    fee_rate: '',
});

const activityFilters = reactive({
    status: '',
});

const activityStatusFilters = [
    { label: 'All', value: '' },
    { label: 'Completed', value: 'completed' },
    { label: 'Pending', value: 'pending' },
    { label: 'Deferred', value: 'deferred' },
    { label: 'Failed', value: 'failed' },
];

const supportedAssets = MERCHANT_ASSET_CATALOG.filter((asset) => asset.assetKey);
const canWriteWallets = computed(() => authStore.hasCapability('wallets.write'));
const configuredWallets = computed(() => normalizedWallets.value.length);
const totalBalanceLabel = computed(() => {
    const total = normalizedBalances.value.reduce((sum, balance) => sum + Number(balance.amount || 0), 0);
    return total > 0 ? `${formatAmount(total)} total coin units` : 'No';
});

const normalizedBalances = computed(() => balances.value.map((balance, index) => {
    const assetKey = String(balance.asset_key || balance.coin || '').toLowerCase();
    const catalog = findCatalogAsset(assetKey);

    return {
        ...balance,
        key: `${assetKey}:${index}`,
        asset_key: assetKey,
        network_key: balance.network_key || catalog?.networkKey,
        amountLabel: `${formatAmount(balance.amount)} ${catalog?.symbol || balance.coin || assetKey.toUpperCase()}`,
        networkLabel: displayAssetNetwork({ ...balance, asset_key: assetKey, network_key: balance.network_key || catalog?.networkKey }),
    };
}));

const normalizedWallets = computed(() => wallets.value.map((wallet) => {
    const assetKey = String(wallet.asset_key || wallet.coin || '').toLowerCase();
    const catalog = findCatalogAsset(assetKey);

    return {
        ...wallet,
        asset_key: assetKey,
        network_key: wallet.network_key || catalog?.networkKey,
        networkLabel: displayAssetNetwork({ ...wallet, asset_key: assetKey, network_key: wallet.network_key || catalog?.networkKey }),
    };
}));

const walletAssetKeys = computed(() => new Set(normalizedWallets.value.map((wallet) => wallet.asset_key)));
const coverageAssets = computed(() => supportedAssets.map((asset) => ({
    ...asset,
    configured: walletAssetKeys.value.has(asset.assetKey),
})));

const normalizedActivityEntries = computed(() => activityEntries.value.map((entry) => {
    const assetKey = String(entry.asset_key || '').toLowerCase();
    const catalog = findCatalogAsset(assetKey);
    const amount = formatAmount(entry.amount_coin);
    const symbol = catalog?.symbol || assetKey.toUpperCase();

    return {
        ...entry,
        asset_key: assetKey,
        networkLabel: displayAssetNetwork(entry),
        amountLabel: `${amount} ${symbol}`,
        title: activityTitle(entry),
        statusLabel: activityStatusLabel(entry.status),
        explorerUrl: explorerTxUrl(entry.network_key, entry.txids?.[0] || entry.txid),
    };
}));

const metrics = computed(() => [
    {
        label: 'Internal balances',
        value: String(normalizedBalances.value.length),
        note: normalizedBalances.value.length ? 'Assets waiting on manual settlement or wallet setup' : 'No fallback balances',
        tone: normalizedBalances.value.length ? 'warning' : 'success',
    },
    {
        label: 'Destination wallets',
        value: String(configuredWallets.value),
        note: `${supportedAssets.length} supported assets`,
        tone: configuredWallets.value ? 'success' : 'warning',
    },
    {
        label: 'Missing coverage',
        value: String(coverageAssets.value.filter((asset) => !asset.configured).length),
        note: 'Assets that will credit internal balance instead of forwarding',
        tone: coverageAssets.value.some((asset) => !asset.configured) ? 'warning' : 'success',
    },
]);

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
const formatDateTime = (value) => (value ? new Date(value).toLocaleString() : '—');
const formatAmount = (value) => {
    const number = Number(value || 0);
    if (!Number.isFinite(number)) return String(value || '0');
    return number.toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 8,
    });
};

const shorten = (value) => {
    if (!value) return '—';
    const text = String(value);
    return text.length > 22 ? `${text.slice(0, 10)}...${text.slice(-8)}` : text;
};

const explorerTxUrl = (networkKey, txid) => {
    if (!txid) return null;
    const encoded = encodeURIComponent(txid);
    const explorers = {
        bitcoin: `https://mempool.space/tx/${encoded}`,
        litecoin: `https://litecoinspace.org/tx/${encoded}`,
        dash: `https://insight.dash.org/insight/tx/${encoded}`,
    };

    return explorers[String(networkKey || '').toLowerCase()] || null;
};

const activityTitle = (entry) => {
    if (entry.type === 'internal_credit') return 'Credited to internal balance';
    if (entry.status === 'failed') return 'Forwarding failed';
    if (entry.type === 'forward_held') return 'Settlement held by policy';
    if (entry.status === 'deferred') return 'Forwarding deferred';
    if (entry.status === 'pending') return 'Forwarding pending';
    return 'Forwarded to destination';
};

const activityStatusLabel = (status) => {
    if (status === 'completed') return 'Completed';
    if (status === 'pending') return 'Pending';
    if (status === 'deferred') return 'Deferred';
    if (status === 'failed') return 'Failed';
    return status || 'Unknown';
};

const clearFieldErrors = () => {
    Object.keys(fieldErrors).forEach((key) => {
        delete fieldErrors[key];
    });
};

const load = async () => {
    loading.value = true;
    error.value = '';

    try {
        const [balancesResponse, walletsResponse, activityResponse] = await Promise.all([
            merchantApi.balances(),
            merchantApi.wallets(),
            merchantApi.settlementEntries({ per_page: activityPagination.perPage, status: activityFilters.status || undefined }),
        ]);
        balances.value = balancesResponse.data?.data || [];
        wallets.value = walletsResponse.data?.data || [];
        applyActivityResponse(activityResponse.data?.data, { replace: true });
    } catch {
        error.value = 'Failed to load settlement resources.';
    } finally {
        loading.value = false;
    }
};

const loadActivity = async () => {
    activityLoading.value = true;
    error.value = '';

    try {
        const response = await merchantApi.settlementEntries({
            per_page: activityPagination.perPage,
            status: activityFilters.status || undefined,
        });
        applyActivityResponse(response.data?.data, { replace: true });
    } catch {
        error.value = 'Failed to load settlement activity.';
    } finally {
        activityLoading.value = false;
    }
};

const loadMoreActivity = async () => {
    if (activityLoading.value || activityPagination.currentPage >= activityPagination.lastPage) {
        return;
    }

    activityLoading.value = true;
    error.value = '';

    try {
        const response = await merchantApi.settlementEntries({
            page: activityPagination.currentPage + 1,
            per_page: activityPagination.perPage,
            status: activityFilters.status || undefined,
        });
        applyActivityResponse(response.data?.data, { replace: false });
    } catch {
        error.value = 'Failed to load settlement activity.';
    } finally {
        activityLoading.value = false;
    }
};

const applyActivityResponse = (payload, { replace } = { replace: true }) => {
    const rows = payload?.data || [];
    activityEntries.value = replace ? rows : [...activityEntries.value, ...rows];
    activityPagination.currentPage = Number(payload?.current_page || 1);
    activityPagination.lastPage = Number(payload?.last_page || 1);
    activityPagination.perPage = Number(payload?.per_page || activityPagination.perPage);
    activityPagination.total = Number(payload?.total || rows.length);
};

const setActivityStatus = async (status) => {
    activityFilters.status = status;
    await loadActivity();
};

const scrollToWalletForm = async () => {
    await nextTick();
    walletFormCard.value?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
};

const startCreate = async () => {
    resetForm();
    await scrollToWalletForm();
};

const walletForAsset = (assetKey) => normalizedWallets.value.find((wallet) => wallet.asset_key === assetKey) || null;

const selectAssetForWallet = (asset) => {
    const existingWallet = walletForAsset(asset.assetKey);

    if (existingWallet) {
        editWallet(existingWallet);
        return;
    }

    editingWalletId.value = null;
    walletForm.coin = asset.assetKey;
    walletForm.wallet = '';
    walletForm.fee_rate = '';
    clearFieldErrors();
};

const editWallet = (wallet) => {
    editingWalletId.value = wallet.id;
    walletForm.coin = wallet.asset_key || wallet.coin?.toLowerCase() || supportedAssets[0]?.assetKey || 'btc';
    walletForm.wallet = wallet.wallet || '';
    walletForm.fee_rate = wallet.fee_rate || '';
    clearFieldErrors();
    void scrollToWalletForm();
};

const resetForm = () => {
    editingWalletId.value = null;
    walletForm.coin = supportedAssets[0]?.assetKey || 'btc';
    walletForm.wallet = '';
    walletForm.fee_rate = '';
    clearFieldErrors();
};

const submitWallet = async () => {
    savingWallet.value = true;
    error.value = '';
    success.value = '';
    clearFieldErrors();

    try {
        const payload = {
            wallet: walletForm.wallet,
            fee_rate: walletForm.fee_rate || null,
        };

        const response = editingWalletId.value
            ? await merchantApi.updateWallet(editingWalletId.value, payload)
            : await merchantApi.createWallet({ ...payload, coin: walletForm.coin });

        const savedWallet = response.data?.data;
        if (savedWallet) {
            wallets.value = editingWalletId.value
                ? wallets.value.map((wallet) => (wallet.id === savedWallet.id ? savedWallet : wallet))
                : [savedWallet, ...wallets.value.filter((wallet) => wallet.id !== savedWallet.id)];
        }

        success.value = editingWalletId.value ? 'Settlement wallet updated.' : 'Settlement wallet added.';
        resetForm();
    } catch (requestError) {
        const errors = requestError?.response?.data?.errors || {};
        Object.entries(errors).forEach(([key, messages]) => {
            fieldErrors[key] = Array.isArray(messages) ? messages[0] : String(messages);
        });
        error.value = requestError?.response?.data?.message || 'Failed to save settlement wallet.';
    } finally {
        savingWallet.value = false;
    }
};

const deleteWallet = async (wallet) => {
    if (!window.confirm(`Delete ${wallet.networkLabel || wallet.coin} destination wallet?`)) return;

    deletingWalletId.value = wallet.id;
    error.value = '';
    success.value = '';

    try {
        await merchantApi.deleteWallet(wallet.id);
        wallets.value = wallets.value.filter((item) => item.id !== wallet.id);
        if (editingWalletId.value === wallet.id) {
            resetForm();
        }
        success.value = 'Settlement wallet deleted.';
    } catch {
        error.value = 'Failed to delete settlement wallet.';
    } finally {
        deletingWalletId.value = null;
    }
};

const copyWallet = async (wallet) => {
    await copyText(wallet.wallet || '', `wallet-row:${wallet.id}`);
    copiedWalletId.value = wallet.id;
};

const copyText = async (value, key) => {
    if (!value) return;

    await navigator.clipboard.writeText(value);
    copiedTextKey.value = key;

    if (walletCopyTimer) {
        window.clearTimeout(walletCopyTimer);
    }
    if (textCopyTimer) {
        window.clearTimeout(textCopyTimer);
    }

    textCopyTimer = window.setTimeout(() => {
        copiedTextKey.value = null;
        copiedWalletId.value = null;
        textCopyTimer = null;
    }, 1200);
};

onMounted(load);
</script>

<style scoped>
.settlements-page {
    gap: 18px;
}

.mobile-settlement-hero {
    display: none;
}

.settlement-metric-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.settlement-metric-card {
    display: grid;
    gap: 6px;
    border-left: 4px solid var(--m-brand-500);
}

.settlement-metric-card span,
.settlement-metric-card small {
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.settlement-metric-card strong {
    color: var(--m-text);
    font-size: 28px;
    line-height: 1.05;
}

.metric-success {
    border-left-color: var(--m-success-500);
}

.metric-warning {
    border-left-color: var(--m-warning-500);
}

.settlement-layout {
    display: grid;
    gap: 16px;
}

.settlement-main-column,
.settlement-side-column,
.balance-list,
.wallet-list,
.coverage-list {
    display: grid;
    gap: 12px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
}

.balances-card,
.wallets-card,
.activity-card {
    overflow: hidden;
}

.balance-list,
.wallet-list,
.activity-list {
    padding: 0 18px 18px;
}

.balance-row,
.wallet-row,
.coverage-row,
.activity-row {
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
}

.balance-row {
    display: grid;
    grid-template-columns: minmax(140px, auto) minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 13px;
}

.balance-row .asset-badge,
.wallet-asset .asset-badge,
.coverage-row .asset-badge {
    width: fit-content;
}

.balance-row strong {
    display: block;
    color: var(--m-text);
    font-size: var(--m-md);
}

.balance-row span,
.balance-updated,
.wallet-meta,
.wallet-asset > span {
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.wallet-row {
    display: grid;
    gap: 10px;
    align-items: start;
    padding: 13px;
}

.wallet-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    min-width: 0;
}

.wallet-asset,
.wallet-meta {
    display: grid;
    gap: 5px;
    min-width: 0;
}

.wallet-asset .asset-badge {
    max-width: 100%;
}

.wallet-asset > span {
    overflow-wrap: anywhere;
}

.wallet-row code {
    min-width: 0;
    padding: 10px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-md);
    background: var(--m-surface);
    overflow-wrap: anywhere;
    color: var(--m-text);
    font-size: var(--m-xs);
    line-height: 1.45;
}

.wallet-actions,
.form-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
}

.delivery-action {
    min-height: 30px;
    border: 1px solid var(--m-border-strong);
    border-radius: var(--m-radius-md);
    background: var(--m-surface);
    color: var(--m-brand-700);
    padding: 0 10px;
    font-size: var(--m-xs);
    font-weight: 850;
}

.danger-action {
    color: var(--m-danger-700);
}

.wallet-form-card,
.readiness-card {
    display: grid;
    gap: 16px;
}

.wallet-form {
    display: grid;
    gap: 14px;
}

.asset-choice-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

.asset-choice {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 9px;
    align-items: center;
    min-height: 54px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface);
    padding: 10px;
    text-align: left;
}

.asset-choice.is-selected {
    border-color: rgba(36, 107, 254, 0.45);
    background: var(--m-brand-50);
}

.asset-choice.is-configured {
    border-color: rgba(22, 163, 74, 0.32);
    background:
        linear-gradient(135deg, rgba(236, 253, 243, 0.96), rgba(255, 255, 255, 0.98)),
        var(--m-surface);
    box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.08);
}

.asset-choice.is-configured.is-selected {
    border-color: rgba(22, 163, 74, 0.58);
    background: var(--m-success-50);
}

.asset-choice strong,
.asset-choice small {
    display: block;
}

.asset-choice strong {
    color: var(--m-text);
    font-size: var(--m-sm);
}

.asset-choice small {
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.wallet-textarea {
    min-height: 92px;
    resize: vertical;
}

.activity-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 0 18px 12px;
}

.activity-filter {
    min-height: 32px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-pill);
    background: var(--m-surface);
    color: var(--m-muted);
    padding: 0 12px;
    font-size: var(--m-xs);
    font-weight: 850;
}

.activity-filter.is-active {
    border-color: rgba(36, 107, 254, 0.35);
    background: var(--m-brand-50);
    color: var(--m-brand-700);
}

.activity-row {
    display: grid;
    grid-template-columns: minmax(210px, 1fr) auto minmax(250px, 1.1fr) auto;
    gap: 12px;
    align-items: center;
    padding: 13px;
}

.activity-main {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 10px;
    align-items: center;
    min-width: 0;
}

.activity-main .asset-badge {
    width: fit-content;
}

.activity-main strong,
.activity-main span {
    display: block;
    min-width: 0;
}

.activity-main strong {
    color: var(--m-text);
    font-size: var(--m-sm);
}

.activity-main span,
.activity-details span,
.activity-row time {
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.activity-status {
    min-height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--m-radius-pill);
    padding: 0 10px;
    font-size: var(--m-xs);
    font-weight: 850;
}

.activity-status-completed {
    background: var(--m-success-50);
    color: var(--m-success-700);
}

.activity-status-pending,
.activity-status-deferred {
    background: var(--m-warning-50);
    color: var(--m-warning-700);
}

.activity-status-failed {
    background: var(--m-danger-50);
    color: var(--m-danger-700);
}

.activity-details {
    display: grid;
    gap: 7px;
    min-width: 0;
}

.activity-details div {
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr) auto;
    gap: 8px;
    align-items: center;
    min-width: 0;
}

.activity-details code {
    min-width: 0;
    overflow: hidden;
    color: var(--m-text);
    font-size: var(--m-xs);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.text-action {
    border: 0;
    background: transparent;
    color: var(--m-brand-700);
    padding: 0;
    font-size: var(--m-xs);
    font-weight: 850;
    text-decoration: none;
}

.tx-actions {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
}

.invoice-link {
    justify-self: start;
}

.activity-load-more {
    display: flex;
    justify-content: center;
    padding: 0 18px 18px;
}

.activity-load-more .btn {
    min-width: 180px;
}

.activity-error {
    grid-column: 1 / -1;
    margin: 0;
    color: var(--m-danger-700);
    font-size: var(--m-xs);
}

.permission-panel {
    display: grid;
    gap: 6px;
    padding: 14px;
    border: 1px solid var(--m-warning-500);
    border-radius: var(--m-radius-lg);
    background: var(--m-warning-50);
}

.permission-panel strong {
    color: var(--m-warning-700);
    font-size: var(--m-sm);
}

.permission-panel p {
    margin: 0;
    color: var(--m-muted);
    font-size: var(--m-xs);
}

.coverage-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 11px;
}

.coverage-ready,
.coverage-missing {
    font-size: var(--m-xs);
    font-weight: 850;
}

.coverage-ready {
    color: var(--m-success-700);
}

.coverage-missing {
    color: var(--m-warning-700);
}

.balance-empty,
.wallet-empty {
    border: 1px dashed var(--m-border-strong);
    border-radius: var(--m-radius-lg);
}

@media (min-width: 1180px) {
    .settlement-layout {
        grid-template-columns: minmax(0, 1fr) 390px;
        align-items: start;
    }

    .settlement-side-column {
        position: sticky;
        top: calc(var(--m-topbar-height) + 24px);
    }
}

@media (max-width: 980px) {
    .settlement-metric-grid {
        grid-template-columns: 1fr;
    }

    .wallet-actions {
        justify-content: flex-start;
    }
}

@media (max-width: 720px) {
    .settlements-page {
        gap: 14px;
    }

    .settlements-header {
        display: grid;
        gap: 4px;
        padding: 0 2px;
    }

    .settlements-header .page-kicker,
    .settlements-header .page-subtitle,
    .settlements-actions {
        display: none;
    }

    .settlements-header .page-title {
        font-size: 24px;
        line-height: 1.1;
    }

    .settlement-metric-grid {
        display: none;
    }

    .mobile-settlement-hero {
        display: grid;
        gap: 16px;
        padding: 16px;
        border: 1px solid rgba(21, 94, 239, 0.14);
        border-radius: 24px;
        background:
            linear-gradient(145deg, rgba(238, 245, 255, 0.98), rgba(255, 255, 255, 0.98) 56%),
            var(--m-surface);
        box-shadow: 0 12px 28px rgba(16, 24, 40, 0.08);
    }

    .mobile-settlement-hero span,
    .mobile-settlement-hero small {
        display: block;
        color: var(--m-muted);
        font-size: var(--m-xs);
    }

    .mobile-settlement-hero strong {
        display: block;
        margin: 4px 0;
        color: var(--m-text);
        font-size: 19px;
        line-height: 1.2;
    }

    .mobile-hero-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
    }

    .mobile-hero-stats div {
        min-width: 0;
        padding: 10px;
        border: 1px solid rgba(214, 221, 232, 0.8);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.72);
    }

    .mobile-hero-stats span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .mobile-hero-stats strong {
        margin: 5px 0 0;
        font-size: 18px;
    }

    .mobile-settlement-hero .btn {
        min-height: 46px;
        width: 100%;
        border-radius: 14px;
    }

    .settlement-layout,
    .settlement-main-column,
    .settlement-side-column {
        display: contents;
    }

    .readiness-card {
        order: 1;
    }

    .wallets-card {
        order: 2;
    }

    .balances-card {
        order: 3;
    }

    .activity-card {
        order: 4;
    }

    .wallet-form-card {
        order: 5;
    }

    .balances-card,
    .wallets-card,
    .activity-card,
    .wallet-form-card,
    .readiness-card {
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        overflow: visible;
    }

    .balances-card > .section-header,
    .wallets-card > .section-header,
    .activity-card > .section-header,
    .wallet-form-card > .section-header,
    .readiness-card {
        padding: 0;
    }

    .section-header {
        display: grid;
        gap: 6px;
    }

    .compact-action {
        display: none;
    }

    .card-title {
        font-size: 17px;
        line-height: 1.25;
    }

    .card-subtitle {
        font-size: var(--m-xs);
        line-height: 1.45;
    }

    .coverage-list {
        display: flex;
        gap: 8px;
        margin: 0 -16px;
        padding: 2px 16px;
        overflow-x: auto;
        scroll-padding: 16px;
        scrollbar-width: none;
    }

    .coverage-list::-webkit-scrollbar {
        display: none;
    }

    .coverage-row {
        flex: 0 0 auto;
        min-width: 156px;
        justify-content: flex-start;
        align-items: center;
        padding: 10px;
        border-radius: 16px;
        background: var(--m-surface);
    }

    .coverage-row > span {
        margin-left: auto;
    }

    .asset-choice-grid {
        display: flex;
        gap: 8px;
        margin: 0 -16px;
        padding: 0 16px 2px;
        overflow-x: auto;
        scroll-padding: 16px;
        scroll-snap-type: x mandatory;
        scrollbar-width: none;
    }

    .asset-choice-grid::-webkit-scrollbar {
        display: none;
    }

    .asset-choice {
        flex: 0 0 142px;
        min-height: 58px;
        border-radius: 16px;
        scroll-snap-align: start;
    }

    .balance-list,
    .wallet-list,
    .activity-list {
        padding: 0;
    }

    .activity-toolbar {
        flex-wrap: nowrap;
        margin: 0 -16px;
        padding: 2px 16px 8px;
        overflow-x: auto;
        scrollbar-width: none;
    }

    .activity-toolbar::-webkit-scrollbar {
        display: none;
    }

    .balance-row {
        grid-template-columns: 1fr;
        gap: 9px;
        align-items: start;
        padding: 14px;
        border-radius: 18px;
        background: var(--m-surface);
    }

    .balance-updated {
        justify-self: start;
    }

    .wallet-row {
        gap: 12px;
        padding: 14px;
        border-radius: 20px;
        background: var(--m-surface);
    }

    .activity-row {
        grid-template-columns: 1fr;
        gap: 11px;
        align-items: start;
        padding: 14px;
        border-radius: 20px;
        background: var(--m-surface);
    }

    .activity-main {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .activity-status {
        justify-self: start;
    }

    .activity-details div {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 5px 8px;
        padding: 10px;
        border: 1px solid var(--m-border);
        border-radius: 14px;
        background: var(--m-surface-subtle);
    }

    .activity-details div span {
        grid-column: 1 / -1;
    }

    .activity-details .tx-actions {
        grid-column: auto;
        justify-content: flex-start;
    }

    .activity-details code {
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .activity-load-more {
        padding: 0;
    }

    .activity-load-more .btn {
        min-height: 44px;
        width: 100%;
    }

    .wallet-head {
        display: grid;
        gap: 10px;
    }

    .wallet-actions {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .wallet-actions,
    .form-actions {
        display: grid;
        width: 100%;
    }

    .wallet-actions .delivery-action {
        min-height: 40px;
        width: 100%;
    }

    .form-actions {
        grid-template-columns: 1fr;
    }

    .form-actions .btn {
        min-height: 44px;
        width: 100%;
    }

    .wallet-form {
        padding: 14px;
        border: 1px solid var(--m-border);
        border-radius: 22px;
        background: var(--m-surface);
    }

    .wallet-textarea {
        min-height: 104px;
    }

    .permission-panel {
        border-radius: 18px;
    }
}
</style>
