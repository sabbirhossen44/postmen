<template>
    <div class="pm-panel flex shrink-0 items-stretch overflow-x-auto border-b pm-border">
        <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            @click="$emit('select', tab.id)"
            @click.middle="$emit('close', tab.id)"
            :class="tab.id === activeTabId ? 'pm-surface border-t-2 pm-border-t-accent pm-text' : 'pm-muted hover:pm-text'"
            class="flex min-w-0 max-w-52 shrink-0 items-center gap-2 border-r pm-border px-3 py-2 text-xs transition-colors"
        >
            <span :class="methodClass(tab.request.method)" class="shrink-0 font-mono font-bold">
                {{ (tab.request.method || 'GET').toUpperCase() }}
            </span>
            <span class="min-w-0 flex-1 truncate text-left">
                {{ tab.request.name || tab.request.url || 'Untitled Request' }}
            </span>
            <span v-if="isDirty(tab)" class="shrink-0 pm-accent-text" title="Unsaved changes">●</span>
            <span
                @click.stop="$emit('close', tab.id)"
                class="flex h-4 w-4 shrink-0 items-center justify-center rounded leading-none hover:bg-neutral-500/30"
                title="Close tab"
            >✕</span>
        </button>
        <button
            type="button"
            @click="$emit('new')"
            class="pm-icon-btn pm-icon-btn-square shrink-0"
            title="New tab (Ctrl/Cmd+T)"
        >
            +
        </button>
    </div>
</template>

<script>
import { isTabDirty } from '../store.js'
import { methodClass } from '../utils/methods.js'

export default {
    name: 'RequestTabs',
    props: {
        tabs: { type: Array, required: true },
        activeTabId: { type: [Number, String], required: true },
    },
    emits: ['select', 'close', 'new'],
    methods: {
        methodClass,
        isDirty(tab) {
            return isTabDirty(tab)
        },
    },
}
</script>
