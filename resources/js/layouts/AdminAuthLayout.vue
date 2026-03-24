<template>
    <div class="auth-shell">
        <div class="auth-card">
            <div class="auth-header">
                <p class="auth-kicker">Internal Access</p>
                <h1 class="auth-title">Admin Panel</h1>
                <p class="auth-subtitle">Sign in with your admin account.</p>
            </div>

            <RouterView />
        </div>
    </div>
</template>

<style scoped>
.auth-shell {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: linear-gradient(135deg, #0f172a 0%, #111827 100%);
}

.auth-card {
    width: 100%;
    max-width: 440px;
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.18);
    padding: 28px;
}

.auth-header {
    margin-bottom: 20px;
}

.auth-kicker {
    margin: 0 0 8px;
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
}

.auth-title {
    margin: 0;
    color: #0f172a;
    font-size: 28px;
    line-height: 1.2;
}

.auth-subtitle {
    margin: 8px 0 0;
    color: #475569;
    font-size: 14px;
}
</style>
