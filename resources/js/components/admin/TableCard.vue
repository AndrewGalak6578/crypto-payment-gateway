<template>
    <article class="table-card">
        <div class="table-wrap">
            <slot />
        </div>
    </article>
</template>

<style scoped>
.table-card {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
}

.table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
</style>
