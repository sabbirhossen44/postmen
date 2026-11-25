<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
        @click.self="$emit('close')"
    >
        <div class="w-full max-w-lg rounded-lg border pm-border pm-panel shadow-xl">
            <div class="flex items-center justify-between border-b pm-border px-4 py-3">
                <h3 class="text-sm font-semibold pm-text">{{ title }}</h3>
                <button
                    type="button"
                    @click="$emit('close')"
                    class="rounded px-2 py-0.5 pm-muted hover:bg-neutral-700 hover:pm-text"
                >
                    ✕
                </button>
            </div>
            <div class="max-h-[70vh] overflow-y-auto px-4 py-4">
                <slot></slot>
            </div>
            <div class="flex justify-end gap-2 border-t pm-border px-4 py-3">
                <button
                    type="button"
                    @click="$emit('close')"
                    class="rounded border border-neutral-600 px-3 py-1.5 text-sm pm-text-2 hover:bg-neutral-700"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="$emit('confirm')"
                    :disabled="confirmDisabled"
                    class="rounded bg-[#FF6C37] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#e85a2a] disabled:opacity-50"
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
    },
    emits: ['close', 'confirm'],
}
</script>
