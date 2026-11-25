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
                    ref="cancelBtn"
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
                <button
                    v-if="extraLabel"
                    type="button"
                    @click="$emit('extra')"
                    class="pm-btn pm-btn-primary"
                >
                    {{ extraLabel }}
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
        // Optional third action (e.g. "Save" on the unsaved-changes dialog).
        extraLabel: { type: String, default: '' },
    },
    emits: ['close', 'confirm', 'extra'],
    watch: {
        open(value) {
            if (value) {
                this.$nextTick(() => {
                    // Land focus in the first field, so the user can type
                    // straight away; Enter confirms once the form is valid.
                    const root = this.$el
                    const field = root && root.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled])')
                    if (field) {
                        field.focus()
                    } else if (this.danger && this.$refs.cancelBtn) {
                        // Destructive dialogs without a field: focus Cancel,
                        // never the destructive button.
                        this.$refs.cancelBtn.focus()
                    } else if (this.$refs.confirmBtn && !this.confirmDisabled) {
                        this.$refs.confirmBtn.focus()
                    }
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
            if (!this.open) return
            if (event.key === 'Escape') {
                event.preventDefault()
                this.$emit('close')
                return
            }
            // Enter confirms once valid — only from a text input, so Enter
            // in a textarea still makes a new line, Enter on a select
            // still opens its dropdown, and Enter on a checkbox just
            // toggles it.
            if (event.key === 'Enter' && event.target && event.target.tagName === 'INPUT'
                && !['checkbox', 'radio', 'button', 'submit', 'file'].includes(event.target.type)
                && !this.confirmDisabled) {
                event.preventDefault()
                this.$emit('confirm')
            }
        },
    },
}
</script>
