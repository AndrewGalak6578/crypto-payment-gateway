import api from "./axios.js";

export const getMerchantBalances = () => api.get('/api/merchant/balances');
