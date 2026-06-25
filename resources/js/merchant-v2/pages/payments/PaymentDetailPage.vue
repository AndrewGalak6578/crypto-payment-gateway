<template>
    <section class="page-stack">
        <header class="page-header">
            <div>
                <p class="page-kicker">Payment detail</p>
                <h2 class="page-title">{{ payment.public_id || `Payment #${paymentId}` }}</h2>
                <p class="page-subtitle">{{ payment.external_id || 'No merchant reference' }}</p>
            </div>
            <div class="page-actions">
                <RouterLink class="btn btn-secondary" :to="{ name: 'merchant-v2.payments', query: backToPaymentsQuery }">Back to payments</RouterLink>
                <button class="btn btn-secondary" type="button" :disabled="refreshing" @click="refresh">{{ refreshing ? 'Refreshing...' : 'Refresh' }}</button>
                <button class="btn btn-primary" type="button" :disabled="!payment.hosted_url" @click="copy(payment.hosted_url, 'Hosted link copied.')">Copy checkout link</button>
            </div>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div v-if="copied" class="alert alert-success">{{ copied }}</div>

        <template v-if="!loading">
            <section class="detail-hero card card-pad">
                <div>
                    <PaymentStatusBadge :payment="payment" />
                    <p class="detail-amount">{{ payment.expected_usd || '—' }} USD</p>
                    <p class="card-subtitle">{{ displayAssetNetwork(payment) }}</p>
                </div>
                <div class="page-actions">
                    <a v-if="payment.hosted_url" class="btn btn-secondary" :href="payment.hosted_url" target="_blank" rel="noopener noreferrer">Open checkout</a>
                </div>
            </section>

            <section class="payment-detail-grid">
                <article class="card card-pad">
                    <h3 class="card-title">Lifecycle</h3>
                    <PaymentLifecycle :payment="payment" />
                </article>

                <article class="card card-pad">
                    <h3 class="card-title">Checkout and payer experience</h3>
                    <div class="detail-list">
                        <div class="detail-row">
                            <span class="detail-label">Hosted checkout</span>
                            <span class="detail-value">{{ payment.hosted_url || '—' }}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Pay address</span>
                            <span class="detail-value">{{ payment.pay_address || 'Allocated after asset selection' }}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Expires</span>
                            <span class="detail-value">{{ formatDate(payment.expires_at) }}</span>
                        </div>
                    </div>
                </article>

                <article class="card card-pad">
                    <h3 class="card-title">Merchant metadata</h3>
                    <pre class="metadata-box">{{ metadataPreview }}</pre>
                </article>

                <article class="card card-pad">
                    <h3 class="card-title">Technical details</h3>
                    <details>
                        <summary>Show raw fields</summary>
                        <pre class="metadata-box">{{ JSON.stringify(payment, null, 2) }}</pre>
                    </details>
                </article>
            </section>
        </template>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { merchantApi } from '../../services/merchantApi';
import { useCopy } from '../../composables/useCopy';
import { displayAssetNetwork } from '../../../utils/assetDisplay';
import PaymentLifecycle from '../../components/payments/PaymentLifecycle.vue';
import PaymentStatusBadge from '../../components/payments/PaymentStatusBadge.vue';

const props = defineProps({
    paymentId: {
        type: [String, Number],
        required: true,
    },
});

const { copied, copy } = useCopy();
const route = useRoute();
const loading = ref(true);
const refreshing = ref(false);
const error = ref('');
const payment = ref({});
const metadataPreview = computed(() => JSON.stringify(payment.value.metadata || {}, null, 2));
const backToPaymentsQuery = computed(() => ({
    ...route.query,
    selected: props.paymentId,
}));
const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        const response = await merchantApi.payment(props.paymentId);
        payment.value = response.data?.data || {};
    } catch {
        error.value = 'Failed to load payment.';
    } finally {
        loading.value = false;
    }
};

const refresh = async () => {
    refreshing.value = true;
    error.value = '';
    try {
        const response = await merchantApi.refreshPayment(props.paymentId);
        payment.value = response.data?.data || {};
    } catch {
        error.value = 'Failed to refresh payment.';
    } finally {
        refreshing.value = false;
    }
};

watch(() => props.paymentId, load, { immediate: true });
</script>

<style scoped>
.detail-hero {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    flex-wrap: wrap;
}

.detail-amount {
    margin: 12px 0 0;
    font-size: 32px;
    line-height: 1;
    font-weight: 800;
}

.payment-detail-grid {
    display: grid;
    gap: 16px;
}

.metadata-box {
    max-height: 360px;
    overflow: auto;
    margin: 12px 0 0;
    padding: 12px;
    border: 1px solid var(--m-border);
    border-radius: var(--m-radius-lg);
    background: var(--m-surface-subtle);
    color: var(--m-text);
    font-size: 12px;
}

@media (min-width: 1100px) {
    .payment-detail-grid {
        grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr);
    }
}
</style>
