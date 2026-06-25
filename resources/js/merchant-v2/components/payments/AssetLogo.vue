<template>
    <span class="asset-logo" :class="`asset-logo-${logoKey}`" aria-hidden="true">
        <svg v-if="logoKey === 'btc'" viewBox="0 0 24 24" role="img">
            <path d="M13.4 4.2v2.1c2 .4 3.2 1.4 3 3-.1 1.1-.8 1.8-1.8 2.2 1.4.4 2.2 1.3 2.1 2.8-.2 2-1.9 3.1-4.4 3.3v2.2h-1.4v-2.2H9.8v2.2H8.4v-2.2H5.9l.3-1.7h1.2V8.1H6.2V6.4h2.2V4.2h1.4v2.2h1.1V4.2h2.5Zm-3.6 7h2.5c1 0 1.7-.4 1.8-1.4.1-1-.7-1.5-1.9-1.5H9.8v2.9Zm0 4.5h2.7c1.2 0 1.9-.5 2-1.6.1-1-.7-1.5-2-1.5H9.8v3.1Z" />
        </svg>
        <svg v-else-if="logoKey === 'ltc'" viewBox="0 0 24 24" role="img">
            <path d="M14.4 4.7 12.5 12l2.1-.8-.5 2-2.1.8-.6 2.5h5.6l-.6 2.5H7.7l.9-3.6-1.9.7.5-2 1.9-.7 2.2-8.7h3.1Z" />
        </svg>
        <svg v-else-if="logoKey === 'dash'" viewBox="0 0 24 24" role="img">
            <path d="M6.7 7.2h7.2c2.5 0 4.1 1.5 3.7 3.7-.5 3.2-2.6 5.9-6.2 5.9H4.2l.6-2.5h6.7c1.5 0 2.4-1.2 2.7-2.8.2-1.1-.4-1.7-1.6-1.7H6.1l.6-2.6Zm-1.2 3.5h5.4l-.5 2.2H5l.5-2.2Z" />
        </svg>
        <svg v-else-if="logoKey === 'eth'" viewBox="0 0 24 24" role="img">
            <path d="M12 3 6.8 12l5.2 3 5.2-3L12 3Zm0 18-5.2-7.4 5.2 3 5.2-3L12 21Z" />
        </svg>
        <svg v-else-if="logoKey === 'usdt'" viewBox="0 0 24 24" role="img">
            <path d="M5.1 5.5h13.8v3.1h-5.1v1.7c3 .2 5.2.9 5.2 1.8s-2.2 1.6-5.2 1.8v4.6h-3.6v-4.6c-3-.2-5.2-.9-5.2-1.8s2.2-1.6 5.2-1.8V8.6H5.1V5.5Zm5.1 6.3c-1.8.1-3 .3-3 .6s1.2.5 3 .6v-1.2Zm3.6 1.2c1.8-.1 3-.3 3-.6s-1.2-.5-3-.6V13Z" />
        </svg>
        <span v-else>{{ fallback }}</span>
    </span>
</template>

<style scoped>

</style>
<script setup>
import { computed } from 'vue';
import { displayAssetKey } from '../../../utils/assetDisplay';

const props = defineProps({
    item: {
        type: Object,
        default: () => ({}),
    },
});

const logoKey = computed(() => {
    const key = displayAssetKey(props.item).toLowerCase();
    if (key.includes('usdt')) return 'usdt';
    if (key.includes('eth')) return 'eth';
    if (key.includes('dash')) return 'dash';
    if (key.includes('ltc')) return 'ltc';
    if (key.includes('btc')) return 'btc';
    return 'default';
});

const fallback = computed(() => {
    const key = displayAssetKey(props.item);
    return key && key !== '—' ? key.slice(0, 2).toUpperCase() : '?';
});
</script>
