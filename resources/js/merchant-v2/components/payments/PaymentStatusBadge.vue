<template>
    <span class="status-badge" :class="`status-${meta.tone}`">{{ meta.label }}</span>
</template>

<script setup>
import { computed } from 'vue';
import { paymentStatusMeta } from './paymentStatus';

const props = defineProps({
    payment: {
        type: Object,
        default: () => ({}),
    },
});

const meta = computed(() => paymentStatusMeta(props.payment));
</script>
