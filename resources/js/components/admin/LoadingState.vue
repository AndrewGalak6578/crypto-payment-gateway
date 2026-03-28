<template>
    <div class="loading-state">
        <span class="dot" />
        <span>{{ text }}</span>
    </div>
</template>

<script setup>
defineProps({
    text: {
        type: String,
        default: 'Loading...',
    },
});
</script>

<style scoped>
.loading-state {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 16px;
    border-radius: 14px;
    border: 1px dashed #cbd5e1;
    background: #fff;
    color: #475569;
    font-size: 14px;
}

.dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: #0f172a;
    animation: pulse 1s infinite ease-in-out;
}

@keyframes pulse {
    0%,
    100% {
        opacity: 0.4;
        transform: scale(0.9);
    }

    50% {
        opacity: 1;
        transform: scale(1.1);
    }
}
</style>
