import api from "./axios.js";

export const getMerchantBalances = () => api.get('/api/merchant/balances');

export const getMerchantWallets = () => api.get('/api/merchant/wallets');

export const createMerchantWallet = (payload) => api.post('/api/merchant/wallets', payload);

export const updateMerchantWallet = (id, payload) => api.put(`/api/merchant/wallets/${id}`, payload);

export const deleteMerchantWallet = (id) => api.delete(`/api/merchant/wallets/${id}`);

export const getMerchantWebhookSettings = () => api.get('/api/merchant/webhook-settings');

export const updateMerchantWebhookSettings = (payload) => api.put('/api/merchant/webhook-settings', payload);
