<template>
    <teleport to="body">
        <div v-if="open" class="overlay" @click.self="$emit('close')">
            <div class="modal">
                <h3 class="title">{{ title }}</h3>
                <p class="message">{{ message }}</p>
                <div class="actions">
                    <button type="button" class="secondary-btn" :disabled="loading" @click="$emit('close')">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="confirm-btn"
                        :class="{ 'confirm-btn-danger': danger }"
                        :disabled="loading"
                        @click="$emit('confirm')"
                    >
                        {{ loading ? 'Processing...' : confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </teleport>
</template>

<script setup>
defineEmits(['close', 'confirm']);

defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: 'Confirm action',
    },
    message: {
        type: String,
        default: 'Are you sure?',
    },
    confirmLabel: {
        type: String,
        default: 'Confirm',
    },
    danger: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});
</script>

<style scoped>
.overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    display: grid;
    place-items: center;
    z-index: 1000;
    padding: 16px;
}

.modal {
    width: min(460px, 100%);
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 18px;
}

.title {
    margin: 0;
    color: #0f172a;
    font-size: 18px;
}

.message {
    margin: 10px 0 0;
    color: #475569;
    line-height: 1.5;
}

.actions {
    margin-top: 16px;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

.secondary-btn,
.confirm-btn {
    border-radius: 8px;
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
    cursor: pointer;
    font: inherit;
}

.confirm-btn {
    border-color: #0f172a;
    background: #0f172a;
    color: #fff;
}

.confirm-btn-danger {
    border-color: #b91c1c;
    background: #b91c1c;
}

.secondary-btn:disabled,
.confirm-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}
</style>
