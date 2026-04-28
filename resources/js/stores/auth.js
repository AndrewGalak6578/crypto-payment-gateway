import { defineStore } from 'pinia';
import api from '../api/axios';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        merchant: null,
        role: null,
        capabilities: [],
        isAuthenticated: false,
        loading: false,
        initialized: false,
    }),

    actions: {
        setAuthPayload(payload) {
            const user = payload?.user ?? null;

            this.user = user;
            this.merchant = payload?.merchant ?? null;
            this.role = user?.role ?? null;
            this.capabilities = Array.isArray(user?.capabilities) ? user.capabilities : [];
            this.isAuthenticated = Boolean(this.user && this.merchant);
        },

        hasCapability(code) {
            return this.capabilities.includes(code);
        },

        clearAuth() {
            this.user = null;
            this.merchant = null;
            this.role = null;
            this.capabilities = [];
            this.isAuthenticated = false;
        },

        async login(email, password) {
            this.loading = true;

            try {
                await api.get('/sanctum/csrf-cookie');
                await api.post('/api/auth/merchant/login', { email, password });
                await this.fetchMe();
                return true;
            } finally {
                this.loading = false;
            }
        },

        async registerMerchant(payload) {
            this.loading = true;

            try {
                await api.get('/sanctum/csrf-cookie');
                const response = await api.post('/api/auth/merchant/register', payload);
                this.setAuthPayload(response.data?.data);
                this.initialized = true;
                return response.data?.data ?? null;
            } finally {
                this.loading = false;
            }
        },

        async fetchMe() {
            const response = await api.get('/api/auth/merchant/me');
            this.setAuthPayload(response.data?.data);
            this.initialized = true;
            return response.data?.data ?? null;
        },

        async logout() {
            this.loading = true;

            try {
                await api.post('/api/auth/merchant/logout');
            } finally {
                this.clearAuth();
                this.loading = false;
                this.initialized = true;
            }
        },

        async bootstrapAuth() {
            if (this.initialized) {
                return;
            }

            this.loading = true;

            try {
                await this.fetchMe();
            } catch (error) {
                if (error?.response?.status === 401) {
                    this.clearAuth();
                    this.initialized = true;
                    return;
                }

                this.clearAuth();
                this.initialized = true;
            } finally {
                this.loading = false;
            }
        },
    },
});
