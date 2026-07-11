<template>
    <div class="lifecycle">
        <div
            v-for="step in steps"
            :key="step.key"
            class="lifecycle-step"
            :class="{ 'is-done': step.done, 'is-active': step.active, 'is-danger': step.danger }"
        >
            <span class="lifecycle-dot" aria-hidden="true"></span>
            <div>
                <strong>{{ step.label }}</strong>
                <p class="card-subtitle">{{ step.description }}</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { normalizePaymentStatus } from './paymentStatus';

const props = defineProps({
    payment: {
        type: Object,
        default: () => ({}),
    },
});

const order = ['created', 'awaiting_asset', 'pending', 'partial', 'confirming', 'paid'];

const steps = computed(() => {
    const status = normalizePaymentStatus(props.payment);
    const currentIndex = order.includes(status) ? order.indexOf(status) : 2;
    const expired = status === 'expired';

    return [
        {
            key: 'created',
            label: 'Payment created',
            description: props.payment.created_at ? new Date(props.payment.created_at).toLocaleString() : 'Invoice exists.',
            done: true,
            active: false,
        },
        {
            key: 'awaiting_asset',
            label: 'Asset selection',
            description: props.payment.asset_key ? 'Payer asset is selected.' : 'Waiting for payer to choose a payment asset.',
            done: Boolean(props.payment.asset_key) || currentIndex > 1 || expired,
            active: status === 'awaiting_asset',
        },
        {
            key: 'pending',
            label: 'Awaiting payment',
            description: props.payment.pay_address ? 'Hosted checkout is showing payment instructions.' : 'Payment details are not allocated yet.',
            done: currentIndex > 2 || expired,
            active: status === 'pending',
        },
        {
            key: 'partial',
            label: 'Partial or confirming',
            description: status === 'partial' ? 'Payment is short. Payer needs to send the remainder.' : 'Received payment is waiting for confirmation.',
            done: currentIndex > 3,
            active: status === 'partial' || status === 'confirming',
        },
        {
            key: 'terminal',
            label: expired ? 'Expired' : 'Paid',
            description: expired ? 'This checkout can no longer be paid.' : 'Payment completed successfully.',
            done: status === 'paid',
            active: expired,
            danger: expired,
        },
    ];
});
</script>
