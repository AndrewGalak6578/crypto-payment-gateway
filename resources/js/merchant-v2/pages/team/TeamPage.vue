<template>
    <section class="page-stack">
        <header class="page-header">
            <div>
                <p class="page-kicker">Access</p>
                <h2 class="page-title">Team</h2>
                <p class="page-subtitle">Merchant users and role-based access in one place.</p>
            </div>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <article class="card">
            <div class="table-scroll">
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users" :key="user.id">
                            <td>
                                <div class="payment-primary">
                                    <span class="payment-id">{{ user.name || user.email }}</span>
                                    <span class="payment-meta">{{ user.email }}</span>
                                </div>
                            </td>
                            <td>{{ user.role || '—' }}</td>
                            <td><span class="status-badge" :class="user.status === 'active' ? 'status-success' : 'status-neutral'">{{ user.status || '—' }}</span></td>
                            <td>{{ formatDate(user.created_at) }}</td>
                        </tr>
                        <tr v-if="!users.length">
                            <td colspan="4"><div class="empty-state">No team users found.</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { merchantApi } from '../../services/merchantApi';

const error = ref('');
const users = ref([]);
const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');

onMounted(async () => {
    try {
        const response = await merchantApi.users();
        const payload = response.data?.data;
        users.value = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : [];
    } catch {
        error.value = 'Failed to load team users.';
    }
});
</script>
