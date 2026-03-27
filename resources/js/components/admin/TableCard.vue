<template>
    <article class="table-card">
        <div class="table-wrap">
            <slot />
        </div>
    </article>
</template>

<style scoped>
.table-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.table-wrap {
    overflow-x: auto;
}
</style>
