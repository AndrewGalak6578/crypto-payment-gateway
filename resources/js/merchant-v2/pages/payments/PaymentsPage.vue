<template>
    <section class="page-stack payments-page">
        <header class="page-header">
            <div>
                <p class="page-kicker">Payment operations</p>
                <h2 class="page-title">Payments</h2>
                <p class="page-subtitle">Track checkout links, payer status, assets, and operational exceptions.</p>
            </div>
            <div class="page-actions">
                <RouterLink class="btn btn-primary" :to="{ name: 'merchant-v2.create-payment' }">Create payment link</RouterLink>
            </div>
        </header>

        <section class="payments-overview card card-pad">
            <div>
                <p class="metric-label">Visible payments</p>
                <p class="metric-value">{{ meta.total || payments.length }}</p>
                <p class="metric-note">Matching current filters</p>
            </div>
            <div class="exception-strip">
                <button
                    v-for="view in decoratedStatusViews"
                    :key="view.key"
                    class="view-chip"
                    :class="{ 'is-active': activeView === view.key }"
                    type="button"
                    @click="setView(view.key)"
                >
                    <span>{{ view.label }}</span>
                    <strong>{{ view.countValue }}</strong>
                </button>
            </div>
        </section>

        <section class="card card-pad payments-toolbar">
            <div class="toolbar-header">
                <div>
                    <h3 class="card-title">Payment search</h3>
                    <p class="card-subtitle">Find a checkout by payment ID, merchant reference, status, or asset.</p>
                </div>
                <button class="btn btn-ghost" type="button" @click="clearFilters">Clear filters</button>
            </div>

            <div class="mobile-search-strip">
                <div class="search-field">
                    <input v-model="filters.search" class="input" placeholder="Search ID or reference" @keyup.enter="applyFilters" />
                    <span v-if="isSearching" class="search-spinner" aria-hidden="true"></span>
                </div>
                <details class="mobile-filter-panel">
                    <summary>
                        <span>Filters</span>
                        <strong v-if="activeFilterChips.length">{{ activeFilterChips.length }}</strong>
                    </summary>
                    <div class="mobile-filter-body">
                        <select v-model="filters.status" class="select">
                            <option value="">Any status</option>
                            <option value="awaiting">Awaiting payer</option>
                            <option value="awaiting_asset">Awaiting asset</option>
                            <option value="pending">Awaiting payment</option>
                            <option value="partial">Partial</option>
                            <option value="fixated">Confirming</option>
                            <option value="paid">Paid</option>
                            <option value="expired">Expired</option>
                        </select>
                        <div class="asset-filter">
                            <button
                                v-for="asset in assetFilterOptions"
                                :key="asset.assetKey"
                                class="asset-filter-chip"
                                :class="{ 'is-active': filters.assets.includes(asset.assetKey) }"
                                type="button"
                                @click="toggleAsset(asset.assetKey)"
                            >
                                <span>{{ asset.symbol }}</span>
                            </button>
                        </div>
                        <div class="mobile-date-grid">
                            <input v-model="filters.date_from" class="input" type="date" aria-label="Created from" />
                            <input v-model="filters.date_to" class="input" type="date" aria-label="Created to" />
                        </div>
                        <select v-model.number="filters.per_page" class="select" aria-label="Rows per page">
                            <option :value="15">15 / page</option>
                            <option :value="25">25 / page</option>
                            <option :value="50">50 / page</option>
                            <option :value="100">100 / page</option>
                        </select>
                        <div class="mobile-filter-actions">
                            <button class="btn btn-secondary" type="button" @click="clearFilters">Reset</button>
                            <button class="btn btn-primary" type="button" @click="applyFilters">Apply</button>
                        </div>
                    </div>
                </details>
            </div>

            <div class="filter-row desktop-filter-row">
                <div class="search-field">
                    <input v-model="filters.search" class="input" placeholder="Search payment ID or reference" @keyup.enter="applyFilters" />
                    <span v-if="isSearching" class="search-spinner" aria-hidden="true"></span>
                </div>
                <select v-model="filters.status" class="select">
                    <option value="">Any status</option>
                    <option value="awaiting">Awaiting payer</option>
                    <option value="awaiting_asset">Awaiting asset</option>
                    <option value="pending">Awaiting payment</option>
                    <option value="partial">Partial</option>
                    <option value="fixated">Confirming</option>
                    <option value="paid">Paid</option>
                    <option value="expired">Expired</option>
                </select>
                <div class="asset-filter">
                    <button
                        v-for="asset in assetFilterOptions"
                        :key="asset.assetKey"
                        class="asset-filter-chip"
                        :class="{ 'is-active': filters.assets.includes(asset.assetKey) }"
                        type="button"
                        @click="toggleAsset(asset.assetKey)"
                    >
                        <span>{{ asset.symbol }}</span>
                    </button>
                </div>
                <input v-model="filters.date_from" class="input" type="date" aria-label="Created from" />
                <input v-model="filters.date_to" class="input" type="date" aria-label="Created to" />
                <select v-model.number="filters.per_page" class="select" aria-label="Rows per page">
                    <option :value="15">15 / page</option>
                    <option :value="25">25 / page</option>
                    <option :value="50">50 / page</option>
                    <option :value="100">100 / page</option>
                </select>
                <button class="btn btn-secondary" type="button" @click="applyFilters">Apply</button>
            </div>

            <div v-if="activeFilterChips.length" class="active-filters" aria-label="Active filters">
                <button
                    v-for="chip in activeFilterChips"
                    :key="chip.key"
                    class="filter-chip"
                    type="button"
                    @click="removeFilter(chip.key)"
                >
                    <span>{{ chip.label }}</span>
                    <strong>{{ chip.value }}</strong>
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        </section>

        <div v-if="notice" class="alert alert-success">{{ notice }}</div>
        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <section
            ref="paymentWorkspace"
            class="payment-workspace"
            :style="paymentWorkspaceStyle"
            :class="{ 'is-resizing': isResizingDetails, 'is-detail-pinned': isDetailPinned }"
        >
            <article class="card">
                <div class="desktop-table table-scroll">
                    <table class="payment-table">
                        <thead>
                            <tr>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Asset</th>
                                <th>Amount</th>
                                <th>Received</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-if="loading">
                                <tr v-for="index in 6" :key="`skeleton-${index}`">
                                    <td colspan="7"><div class="skeleton skeleton-row"></div></td>
                                </tr>
                            </template>
                            <template v-else>
                                <tr
                                    v-for="payment in displayedPayments"
                                    :key="payment.id"
                                    :class="{ 'is-selected': String(payment.id) === String(selectedId) }"
                                    @click="selectPayment(payment)"
                                >
                                    <td>
                                        <div class="payment-primary">
                                            <span class="payment-id">{{ payment.public_id }}</span>
                                            <span class="payment-meta">{{ payment.external_id || 'No reference' }}</span>
                                        </div>
                                    </td>
                                    <td><PaymentStatusBadge :payment="payment" /></td>
                                    <td><AssetBadge :item="payment" /></td>
                                    <td>{{ payment.expected_usd || '—' }} USD</td>
                                    <td>{{ payment.received_all_coin || '0' }}</td>
                                    <td>{{ formatDate(payment.created_at) }}</td>
                                    <td>
                                        <button class="row-action" type="button" :disabled="!payment.hosted_url" @click.stop="copyHostedLink(payment.hosted_url)">
                                            Copy link
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="payment-mobile-list card-pad">
                    <template v-if="loading">
                        <div v-for="index in 4" :key="`mobile-skeleton-${index}`" class="card payment-mobile-item payment-mobile-skeleton">
                            <div class="skeleton skeleton-mobile-title"></div>
                            <div class="skeleton skeleton-mobile-line"></div>
                            <div class="skeleton skeleton-mobile-line is-short"></div>
                        </div>
                    </template>
                    <button
                        v-else
                        v-for="payment in displayedPayments"
                        :key="payment.id"
                        class="card payment-mobile-item"
                        type="button"
                        @click="openFull(payment.id)"
                    >
                        <div class="mobile-payment-top">
                            <AssetLogo class="mobile-payment-logo" :item="payment" />
                            <div class="mobile-payment-title">
                                <strong>{{ payment.expected_usd || '—' }} USD</strong>
                                <p>{{ payment.public_id }}</p>
                            </div>
                            <PaymentStatusBadge :payment="payment" />
                        </div>
                        <div class="mobile-payment-meta">
                            <span>Reference</span>
                            <strong>{{ payment.external_id || 'No reference' }}</strong>
                        </div>
                        <div class="mobile-payment-meta">
                            <span>Asset</span>
                            <AssetBadge :item="payment" />
                        </div>
                        <div class="mobile-payment-meta">
                            <span>Received</span>
                            <strong>{{ payment.received_all_coin || '0' }}</strong>
                        </div>
                        <div class="mobile-payment-footer">
                            <span>{{ formatDate(payment.created_at) }}</span>
                            <strong>Open details</strong>
                        </div>
                    </button>
                </div>

                <div v-if="!displayedPayments.length && !loading" class="card-pad">
                    <div class="empty-state">No payments found. Try changing filters or create a payment link.</div>
                </div>

                <div class="card-pad page-actions">
                    <button class="btn btn-secondary" type="button" :disabled="meta.current_page <= 1 || loading" @click="changePage(meta.current_page - 1)">Prev</button>
                    <span class="card-subtitle">
                        Page {{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }} total · {{ meta.per_page }} per page
                    </span>
                    <button class="btn btn-secondary" type="button" :disabled="meta.current_page >= meta.last_page || loading" @click="changePage(meta.current_page + 1)">Next</button>
                </div>
            </article>

            <button
                class="workspace-resizer"
                type="button"
                aria-label="Resize payment details panel"
                @pointerdown="startResizeDetails"
            ></button>

            <PaymentDrawer :payment="selectedPayment" :back-query="detailBackQuery" @copy-link="copyHostedLink" />
        </section>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { merchantApi } from '../../services/merchantApi';
import { useCopy } from '../../composables/useCopy';
import AssetBadge from '../../components/payments/AssetBadge.vue';
import AssetLogo from '../../components/payments/AssetLogo.vue';
import PaymentDrawer from '../../components/payments/PaymentDrawer.vue';
import PaymentStatusBadge from '../../components/payments/PaymentStatusBadge.vue';
import { MERCHANT_ASSET_CATALOG } from '../../../utils/merchantAssetCatalog';

const route = useRoute();
const router = useRouter();
const { copy } = useCopy();
const loading = ref(false);
const error = ref('');
const notice = ref('');
const isSearching = ref(false);
const searchTimer = ref(null);
const isResizingDetails = ref(false);
const isDetailPinned = ref(false);
const detailPanelWidth = ref(400);
const detailPanelLeft = ref(0);
const paymentWorkspace = ref(null);
const listQueryKey = ref('');
const pendingScrollRestore = ref(null);
const payments = ref([]);
const selectedPayment = ref(null);
const meta = reactive({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
const summary = reactive({ total: 0, paid: 0, awaiting_asset: 0, pending: 0, confirming: 0, partial: 0, expired: 0 });
const filters = reactive({ search: '', status: '', assets: [], date_from: '', date_to: '', per_page: 15 });
const assetFilterOptions = MERCHANT_ASSET_CATALOG.filter((asset) => asset.assetKey);

const selectedId = computed(() => route.query.selected || '');
const activeView = computed(() => {
    if (filters.status) return filters.status;
    return route.query.view || 'all';
});
const statusViews = [
    { key: 'all', label: 'All', count: () => summary.total },
    { key: 'paid', label: 'Paid', count: () => summary.paid },
    { key: 'awaiting', label: 'Awaiting', count: () => summary.awaiting_asset + summary.pending },
    { key: 'partial', label: 'Partial', count: () => summary.partial },
    { key: 'expired', label: 'Expired', count: () => summary.expired },
];

const decoratedStatusViews = computed(() => statusViews.map((view) => ({
    ...view,
    countValue: view.count(),
})));

const displayedPayments = computed(() => payments.value);
const paymentWorkspaceStyle = computed(() => ({
    '--payment-detail-width': `${detailPanelWidth.value}px`,
    '--payment-detail-left': `${detailPanelLeft.value}px`,
}));
const detailBackQuery = computed(() => cleanQuery({
    ...route.query,
    selected: selectedId.value || undefined,
}));

const activeFilterChips = computed(() => {
    const chips = [];

    if (filters.search) chips.push({ key: 'search', label: 'Search', value: filters.search });
    if (filters.status) chips.push({ key: 'status', label: 'Status', value: statusLabel(filters.status) });
    filters.assets.forEach((assetKey) => {
        chips.push({ key: `asset:${assetKey}`, label: 'Asset', value: assetLabel(assetKey) });
    });
    if (filters.date_from) chips.push({ key: 'date_from', label: 'From', value: filters.date_from });
    if (filters.date_to) chips.push({ key: 'date_to', label: 'To', value: filters.date_to });
    if (filters.per_page !== 15) chips.push({ key: 'per_page', label: 'Page size', value: String(filters.per_page) });

    return chips;
});

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const statusLabel = (value) => ({
    awaiting: 'Awaiting payer',
    awaiting_asset: 'Awaiting asset',
    pending: 'Awaiting payment',
    partial: 'Partial',
    fixated: 'Confirming',
    paid: 'Paid',
    expired: 'Expired',
}[value] || value);
const assetLabel = (assetKey) => {
    const asset = assetFilterOptions.find((item) => item.assetKey === assetKey);
    return asset ? `${asset.assetLabel} (${asset.symbol})` : assetKey;
};
const normalizeAssetsFromQuery = (value) => {
    if (typeof value !== 'string') return [];

    const allowed = new Set(assetFilterOptions.map((asset) => asset.assetKey));
    return value
        .split(',')
        .map((item) => item.trim().toLowerCase())
        .filter((item, index, items) => item && allowed.has(item) && items.indexOf(item) === index);
};

const assetQueryValue = () => filters.assets.length ? filters.assets.join(',') : undefined;
const perPageFromQuery = (value) => {
    const parsed = Number.parseInt(String(value || ''), 10);
    return [15, 25, 50, 100].includes(parsed) ? parsed : 15;
};
const paymentsListQueryKey = (query) => JSON.stringify({
    search: query.search || '',
    status: query.status || '',
    coin: query.coin || '',
    date_from: query.date_from || '',
    date_to: query.date_to || '',
    per_page: query.per_page || '',
    page: query.page || '',
});

const syncFilters = () => {
    filters.search = typeof route.query.search === 'string' ? route.query.search : '';
    filters.status = typeof route.query.status === 'string' ? route.query.status : '';
    filters.assets = normalizeAssetsFromQuery(route.query.coin);
    filters.date_from = typeof route.query.date_from === 'string' ? route.query.date_from : '';
    filters.date_to = typeof route.query.date_to === 'string' ? route.query.date_to : '';
    filters.per_page = perPageFromQuery(route.query.per_page);
};

const loadPayments = async () => {
    loading.value = true;
    error.value = '';
    notice.value = '';

    try {
        const response = await merchantApi.payments({
            search: route.query.search || undefined,
            status: route.query.status || undefined,
            coin: route.query.coin || undefined,
            date_from: route.query.date_from || undefined,
            date_to: route.query.date_to || undefined,
            per_page: route.query.per_page || undefined,
            page: route.query.page || 1,
        });
        const payload = response.data?.data || {};
        payments.value = payload.data || [];
        Object.assign(meta, {
            current_page: payload.current_page || 1,
            last_page: payload.last_page || 1,
            per_page: payload.per_page || 15,
            total: payload.total || payments.value.length,
        });
        selectedPayment.value = payments.value.find((item) => String(item.id) === String(selectedId.value)) || null;
        if (selectedId.value && !selectedPayment.value) {
            await loadSelectedPayment(selectedId.value);
        }
    } catch {
        error.value = 'Failed to load payments.';
    } finally {
        loading.value = false;
    }
};

const loadSummary = async () => {
    try {
        const response = await merchantApi.paymentsSummary({
            search: route.query.search || undefined,
            coin: route.query.coin || undefined,
            date_from: route.query.date_from || undefined,
            date_to: route.query.date_to || undefined,
        });
        Object.assign(summary, {
            total: 0,
            paid: 0,
            awaiting_asset: 0,
            pending: 0,
            confirming: 0,
            partial: 0,
            expired: 0,
            ...(response.data?.data || {}),
        });
    } catch {
        Object.assign(summary, { total: 0, paid: 0, awaiting_asset: 0, pending: 0, confirming: 0, partial: 0, expired: 0 });
    }
};

const loadSelectedPayment = async (id) => {
    try {
        const response = await merchantApi.payment(id);
        selectedPayment.value = response.data?.data || null;
    } catch {
        selectedPayment.value = null;
    }
};

const applyFilters = () => router.push({
    name: 'merchant-v2.payments',
    query: cleanQuery({
        search: filters.search || undefined,
        status: filters.status || undefined,
        coin: assetQueryValue(),
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
        per_page: filters.per_page !== 15 ? filters.per_page : undefined,
        selected: undefined,
        page: 1,
    }),
});

const clearFilters = () => {
    if (searchTimer.value) {
        window.clearTimeout(searchTimer.value);
        searchTimer.value = null;
    }

    isSearching.value = false;
    router.push({ name: 'merchant-v2.payments' });
};

const removeFilter = (key) => {
    if (key.startsWith('asset:')) {
        filters.assets = filters.assets.filter((assetKey) => assetKey !== key.slice(6));
    } else if (key === 'per_page') {
        filters.per_page = 15;
    } else {
        filters[key] = '';
    }
    applyFilters();
};

const toggleAsset = (assetKey) => {
    filters.assets = filters.assets.includes(assetKey)
        ? filters.assets.filter((item) => item !== assetKey)
        : [...filters.assets, assetKey];
};

const setView = (view) => {
    const backendStatusViews = ['paid', 'awaiting', 'partial', 'expired'];
    const status = backendStatusViews.includes(view) ? view : '';
    filters.status = status;
    router.push({
        name: 'merchant-v2.payments',
        query: cleanQuery({
            ...route.query,
            status: status || undefined,
            view: view === 'all' ? undefined : view,
            selected: undefined,
            page: 1,
        }),
    });
};

const changePage = (page) => router.push({ name: 'merchant-v2.payments', query: { ...route.query, page } });
const selectPayment = async (payment) => {
    pendingScrollRestore.value = window.scrollY;
    selectedPayment.value = payment;

    await router.replace({ name: 'merchant-v2.payments', query: { ...route.query, selected: payment.id } });
};
const openFull = (id) => router.push({
    name: 'merchant-v2.payment-detail',
    params: { paymentId: id },
    query: cleanQuery({ ...route.query, selected: id }),
});
const copyHostedLink = async (url) => {
    if (await copy(url, 'Hosted link copied.')) notice.value = 'Hosted link copied.';
};

const DETAILS_WIDTH_STORAGE_KEY = 'merchant-v2.payment-detail-width';
const clampDetailWidth = (value) => Math.min(Math.max(value, 320), 560);
const updateDetailPanelPosition = () => {
    const workspaceRect = paymentWorkspace.value?.getBoundingClientRect();
    if (!workspaceRect) {
        isDetailPinned.value = false;
        return;
    }

    const pinOffset = 94;
    isDetailPinned.value = window.innerWidth >= 1200
        && workspaceRect.top <= pinOffset
        && workspaceRect.bottom > pinOffset + 240;
    detailPanelLeft.value = Math.max(16, workspaceRect.right - detailPanelWidth.value);
};
const stopResizeDetails = () => {
    if (!isResizingDetails.value) return;

    isResizingDetails.value = false;
    window.localStorage.setItem(DETAILS_WIDTH_STORAGE_KEY, String(detailPanelWidth.value));
    window.removeEventListener('pointermove', resizeDetails);
    window.removeEventListener('pointerup', stopResizeDetails);
};
const resizeDetails = (event) => {
    if (!isResizingDetails.value) return;

    const workspaceRect = paymentWorkspace.value?.getBoundingClientRect();
    const workspaceRight = workspaceRect?.right || window.innerWidth;
    const nextWidth = workspaceRight - event.clientX - 10;
    detailPanelWidth.value = clampDetailWidth(nextWidth);
    updateDetailPanelPosition();
};
const startResizeDetails = (event) => {
    if (window.innerWidth < 1200) return;

    event.preventDefault();
    isResizingDetails.value = true;
    window.addEventListener('pointermove', resizeDetails);
    window.addEventListener('pointerup', stopResizeDetails);
};

const cleanQuery = (query) => Object.fromEntries(
    Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== ''),
);

const pushFilterQuery = () => router.push({
    name: 'merchant-v2.payments',
    query: cleanQuery({
        ...route.query,
        search: filters.search || undefined,
        status: filters.status || undefined,
        coin: assetQueryValue(),
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
        per_page: filters.per_page !== 15 ? filters.per_page : undefined,
        view: undefined,
        selected: undefined,
        page: 1,
    }),
});

watch(() => filters.search, (value) => {
    if (value === (route.query.search || '')) return;

    if (searchTimer.value) {
        window.clearTimeout(searchTimer.value);
    }

    isSearching.value = true;
    searchTimer.value = window.setTimeout(() => {
        isSearching.value = false;
        pushFilterQuery();
    }, 450);
});

watch(
    () => [filters.status, filters.assets.join(','), filters.date_from, filters.date_to, filters.per_page],
    ([status, coin, dateFrom, dateTo, perPage]) => {
        if (
            status === (route.query.status || '')
            && coin === (route.query.coin || '')
            && dateFrom === (route.query.date_from || '')
            && dateTo === (route.query.date_to || '')
            && perPage === perPageFromQuery(route.query.per_page)
        ) {
            return;
        }

        pushFilterQuery();
    },
);

watch(() => route.query, async () => {
    syncFilters();
    const nextListQueryKey = paymentsListQueryKey(route.query);

    if (nextListQueryKey !== listQueryKey.value) {
        listQueryKey.value = nextListQueryKey;
        await Promise.all([loadPayments(), loadSummary()]);
    } else {
        selectedPayment.value = payments.value.find((item) => String(item.id) === String(selectedId.value)) || null;
        if (selectedId.value && !selectedPayment.value) {
            await loadSelectedPayment(selectedId.value);
        }
    }

    window.requestAnimationFrame(() => {
        updateDetailPanelPosition();

        if (pendingScrollRestore.value !== null) {
            window.scrollTo({ top: pendingScrollRestore.value, left: window.scrollX });
            pendingScrollRestore.value = null;
        }
    });
}, { immediate: true });

onMounted(() => {
    const storedWidth = Number.parseInt(window.localStorage.getItem(DETAILS_WIDTH_STORAGE_KEY) || '', 10);
    if (Number.isFinite(storedWidth)) {
        detailPanelWidth.value = clampDetailWidth(storedWidth);
    }
    updateDetailPanelPosition();
    window.addEventListener('resize', updateDetailPanelPosition);
    window.addEventListener('scroll', updateDetailPanelPosition, { passive: true });
});

onBeforeUnmount(() => {
    stopResizeDetails();
    window.removeEventListener('resize', updateDetailPanelPosition);
    window.removeEventListener('scroll', updateDetailPanelPosition);
    if (searchTimer.value) {
        window.clearTimeout(searchTimer.value);
    }
});
</script>
