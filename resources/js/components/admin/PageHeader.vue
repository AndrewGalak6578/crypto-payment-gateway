<template>
    <header class="page-header">
        <div>
            <h1 class="page-title">{{ title }}</h1>
            <p v-if="subtitle" class="page-subtitle">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="page-actions">
            <slot name="actions" />
        </div>
    </header>
</template>

<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: '',
    },
});
</script>

<style scoped>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.page-header > :first-child {
    min-width: 0;
}

.page-title {
    margin: 0;
    color: #0f172a;
    font-size: 24px;
    line-height: 1.2;
}

.page-subtitle {
    margin: 7px 0 0;
    color: #64748b;
    font-size: 14px;
}

.page-actions {
    display: inline-flex;
    gap: 8px;
    flex-wrap: wrap;
}

@media (max-width: 640px) {
    .page-title {
        font-size: 22px;
    }

    .page-actions {
        width: 100%;
    }
}
</style>
