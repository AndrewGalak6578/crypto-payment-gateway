import { defineStore } from 'pinia';
import api from '../api/axios';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        merchant: null,
        isAuthenticated: false,
        loading: false,
        initialized: false,
    }),

    actions: {
        setAuthPayload(payload) {
            this.user = payload?.user ?? null;
            this.merchant = payload?.merchant ?? null;
            this.isAuthenticated = Boolean(this.user && this.merchant);
        },

        clearAuth() {
            this.user = null;
            this.merchant = null;
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
