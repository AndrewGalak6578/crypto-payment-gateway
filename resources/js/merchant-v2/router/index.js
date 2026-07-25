import { createRouter, createWebHistory } from 'vue-router';
import AppShell from '../layouts/AppShell.vue';
import MerchantLoginPage from '../../pages/auth/MerchantLoginPage.vue';
import MerchantRegisterPage from '../../pages/auth/MerchantRegisterPage.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
import { useAuthStore } from '../../stores/auth';

const DashboardPage = () => import('../pages/DashboardPage.vue');
const PaymentsPage = () => import('../pages/payments/PaymentsPage.vue');
const PaymentDetailPage = () => import('../pages/payments/PaymentDetailPage.vue');
const CreatePaymentPage = () => import('../pages/payments/CreatePaymentPage.vue');
const DevelopersPage = () => import('../pages/developers/DevelopersPage.vue');
const SettlementsPage = () => import('../pages/settlements/SettlementsPage.vue');
const SettlementRulesPage = () => import('../pages/settlements/SettlementRulesPage.vue');
const TeamPage = () => import('../pages/team/TeamPage.vue');
const TeamMemberProfilePage = () => import('../pages/team/TeamMemberProfilePage.vue');
const SettingsPage = () => import('../pages/settings/SettingsPage.vue');

const routes = [
    { path: '/', redirect: '/merchant/dashboard' },
    {
        path: '/merchant/login',
        component: AuthLayout,
        meta: { guestOnly: true },
        children: [{ path: '', name: 'merchant.login', component: MerchantLoginPage }],
    },
    {
        path: '/merchant/register',
        component: AuthLayout,
        meta: { guestOnly: true },
        children: [{ path: '', name: 'merchant.register', component: MerchantRegisterPage }],
    },
    {
        path: '/merchant',
        component: AppShell,
        meta: { requiresAuth: true },
        children: [
            { path: '', redirect: { name: 'merchant-v2.dashboard' } },
            { path: 'dashboard', name: 'merchant-v2.dashboard', component: DashboardPage },
            { path: 'payments', name: 'merchant-v2.payments', component: PaymentsPage },
            { path: 'payments/new', name: 'merchant-v2.create-payment', component: CreatePaymentPage },
            { path: 'payments/:paymentId', name: 'merchant-v2.payment-detail', component: PaymentDetailPage, props: true },
            { path: 'developers', name: 'merchant-v2.developers', component: DevelopersPage },
            { path: 'settlements', name: 'merchant-v2.settlements', component: SettlementsPage },
            {
                path: 'settlement-rules',
                name: 'merchant-v2.settlement-rules',
                component: SettlementRulesPage,
                meta: { capability: 'settlements.read' },
            },
            { path: 'team', name: 'merchant-v2.team', component: TeamPage },
            { path: 'team/:userId', name: 'merchant-v2.team-member', component: TeamMemberProfilePage, props: true },
            { path: 'settings', name: 'merchant-v2.settings', component: SettingsPage },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/merchant/dashboard' },
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
        return { name: 'merchant.login', query: { redirect: to.fullPath } };
    }

    if (guestOnly && authStore.isAuthenticated) {
        return typeof to.query.redirect === 'string' ? to.query.redirect : '/merchant/dashboard';
    }

    const capability = to.matched.map((record) => record.meta.capability).find(Boolean);
    if (capability && !authStore.hasCapability(capability)) {
        return { name: 'merchant-v2.dashboard' };
    }

    return true;
});

export default router;
