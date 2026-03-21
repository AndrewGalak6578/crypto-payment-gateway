<template>
  <div>
    <h2 class="title">Merchant Login</h2>
    <p class="subtitle">Sign in to access your dashboard and invoices.</p>

    <form class="form" @submit.prevent="submit">
      <label class="label" for="email">Email</label>
      <input id="email" v-model="form.email" class="input" type="email" autocomplete="email" required />

      <label class="label" for="password">Password</label>
      <input id="password" v-model="form.password" class="input" type="password" autocomplete="current-password" required />

      <p v-if="error" class="error">{{ error }}</p>

      <button class="submit" type="submit" :disabled="authStore.loading">
        {{ authStore.loading ? 'Signing in...' : 'Sign in' }}
      </button>
    </form>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';

const authStore = useAuthStore();
const route = useRoute();
const router = useRouter();

const form = reactive({
  email: '',
  password: '',
});

const error = ref('');

const submit = async () => {
  error.value = '';

  try {
    await authStore.login(form.email, form.password);
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/merchant';
    await router.push(redirect);
  } catch (requestError) {
    const response = requestError?.response;
    if (response?.data?.message) {
      error.value = response.data.message;
      return;
    }

    if (response?.data?.errors) {
      const firstError = Object.values(response.data.errors).flat()[0];
      error.value = firstError || 'Login failed.';
      return;
    }

    error.value = 'Login failed. Please try again.';
  }
};
</script>

<style scoped>
.title {
  margin: 0;
  font-size: 24px;
  color: #0f172a;
}

.subtitle {
  margin: 8px 0 20px;
  color: #475569;
  font-size: 14px;
}

.form {
  display: grid;
  gap: 10px;
}

.label {
  font-size: 14px;
  color: #334155;
}

.input {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 10px 12px;
}

.error {
  margin: 4px 0;
  color: #b91c1c;
  font-size: 14px;
}

.submit {
  margin-top: 8px;
  border: 0;
  border-radius: 8px;
  padding: 11px 14px;
  background: #0f172a;
  color: #fff;
  cursor: pointer;
}

.submit:disabled {
  opacity: 0.75;
  cursor: not-allowed;
}
</style>
