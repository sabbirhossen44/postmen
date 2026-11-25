<template>
    <div class="pointer-events-none fixed bottom-10 right-4 z-[100] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2" aria-live="polite">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            :class="['pm-toast', toast.leaving ? 'pm-toast-leaving' : '']"
            class="pointer-events-auto flex items-start gap-2.5 rounded-lg border pm-border pm-panel px-3 py-2.5 text-sm shadow-xl"
            role="status"
        >
            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" :class="iconClass(toast.type)">
                {{ toast.type === 'success' ? '✓' : toast.type === 'error' ? '!' : 'i' }}
            </span>
            <span class="min-w-0 flex-1 break-words pm-text-2">{{ toast.message }}</span>
            <button type="button" @click="dismissToast(toast.id)" class="pm-icon-btn pm-icon-btn-xs shrink-0" title="Dismiss" aria-label="Dismiss notification">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4l8 8M12 4l-8 8" /></svg>
            </button>
        </div>
    </div>
</template>

<script>
import { toasts, dismissToast } from '../utils/toast.js'

export default {
    name: 'Toasts',
    data() {
        return { toasts }
    },
    methods: {
        dismissToast,
        iconClass(type) {
            if (type === 'success') return 'bg-green-600'
            if (type === 'error') return 'bg-red-600'
            return 'bg-sky-600'
        },
    },
}
</script>
