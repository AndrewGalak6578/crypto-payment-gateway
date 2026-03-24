<template>
    <div class="admin-layout">
        <aside class="sidebar">
            <div>
                <h1 class="brand">Internal Admin Panel</h1>
                <p class="admin-meta">
                    {{ adminLabel }}
                </p>
            </div>

            <nav class="nav">
                <RouterLink
                    class="nav-link"
                    to="/admin"
                    exact-active-class="router-link-active"
                    active-class=""
                >
                    Dashboard
                </RouterLink>
            </nav>

            <button
                class="logout-btn"
                type="button"
                :disabled="adminAuthStore.loading"
                @click="handleLogout"
            >
                Logout
            </button>
        </aside>

        <main class="content">
            <header class="topbar">
                <strong>{{ adminLabel }}</strong>
            </header>

            <RouterView />
        </main>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAdminAuthStore } from '../stores/adminAuth';

const router = useRouter();
const adminAuthStore = useAdminAuthStore();

const adminLabel = computed(() => {
    return (
        adminAuthStore.admin?.name ||
        adminAuthStore.admin?.email ||
        'Admin User'
    );
});

const handleLogout = async () => {
    await adminAuthStore.logout();
    await router.push({ name: 'admin.login' });
};
</script>

<style scoped>
.admin-layout {
    min-height: 100vh;
    background: #f8fafc;
}

.sidebar {
    background: #111827;
    color: #cbd5e1;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.brand {
    margin: 0;
    color: #f8fafc;
    font-size: 20px;
}

.admin-meta {
    margin: 8px 0 0;
    color: #94a3b8;
    font-size: 14px;
}

.nav {
    display: grid;
    gap: 8px;
}

.nav-link {
    color: #cbd5e1;
    text-decoration: none;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 10px 12px;
}

.nav-link.router-link-active {
    background: #1f2937;
    border-color: #374151;
    color: #f8fafc;
}

.logout-btn {
    margin-top: auto;
    border: 1px solid #374151;
    border-radius: 8px;
    background: transparent;
    color: #f8fafc;
    padding: 10px 12px;
    cursor: pointer;
}

.logout-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.content {
    padding: 20px;
}

.topbar {
    margin-bottom: 16px;
    color: #334155;
}

@media (min-width: 992px) {
    .admin-layout {
        display: grid;
        grid-template-columns: 260px 1fr;
    }

    .content {
        padding: 24px;
    }
}
</style>
