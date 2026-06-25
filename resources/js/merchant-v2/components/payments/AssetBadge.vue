<template>
    <span class="asset-badge">
        <AssetLogo :item="item" />
        <span>{{ label }}</span>
    </span>
</template>

<script setup>
import { computed } from 'vue';
import { displayAssetLabel, displayAssetKey } from '../../../utils/assetDisplay';
import AssetLogo from './AssetLogo.vue';

const props = defineProps({
    item: {
        type: Object,
        default: () => ({}),
    },
});

const label = computed(() => {
    const asset = displayAssetLabel(props.item);
    const key = displayAssetKey(props.item);
    return key && key !== '—' ? `${asset} · ${key}` : asset;
});
</script>
