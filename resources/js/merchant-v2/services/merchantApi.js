import api from '../../api/axios';

export const merchantApi = {
    dashboard: () => api.get('/api/merchant/dashboard'),
    payments: (params = {}) => api.get('/api/merchant/invoices', { params }),
    paymentsSummary: (params = {}) => api.get('/api/merchant/invoices/summary', { params }),
    payment: (id) => api.get(`/api/merchant/invoices/${id}`),
    refreshPayment: (id) => api.post(`/api/merchant/invoices/${id}/refresh`),
    createPayment: (payload) => api.post('/api/merchant/invoices', payload),
    balances: () => api.get('/api/merchant/balances'),
    wallets: () => api.get('/api/merchant/wallets'),
    apiKeys: () => api.get('/api/merchant/api-keys'),
    createApiKey: (payload) => api.post('/api/merchant/api-keys', payload),
    deleteApiKey: (id) => api.delete(`/api/merchant/api-keys/${id}`),
    webhookSettings: () => api.get('/api/merchant/webhook-settings'),
    updateWebhookSettings: (payload) => api.put('/api/merchant/webhook-settings', payload),
    webhookDeliveries: (params = {}) => api.get('/api/merchant/webhook-deliveries', { params }),
    webhookDelivery: (id) => api.get(`/api/merchant/webhook-deliveries/${id}`),
    retryWebhookDelivery: (id) => api.post(`/api/merchant/webhook-deliveries/${id}/retry`),
    users: (params = {}) => api.get('/api/merchant/merchant-users', { params }),
};
