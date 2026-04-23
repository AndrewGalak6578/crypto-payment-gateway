import api from './axios';

export const getAdminDashboard = () => api.get('/api/admin/dashboard');

export const getAdminMerchants = (params = {}) => api.get('/api/admin/merchants', { params });
export const createAdminMerchant = (payload) => api.post('/api/admin/merchants', payload);
export const getAdminMerchant = (merchantId) => api.get(`/api/admin/merchants/${merchantId}`);
export const updateAdminMerchantStatus = (merchantId, payload) => api.patch(`/api/admin/merchants/${merchantId}/status`, payload);
export const getAdminMerchantWallets = (merchantId) => api.get(`/api/admin/merchants/${merchantId}/wallets`);
export const createAdminMerchantWallet = (merchantId, payload) => api.post(`/api/admin/merchants/${merchantId}/wallets`, payload);
export const updateAdminMerchantWallet = (merchantId, walletId, payload) => api.put(`/api/admin/merchants/${merchantId}/wallets/${walletId}`, payload);
export const deleteAdminMerchantWallet = (merchantId, walletId) => api.delete(`/api/admin/merchants/${merchantId}/wallets/${walletId}`);

export const getAdminMerchantUsers = (params = {}) => api.get('/api/admin/merchant-users', { params });
export const createAdminMerchantUser = (payload) => api.post('/api/admin/merchant-users', payload);
export const updateAdminMerchantUserRole = (merchantUserId, payload) => api.patch(`/api/admin/merchant-users/${merchantUserId}/role`, payload);
export const updateAdminMerchantUserStatus = (merchantUserId, payload) => api.patch(`/api/admin/merchant-users/${merchantUserId}/status`, payload);

export const getAdminInvoices = (params = {}) => api.get('/api/admin/invoices', { params });
export const getAdminInvoice = (invoiceId) => api.get(`/api/admin/invoices/${invoiceId}`);
export const refreshAdminInvoice = (invoiceId) => api.post(`/api/admin/invoices/${invoiceId}/refresh`);

export const getAdminWebhookDeliveries = (params = {}) => api.get('/api/admin/webhook-deliveries', { params });
export const getAdminWebhookDelivery = (deliveryId) => api.get(`/api/admin/webhook-deliveries/${deliveryId}`);
export const retryAdminWebhookDelivery = (deliveryId) => api.post(`/api/admin/webhook-deliveries/${deliveryId}/retry`);

export const getAdminMerchantApiKeys = (params = {}) => api.get('/api/admin/merchant-api-keys', { params });
export const revokeAdminMerchantApiKey = (apiKeyId) => api.post(`/api/admin/merchant-api-keys/${apiKeyId}/revoke`);

export const extractApiErrorMessage = (requestError, fallbackMessage = 'Request failed.') => {
    const validationErrors = requestError?.response?.data?.errors;

    if (validationErrors && typeof validationErrors === 'object') {
        const firstMessage = Object.values(validationErrors).flat()[0];

        if (firstMessage) {
            return firstMessage;
        }
    }

    return requestError?.response?.data?.message || fallbackMessage;
};
