<template>
    <section class="page-stack">
        <header class="page-header">
            <div>
                <p class="page-kicker">Funds</p>
                <h2 class="page-title">Settlements</h2>
                <p class="page-subtitle">Balances and forwarding wallets grouped as the money movement workspace.</p>
            </div>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <section class="dashboard-grid">
            <article class="card card-pad">
                <h3 class="card-title">Balances</h3>
                <div class="detail-list">
                    <div v-for="(balance, index) in balances" :key="`${balance.asset_key || balance.coin}:${index}`" class="detail-row">
                        <span class="detail-label">{{ displayAssetNetwork(balance) }}</span>
                        <span class="detail-value">{{ balance.amount }}</span>
                    </div>
                    <div v-if="!balances.length" class="detail-row">
                        <span class="detail-value">No balances yet.</span>
                    </div>
                </div>
            </article>

            <article class="card card-pad">
                <h3 class="card-title">Forwarding wallets</h3>
                <div class="detail-list">
                    <div v-for="wallet in wallets" :key="wallet.id" class="detail-row">
                        <span class="detail-label">{{ displayAssetNetwork(wallet) }}</span>
                        <span class="detail-value">{{ wallet.wallet }}</span>
                    </div>
                    <div v-if="!wallets.length" class="detail-row">
                        <span class="detail-value">No settlement wallets configured.</span>
                    </div>
                </div>
            </article>
        </section>
    </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { merchantApi } from '../../services/merchantApi';
import { displayAssetNetwork } from '../../../utils/assetDisplay';

const error = ref('');
const balances = ref([]);
const wallets = ref([]);

onMounted(async () => {
    try {
        const [balancesResponse, walletsResponse] = await Promise.all([merchantApi.balances(), merchantApi.wallets()]);
        balances.value = balancesResponse.data?.data || [];
        wallets.value = walletsResponse.data?.data || [];
    } catch {
        error.value = 'Failed to load settlement resources.';
    }
});
</script>

<style scoped>
.dashboard-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
}
</style>
