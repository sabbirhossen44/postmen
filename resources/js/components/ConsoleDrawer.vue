<template>
    <div v-if="open" class="pm-surface flex h-56 shrink-0 flex-col border-t pm-border">
        <div class="flex items-center justify-between border-b pm-border px-4 py-2">
            <span class="text-xs font-semibold pm-text">Console</span>
            <div class="flex gap-2">
                <button type="button" @click="$emit('clear')" class="pm-btn pm-btn-ghost pm-btn-sm">Clear</button>
                <button type="button" @click="$emit('close')" class="pm-icon-btn" title="Close console" aria-label="Close console">✕</button>
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-2 font-mono text-xs">
            <p v-if="entries.length === 0" class="pm-faint">No console output yet. Send a request and its method, URL, status and time appear here.</p>
            <div v-for="e in entries" :key="e.id" class="border-b pm-border py-1.5">
                <div class="flex flex-wrap gap-2">
                    <span class="pm-faint">{{ formatTime(e.time) }}</span>
                    <span :class="methodClass(e.method)" class="font-bold">{{ e.method }}</span>
                    <span class="break-all pm-text-2">{{ e.url }}</span>
                    <span v-if="e.status" :class="statusClass(e.status)">{{ e.status }}</span>
                    <span v-if="e.timeMs !== undefined" class="pm-muted">{{ e.timeMs }} ms</span>
                    <span v-if="e.error" class="status-error">{{ e.error }}</span>
                </div>
                <div v-if="e.headers" class="mt-1 pl-2 pm-muted">
                    <span v-for="(v, k) in e.headers" :key="k" class="mr-3">{{ k }}: {{ v }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { methodClass, statusClass } from '../utils/methods.js'

export default {
    name: 'ConsoleDrawer',
    props: {
        open: { type: Boolean, default: false },
        entries: { type: Array, default: () => [] },
    },
    emits: ['close', 'clear'],
    methods: {
        methodClass,
        statusClass,
        formatTime(t) {
            const d = t instanceof Date ? t : new Date(t)
            return d.toLocaleTimeString()
        },
    },
}
</script>
