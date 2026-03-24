import { createRouter, createWebHistory } from 'vue-router';
import AdminAuthLayout from '../layouts/AdminAuthLayout.vue';
import AdminLayout from '../layouts/AdminLayout.vue';
import AdminLoginPage from '../pages/admin/auth/AdminLoginPage.vue';
import AdminDashboardPage from '../pages/admin/AdminDashboardPage.vue';
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
