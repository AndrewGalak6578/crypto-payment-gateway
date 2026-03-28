<template>
    <div class="state-card">
        <p class="title">{{ title }}</p>
        <p v-if="description" class="description">{{ description }}</p>
    </div>
</template>

<script setup>
defineProps({
    title: {
        type: String,
        default: 'No data found.',
    },
    description: {
        type: String,
        default: '',
    },
});
</script>

<style scoped>
.state-card {
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    padding: 20px;
    background: #fff;
}

.title {
    margin: 0;
    color: #334155;
    font-weight: 600;
    font-size: 15px;
}

.description {
    margin: 6px 0 0;
    color: #64748b;
    font-size: 14px;
    line-height: 1.45;
}
</style>
