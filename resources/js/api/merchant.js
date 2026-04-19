import api from "./axios.js";

export const getMerchantBalances = () => api.get('/api/merchant/balances');

export const getMerchantWallets = () => api.get('/api/merchant/wallets');

export const createMerchantWallet = (payload) => api.post('/api/merchant/wallets', payload);

export const updateMerchantWallet = (id, payload) => api.put(`/api/merchant/wallets/${id}`, payload);

export const deleteMerchantWallet = (id) => api.delete(`/api/merchant/wallets/${id}`);

export const getMerchantWebhookSettings = () => api.get('/api/merchant/webhook-settings');

export const updateMerchantWebhookSettings = (payload) => api.put('/api/merchant/webhook-settings', payload);

export const getMerchantWebhookDeliveries = (params = {}) => api.get('/api/merchant/webhook-deliveries', { params });
export const getMerchantWebhookDeliveryDetail = (id) => api.get(`/api/merchant/webhook-deliveries/${id}`);

export const getMerchantApiKeys = () => api.get('/api/merchant/api-keys');

export const createMerchantApiKey = (payload) => api.post('/api/merchant/api-keys', payload);

export const deleteMerchantApiKey = (id) => api.delete(`/api/merchant/api-keys/${id}`);

export const createMerchantInvoiceWithToken = (token, payload) => api.post('/api/v1/invoices', payload, {
    headers: {
        Authorization: `Bearer ${token}`,
    },
});

export const createMerchantInvoice = (payload) => api.post('/api/merchant/invoices', payload);
export const refreshMerchantInvoice = (id) => api.post(`/api/merchant/invoices/${id}/refresh`);
