<template>
    <div class="pm-panel flex shrink-0 items-stretch overflow-x-auto border-b pm-border">
        <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            @click="$emit('select', tab.id)"
            @click.middle="$emit('close', tab.id)"
            :class="tab.id === activeTabId ? 'pm-surface border-t-2 border-t-[#FF6C37] pm-text' : 'pm-muted hover:pm-text'"
            class="flex min-w-0 max-w-52 shrink-0 items-center gap-2 border-r pm-border px-3 py-2 text-xs"
        >
            <span :class="methodClass(tab.request.method)" class="shrink-0 font-mono font-bold">
                {{ (tab.request.method || 'GET').toUpperCase() }}
            </span>
            <span class="min-w-0 flex-1 truncate text-left">
                {{ tab.request.name || tab.request.url || 'Untitled Request' }}
            </span>
            <span v-if="isDirty(tab)" class="shrink-0 text-[#FF6C37]" title="Unsaved changes">●</span>
            <span
                @click.stop="$emit('close', tab.id)"
                class="shrink-0 rounded px-1 leading-none hover:bg-neutral-500/30"
                title="Close tab"
            >✕</span>
        </button>
        <button
            type="button"
            @click="$emit('new')"
            class="shrink-0 px-3 py-2 text-sm pm-muted hover:pm-text"
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
