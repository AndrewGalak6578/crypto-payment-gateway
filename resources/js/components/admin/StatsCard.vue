<template>
    <article class="stats-card">
        <p class="label">{{ label }}</p>
        <p class="value">{{ value }}</p>
        <p v-if="hint" class="hint">{{ hint }}</p>
    </article>
</template>

<script setup>
defineProps({
    label: {
        type: String,
        required: true,
    },
    value: {
        type: [String, Number],
        default: '—',
    },
    hint: {
        type: String,
        default: '',
    },
});
</script>

<style scoped>
.stats-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px;
}

.label {
    margin: 0;
    font-size: 13px;
    color: #64748b;
}

.value {
    margin: 8px 0 0;
    font-size: 28px;
    font-weight: 700;
    color: #0f172a;
}

.hint {
    margin: 6px 0 0;
    font-size: 12px;
    color: #94a3b8;
}
</style>
