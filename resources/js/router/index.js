import { createRouter, createWebHistory } from 'vue-router';
import AuthLayout from '../layouts/AuthLayout.vue';
import MerchantLayout from '../layouts/MerchantLayout.vue';
import MerchantLoginPage from '../pages/auth/MerchantLoginPage.vue';
import MerchantDashboardPage from '../pages/merchant/MerchantDashboardPage.vue';
import MerchantBalancesPage from "../pages/merchant/MerchantBalancesPage.vue";
import MerchantWalletsPage from "../pages/merchant/MerchantWalletsPage.vue";
import MerchantInvoicesPage from '../pages/merchant/invoices/MerchantInvoicesPage.vue';
import MerchantInvoiceDetailPage from '../pages/merchant/invoices/MerchantInvoiceDetailPage.vue';
import { useAuthStore } from '../stores/auth';
import MerchantWebhookSettingsPage from "../pages/merchant/MerchantWebhookSettingsPage.vue";
import MerchantWebhookDeliveriesPage from "../pages/merchant/MerchantWebhookDeliveriesPage.vue";

const routes = [
    {
        path: '/',
        redirect: '/merchant',
    },
    {
        path: '/merchant/login',
        component: AuthLayout,
        meta: { guestOnly: true },
        children: [
            {
                path: '',
                name: 'merchant.login',
                component: MerchantLoginPage,
            },
        ],
    },
    {
        path: '/merchant',
        component: MerchantLayout,
        meta: { requiresAuth: true },
        children: [
            {
                path: '',
                name: 'merchant.dashboard',
                component: MerchantDashboardPage,
            },
            {
                path: 'invoices',
                name: 'merchant.invoices',
                component: MerchantInvoicesPage,
            },
            {
                path: 'balances',
                name: 'merchant.balances',
                component: MerchantBalancesPage,
            },
            {
                path: 'wallets',
                name: 'merchant.wallets',
                component: MerchantWalletsPage,
            },
            {
                path: 'webhook-deliveries',
                name: 'merchant.webhook-deliveries',
                component: MerchantWebhookDeliveriesPage,
            },
            {
                path: 'webhook-settings',
                name: 'merchant.webhook-settings',
                component: MerchantWebhookSettingsPage,
            },
            {
                path: 'invoices/:id',
                name: 'merchant.invoices.detail',
                component: MerchantInvoiceDetailPage,
                props: true,
            },
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/merchant',
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const authStore = useAuthStore();

    if (!authStore.initialized) {
        await authStore.bootstrapAuth();
    }

    const requiresAuth = to.matched.some((record) => record.meta.requiresAuth);
    const guestOnly = to.matched.some((record) => record.meta.guestOnly);

    if (requiresAuth && !authStore.isAuthenticated) {
        return {
            name: 'merchant.login',
            query: { redirect: to.fullPath },
        };
    }

    if (guestOnly && authStore.isAuthenticated) {
        const redirectTo = typeof to.query.redirect === 'string' ? to.query.redirect : '/merchant';
        return redirectTo;
    }

    return true;
});

export default router;
