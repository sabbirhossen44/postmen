<template>
    <div
        v-if="open"
        class="pm-overlay fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4"
        @click.self="$emit('close')"
    >
        <div class="pm-pop w-full max-w-lg rounded-xl border pm-border pm-panel shadow-2xl" role="dialog" aria-modal="true">
            <div class="flex items-center justify-between border-b pm-border px-4 py-3">
                <h3 class="text-sm font-semibold pm-text">{{ title }}</h3>
                <button
                    type="button"
                    @click="$emit('close')"
                    class="pm-icon-btn"
                    title="Close (Esc)"
                    aria-label="Close dialog"
                >
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4l8 8M12 4l-8 8" /></svg>
                </button>
            </div>
            <div class="max-h-[70vh] overflow-y-auto px-4 py-4">
                <slot></slot>
            </div>
            <div class="flex justify-end gap-2 border-t pm-border px-4 py-3">
                <button
                    type="button"
                    @click="$emit('close')"
                    class="pm-btn pm-btn-ghost"
                >
                    Cancel
                </button>
                <button
                    ref="confirmBtn"
                    type="button"
                    @click="$emit('confirm')"
                    :disabled="confirmDisabled"
                    :class="danger ? 'pm-btn-danger' : 'pm-btn-primary'"
                    class="pm-btn"
                >
                    {{ confirmLabel }}
                </button>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'Modal',
    props: {
        open: { type: Boolean, default: false },
        title: { type: String, default: '' },
        confirmLabel: { type: String, default: 'Save' },
        confirmDisabled: { type: Boolean, default: false },
        // Danger styling for destructive confirms (deletes, clear history).
        danger: { type: Boolean, default: false },
    },
    emits: ['close', 'confirm'],
    watch: {
        open(value) {
            if (value) {
                this.$nextTick(() => {
                    if (this.$refs.confirmBtn && !this.confirmDisabled) this.$refs.confirmBtn.focus()
                })
            }
        },
    },
    mounted() {
        window.addEventListener('keydown', this.onKeydown)
    },
    beforeUnmount() {
        window.removeEventListener('keydown', this.onKeydown)
    },
    methods: {
        onKeydown(event) {
            if (this.open && event.key === 'Escape') {
                event.preventDefault()
                this.$emit('close')
            }
        },
    },
}
</script>
