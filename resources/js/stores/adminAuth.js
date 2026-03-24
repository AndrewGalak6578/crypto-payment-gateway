import { defineStore } from 'pinia';
import api from '../api/axios';

export const useAdminAuthStore = defineStore('adminAuth', {
    state: () => ({
        admin: null,
        loading: false,
        initialized: false,
        error: null,
    }),

    getters: {
        isAuthenticated: (state) => Boolean(state.admin),
    },

    actions: {
        setAdminPayload(payload) {
            this.admin = payload?.admin ?? payload?.user ?? payload ?? null;
        },

        clearAuth() {
            this.admin = null;
            this.error = null;
        },

        async login(email, password) {
            this.loading = true;
            this.error = null;

            try {
                await api.get('/sanctum/csrf-cookie');
                await api.post('/api/auth/admin/login', { email, password });
                await this.fetchMe();
                return true;
            } catch (error) {
                this.error = error?.response?.data?.message ?? 'Login failed.';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchMe() {
            const response = await api.get('/api/auth/admin/me');
            const payload = response.data?.data ?? response.data ?? null;
            this.setAdminPayload(payload);
            this.initialized = true;
            this.error = null;
            return this.admin;
        },

        async logout() {
            this.loading = true;

            try {
                await api.post('/api/auth/admin/logout');
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
            this.error = null;

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
                this.error = error?.response?.data?.message ?? null;
            } finally {
                this.loading = false;
            }
        },
    },
});
