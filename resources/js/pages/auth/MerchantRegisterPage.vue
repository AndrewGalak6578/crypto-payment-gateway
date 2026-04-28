<template>
  <div>
    <h2 class="title">Register Merchant</h2>
    <p class="subtitle">Start with a base platform fee of 2.0% and create the first owner account for Merchant Portal access.</p>

    <div class="notice">
      <strong>Base fee:</strong> 2.0%
    </div>

    <form class="form" @submit.prevent="submit">
      <label class="label" for="merchant_name">Merchant name</label>
      <input id="merchant_name" v-model.trim="form.merchant_name" class="input" type="text" autocomplete="organization" required />

      <label class="label" for="owner_name">Owner name</label>
      <input id="owner_name" v-model.trim="form.owner_name" class="input" type="text" autocomplete="name" required />

      <label class="label" for="email">Owner email</label>
      <input id="email" v-model.trim="form.email" class="input" type="email" autocomplete="email" required />

      <label class="label" for="password">Password</label>
      <input id="password" v-model="form.password" class="input" type="password" autocomplete="new-password" required />

      <label class="label" for="password_confirmation">Confirm password</label>
      <input
        id="password_confirmation"
        v-model="form.password_confirmation"
        class="input"
        type="password"
        autocomplete="new-password"
        required
      />

      <p v-if="error" class="error">{{ error }}</p>

      <button class="submit" type="submit" :disabled="authStore.loading">
        {{ authStore.loading ? 'Registering...' : 'Create merchant and owner account' }}
      </button>
    </form>

    <RouterLink class="secondary-link" :to="{ name: 'merchant.login' }">
      Back to login
    </RouterLink>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';

const authStore = useAuthStore();
const router = useRouter();

const form = reactive({
  merchant_name: '',
  owner_name: '',
  email: '',
  password: '',
  password_confirmation: '',
});

const error = ref('');

const submit = async () => {
  error.value = '';

  try {
    await authStore.registerMerchant({ ...form });
    await router.push('/merchant');
  } catch (requestError) {
    const response = requestError?.response;

    if (response?.data?.message) {
      error.value = response.data.message;
      return;
    }

    if (response?.data?.errors) {
      const firstError = Object.values(response.data.errors).flat()[0];
      error.value = firstError || 'Registration failed.';
      return;
    }

    error.value = 'Registration failed. Please try again.';
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
  margin: 8px 0 16px;
  color: #475569;
  font-size: 14px;
}

.notice {
  margin-bottom: 18px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 12px;
  background: #f8fafc;
  color: #0f172a;
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

.secondary-link {
  display: inline-block;
  margin-top: 16px;
  color: #334155;
  text-decoration: none;
}
</style>
