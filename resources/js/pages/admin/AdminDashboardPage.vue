<template>
    <section>
        <PageHeader
            title="Dashboard"
            subtitle="Operational snapshot across merchants, invoices and webhook processing."
        >
            <template #actions>
                <button type="button" class="primary-btn" :disabled="loading" @click="loadDashboard">
                    {{ loading ? 'Refreshing...' : 'Refresh' }}
                </button>
            </template>
        </PageHeader>

        <LoadingState v-if="loading" text="Loading dashboard metrics..." />

        <div v-else-if="error" class="state-card">
            <p class="error">{{ error }}</p>
            <button type="button" class="secondary-btn" @click="loadDashboard">Retry</button>
        </div>

        <div v-else class="stats-grid">
            <StatsCard label="Merchants total" :value="stats.merchants_total" />
            <StatsCard label="Active merchants" :value="stats.merchants_active" />
            <StatsCard label="Suspended merchants" :value="stats.merchants_disabled" hint="Backend status: disabled" />
            <StatsCard label="Invoices total" :value="stats.invoices_total" />
            <StatsCard label="Failed webhook deliveries" :value="stats.failed_webhook_deliveries" />
        </div>
    </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { extractApiErrorMessage, getAdminDashboard } from '../../api/admin';
import LoadingState from '../../components/admin/LoadingState.vue';
import PageHeader from '../../components/admin/PageHeader.vue';
import StatsCard from '../../components/admin/StatsCard.vue';

const loading = ref(true);
const error = ref('');
const stats = ref({
    merchants_total: 0,
    merchants_active: 0,
    merchants_disabled: 0,
    invoices_total: 0,
    failed_webhook_deliveries: 0,
});

const loadDashboard = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await getAdminDashboard();
        const nextStats = response.data?.data?.stats || {};

        stats.value = {
            merchants_total: nextStats.merchants_total ?? 0,
            merchants_active: nextStats.merchants_active ?? 0,
            merchants_disabled: nextStats.merchants_disabled ?? 0,
            invoices_total: nextStats.invoices_total ?? 0,
            failed_webhook_deliveries: nextStats.failed_webhook_deliveries ?? 0,
        };
    } catch (requestError) {
        error.value = extractApiErrorMessage(requestError, 'Failed to load dashboard metrics.');
    } finally {
        loading.value = false;
    }
};

onMounted(loadDashboard);
</script>

<style scoped>
.stats-grid {
    display: grid;
    gap: 12px;
}

.state-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
}

.primary-btn,
.secondary-btn {
    border-radius: 8px;
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
    font: inherit;
    cursor: pointer;
}

.primary-btn {
    background: #0f172a;
    border-color: #0f172a;
    color: #fff;
}

.error {
    color: #b91c1c;
    margin: 0 0 12px;
}

@media (min-width: 992px) {
    .stats-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
</style>
