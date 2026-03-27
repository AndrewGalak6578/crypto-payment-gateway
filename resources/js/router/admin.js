import { createRouter, createWebHistory } from 'vue-router';
import AdminAuthLayout from '../layouts/AdminAuthLayout.vue';
import AdminLayout from '../layouts/AdminLayout.vue';
import AdminLoginPage from '../pages/admin/auth/AdminLoginPage.vue';
import AdminDashboardPage from '../pages/admin/AdminDashboardPage.vue';
import AdminMerchantsListPage from '../pages/admin/merchants/AdminMerchantsListPage.vue';
import AdminMerchantDetailPage from '../pages/admin/merchants/AdminMerchantDetailPage.vue';
import AdminMerchantUsersPage from '../pages/admin/merchant-users/AdminMerchantUsersPage.vue';
import AdminInvoicesListPage from '../pages/admin/invoices/AdminInvoicesListPage.vue';
import AdminInvoiceDetailPage from '../pages/admin/invoices/AdminInvoiceDetailPage.vue';
import AdminWebhookDeliveriesListPage from '../pages/admin/webhook-deliveries/AdminWebhookDeliveriesListPage.vue';
import AdminWebhookDeliveryDetailPage from '../pages/admin/webhook-deliveries/AdminWebhookDeliveryDetailPage.vue';
import AdminApiKeysPage from '../pages/admin/api-keys/AdminApiKeysPage.vue';
import { useAdminAuthStore } from '../stores/adminAuth';

const routes = [
    {
        path: '/',
        redirect: '/admin',
    },
    {
        path: '/admin/login',
        component: AdminAuthLayout,
        meta: { guestOnly: true },
        children: [
            {
                path: '',
                name: 'admin.login',
                component: AdminLoginPage,
            },
        ],
    },
    {
        path: '/admin',
        component: AdminLayout,
        meta: { requiresAuth: true },
        children: [
            {
                path: '',
                name: 'admin.dashboard',
                component: AdminDashboardPage,
            },
            {
                path: 'merchants',
                name: 'admin.merchants',
                component: AdminMerchantsListPage,
            },
            {
                path: 'merchants/:id',
                name: 'admin.merchants.detail',
                component: AdminMerchantDetailPage,
            },
            {
                path: 'merchant-users',
                name: 'admin.merchant-users',
                component: AdminMerchantUsersPage,
            },
            {
                path: 'invoices',
                name: 'admin.invoices',
                component: AdminInvoicesListPage,
            },
            {
                path: 'invoices/:id',
                name: 'admin.invoices.detail',
                component: AdminInvoiceDetailPage,
            },
            {
                path: 'webhook-deliveries',
                name: 'admin.webhook-deliveries',
                component: AdminWebhookDeliveriesListPage,
            },
            {
                path: 'webhook-deliveries/:id',
                name: 'admin.webhook-deliveries.detail',
                component: AdminWebhookDeliveryDetailPage,
            },
            {
                path: 'api-keys',
                name: 'admin.api-keys',
                component: AdminApiKeysPage,
            },
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/admin',
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const adminAuthStore = useAdminAuthStore();

    if (!adminAuthStore.initialized) {
        await adminAuthStore.bootstrapAuth();
    }

    const requiresAuth = to.matched.some((record) => record.meta.requiresAuth);
    const guestOnly = to.matched.some((record) => record.meta.guestOnly);

    if (requiresAuth && !adminAuthStore.isAuthenticated) {
        return {
            name: 'admin.login',
            query: { redirect: to.fullPath },
        };
    }

    if (guestOnly && adminAuthStore.isAuthenticated) {
        return typeof to.query.redirect === 'string'
            ? to.query.redirect
            : '/admin';
    }

    return true;
});

export default router;
