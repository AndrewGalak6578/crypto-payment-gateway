<template>
    <aside class="card card-pad selection-panel">
        <div v-if="!payment" class="empty-state">
            <strong>Select a payment</strong>
            <p class="card-subtitle">Choose a payment from the list to view details, timeline, and delivery activity.</p>
        </div>

        <div v-else class="drawer-stack">
            <header class="drawer-hero">
                <div>
                    <p class="page-kicker">Payment</p>
                    <h3 class="drawer-payment-id">{{ payment.public_id || `#${payment.id}` }}</h3>
                    <p class="card-subtitle">{{ payment.external_id || 'No merchant reference' }}</p>
                </div>
                <PaymentStatusBadge :payment="payment" />
            </header>

            <section class="drawer-amount-card">
                <AssetLogo class="drawer-asset-logo" :item="payment" />
                <div>
                    <span>Amount</span>
                    <strong>{{ payment.expected_usd || '—' }} USD</strong>
                    <small>{{ displayAssetNetwork(payment) }}</small>
                </div>
            </section>

            <section class="next-action" :class="`next-action-${nextAction.tone}`">
                <strong>{{ nextAction.title }}</strong>
                <p>{{ nextAction.body }}</p>
            </section>

            <div class="detail-list">
                <div class="detail-row">
                    <span class="detail-label">Received</span>
                    <span class="detail-value">{{ payment.received_all_coin || '0' }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Hosted checkout</span>
                    <span class="detail-value">{{ payment.hosted_url || 'Not available' }}</span>
                </div>
            </div>

            <PaymentLifecycle :payment="payment" />

            <div class="page-actions">
                <RouterLink
                    class="btn btn-primary"
                    :to="{ name: 'merchant-v2.payment-detail', params: { paymentId: payment.id }, query: backQuery }"
                >
                    Open full details
                </RouterLink>
                <button class="btn btn-secondary" type="button" :disabled="!payment.hosted_url" @click="$emit('copy-link', payment.hosted_url)">
                    Copy link
                </button>
                <a v-if="payment.hosted_url" class="btn btn-ghost" :href="payment.hosted_url" target="_blank" rel="noopener noreferrer">
                    Open checkout
                </a>
            </div>
        </div>
    </aside>
</template>

<script setup>
import { computed } from 'vue';
import { displayAssetNetwork } from '../../../utils/assetDisplay';
import AssetLogo from './AssetLogo.vue';
import PaymentLifecycle from './PaymentLifecycle.vue';
import PaymentStatusBadge from './PaymentStatusBadge.vue';
import { paymentNextAction } from './paymentStatus';

const props = defineProps({
    payment: {
        type: Object,
        default: null,
    },
    backQuery: {
        type: Object,
        default: () => ({}),
    },
});

defineEmits(['copy-link']);

const nextAction = computed(() => paymentNextAction(props.payment || {}));
</script>
