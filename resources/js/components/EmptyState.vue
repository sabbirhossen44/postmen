<template>
    <div class="flex flex-col items-center justify-center px-6 py-10 text-center">
        <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full pm-active" :class="iconColorClass" aria-hidden="true">
            <slot name="icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 8v5M12 16.5v.01" />
                </svg>
            </slot>
        </div>
        <p class="text-sm font-semibold pm-text">{{ title }}</p>
        <p class="mt-1 max-w-xs text-[13px] leading-relaxed pm-muted">{{ description }}</p>
        <div v-if="$slots.actions" class="mt-4 flex flex-wrap items-center justify-center gap-2">
            <slot name="actions"></slot>
        </div>
    </div>
</template>

<script>
export default {
    name: 'EmptyState',
    props: {
        title: { type: String, required: true },
        description: { type: String, default: '' },
        iconColorClass: { type: String, default: 'pm-accent-text' },
    },
}
</script>
