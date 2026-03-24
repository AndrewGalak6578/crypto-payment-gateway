<template>
    <section class="dashboard">
        <header class="hero">
            <h1>Internal Admin Panel</h1>
            <p>
                Base admin surface is ready. This area will host internal operations for
                merchants, invoices, webhook deliveries, and system overview.
            </p>
        </header>

        <div class="cards">
            <article class="card">
                <h2>Merchants</h2>
                <p>Manage merchant accounts, access, status, and internal support actions.</p>
            </article>

            <article class="card">
                <h2>Invoices</h2>
                <p>Inspect invoice lifecycle, payment state, forwarding and troubleshooting.</p>
            </article>

            <article class="card">
                <h2>Webhook Deliveries</h2>
                <p>Review delivery attempts, retries, failures and payload diagnostics.</p>
            </article>

            <article class="card">
                <h2>Admin Overview</h2>
                <p>Track internal signals, key numbers and operational health over time.</p>
            </article>
        </div>
    </section>
</template>

<style scoped>
.dashboard {
    display: grid;
    gap: 20px;
}

.hero {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 24px;
}

.hero h1 {
    margin: 0 0 10px;
    color: #0f172a;
    font-size: 28px;
}

.hero p {
    margin: 0;
    color: #475569;
    line-height: 1.6;
}

.cards {
    display: grid;
    gap: 16px;
}

.card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 20px;
}

.card h2 {
    margin: 0 0 8px;
    color: #0f172a;
    font-size: 18px;
}

.card p {
    margin: 0;
    color: #475569;
    line-height: 1.5;
}

@media (min-width: 992px) {
    .cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
