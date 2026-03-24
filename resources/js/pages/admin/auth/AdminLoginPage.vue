<template>
    <section class="login-page">
        <form class="form" @submit.prevent="handleSubmit">
            <div class="field">
                <label for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    required
                    placeholder="admin@example.com"
                >
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    placeholder="Enter password"
                >
            </div>

            <p v-if="errorMessage" class="error-message">
                {{ errorMessage }}
            </p>

            <button type="submit" class="submit-btn" :disabled="adminAuthStore.loading">
                {{ adminAuthStore.loading ? 'Signing in...' : 'Sign in' }}
            </button>
        </form>
    </section>
</template>

<script setup>
import { reactive, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAdminAuthStore } from '../../../stores/adminAuth';

const router = useRouter();
const route = useRoute();
const adminAuthStore = useAdminAuthStore();

const form = reactive({
    email: '',
    password: '',
});

const errorMessage = computed(() => adminAuthStore.error);

const handleSubmit = async () => {
    try {
        await adminAuthStore.login(form.email, form.password);

        const redirectTo = typeof route.query.redirect === 'string'
            ? route.query.redirect
            : '/admin';

        await router.push(redirectTo);
    } catch (_) {
    }
};
</script>

<style scoped>
.form {
    display: grid;
    gap: 16px;
}

.field {
    display: grid;
    gap: 6px;
}

label {
    font-size: 14px;
    color: #334155;
    font-weight: 600;
}

input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 14px;
    color: #0f172a;
    background: #fff;
    outline: none;
}

input:focus {
    border-color: #64748b;
}

.error-message {
    margin: 0;
    color: #b91c1c;
    font-size: 14px;
}

.submit-btn {
    border: 0;
    border-radius: 10px;
    padding: 12px 14px;
    background: #0f172a;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.submit-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}
</style>
