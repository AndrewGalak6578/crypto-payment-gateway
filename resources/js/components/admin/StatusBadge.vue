<template>
    <span class="status-badge" :class="variantClass">{{ text || '—' }}</span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    text: {
        type: String,
        default: '',
    },
    variant: {
        type: String,
        default: 'muted',
    },
});

const variantClass = computed(() => `status-badge-${props.variant}`);
</script>

<style scoped>
.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border-radius: 999px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 9px;
    font-size: 11px;
    text-transform: capitalize;
    white-space: nowrap;
}

.status-badge-success {
    background: #dcfce7;
    color: #166534;
}

.status-badge-danger {
    background: #fef2f2;
    color: #b91c1c;
}

.status-badge-warning {
    background: #fef3c7;
    color: #92400e;
}

.status-badge-muted {
    background: #e2e8f0;
    color: #475569;
}

.status-badge-info {
    background: #dbeafe;
    color: #1d4ed8;
}
</style>
