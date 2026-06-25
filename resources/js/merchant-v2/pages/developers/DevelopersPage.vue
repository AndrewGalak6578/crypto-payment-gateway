<template>
    <section class="page-stack">
        <header class="page-header">
            <div>
                <p class="page-kicker">Integration</p>
                <h2 class="page-title">Developers</h2>
                <p class="page-subtitle">API keys, webhook endpoint health, and recent delivery activity.</p>
            </div>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <section class="dashboard-grid">
            <article class="card card-pad">
                <h3 class="card-title">API keys</h3>
                <p class="card-subtitle">{{ activeKeys }} active keys</p>
                <div class="detail-list">
                    <div v-for="key in apiKeys" :key="key.id" class="detail-row">
                        <span class="detail-label">{{ key.name || `Key #${key.id}` }}</span>
                        <span class="detail-value">{{ key.revoked_at ? 'Revoked' : 'Active' }}</span>
                    </div>
                    <div v-if="!apiKeys.length" class="detail-row">
                        <span class="detail-value">No API keys yet.</span>
                    </div>
                </div>
            </article>

            <article class="card card-pad">
                <h3 class="card-title">Webhook endpoint</h3>
                <p class="card-subtitle">Delivery target for payment lifecycle events.</p>
                <div class="detail-list">
                    <div class="detail-row">
                        <span class="detail-label">URL</span>
                        <span class="detail-value">{{ webhookSettings.webhook_url || 'Not configured' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Secret</span>
                        <span class="detail-value">{{ webhookSettings.has_webhook_secret ? 'Configured' : 'Missing' }}</span>
                    </div>
                </div>
            </article>
        </section>

        <article class="card">
            <div class="card-pad">
                <h3 class="card-title">Recent webhook deliveries</h3>
                <p class="card-subtitle">Latest integration delivery attempts.</p>
            </div>
            <div class="table-scroll">
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Delivery</th>
                            <th>Status</th>
                            <th>Event</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="delivery in deliveries" :key="delivery.id">
                            <td>#{{ delivery.id }}</td>
                            <td><span class="status-badge" :class="delivery.status === 'delivered' ? 'status-success' : delivery.status === 'failed' ? 'status-danger' : 'status-info'">{{ delivery.status }}</span></td>
                            <td>{{ delivery.event_type || delivery.invoice_public_id || '—' }}</td>
                            <td>{{ formatDate(delivery.created_at) }}</td>
                        </tr>
                        <tr v-if="!deliveries.length">
                            <td colspan="4"><div class="empty-state">No webhook deliveries yet.</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { merchantApi } from '../../services/merchantApi';

const error = ref('');
const apiKeys = ref([]);
const webhookSettings = ref({});
const deliveries = ref([]);
const activeKeys = computed(() => apiKeys.value.filter((item) => !item.revoked_at).length);
const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');

onMounted(async () => {
    try {
        const [keysResponse, settingsResponse, deliveriesResponse] = await Promise.all([
            merchantApi.apiKeys(),
            merchantApi.webhookSettings(),
            merchantApi.webhookDeliveries({ per_page: 20 }),
        ]);
        apiKeys.value = keysResponse.data?.data || [];
        webhookSettings.value = settingsResponse.data?.data || {};
        const payload = deliveriesResponse.data?.data;
        deliveries.value = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : [];
    } catch {
        error.value = 'Failed to load developer resources.';
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
