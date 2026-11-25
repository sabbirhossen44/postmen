<template>
    <div v-if="open" class="pm-overlay fixed inset-0 z-[90] flex items-start justify-center bg-black/60 px-4 pt-[12vh]" @click.self="$emit('close')">
        <div class="pm-pop w-full max-w-xl overflow-hidden rounded-xl border pm-border pm-panel shadow-2xl" role="dialog" aria-label="Command palette">
            <div class="flex items-center gap-2 border-b pm-border px-3 py-2.5">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="h-4 w-4 shrink-0 pm-faint"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
                <input
                    ref="input"
                    v-model="query"
                    type="text"
                    placeholder="Search requests, collections, environments… or type an action"
                    class="min-w-0 flex-1 bg-transparent text-sm pm-text outline-none placeholder:pm-faint"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter.prevent="runSelected"
                    @keydown.esc.prevent="$emit('close')"
                >
                <span class="pm-kbd">esc</span>
            </div>

            <div ref="list" class="max-h-[50vh] overflow-y-auto py-1.5">
                <template v-for="group in groups" :key="group.key">
                    <div v-if="group.items.length > 0" class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide pm-faint">
                        {{ group.label }}
                    </div>
                    <button
                        v-for="item in group.items"
                        :key="item.id"
                        type="button"
                        @click="run(item)"
                        @mouseenter="selectedId = item.id"
                        :data-selected="selectedId === item.id"
                        :class="selectedId === item.id ? 'pm-active' : ''"
                        class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm pm-text-2"
                    >
                        <span v-if="item.method" :class="'shrink-0 font-mono text-[11px] font-bold ' + methodClass(item.method)">{{ item.method }}</span>
                        <span v-else class="flex h-5 w-5 shrink-0 items-center justify-center rounded pm-active text-xs pm-muted">{{ item.icon }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                        <span v-if="item.hint" class="shrink-0 truncate text-xs pm-faint">{{ item.hint }}</span>
                        <span v-if="item.shortcut" class="pm-kbd shrink-0">{{ item.shortcut }}</span>
                    </button>
                </template>
                <div v-if="flatItems.length === 0" class="px-4 py-8 text-center">
                    <p class="text-sm font-medium pm-text-2">No results for “{{ query }}”</p>
                    <p class="mt-1 text-xs pm-faint">Try a different search, or create something new below.</p>
                    <button type="button" @click="$emit('new-request')" class="pm-btn pm-btn-primary mt-4">New request</button>
                </div>
            </div>

            <div class="flex items-center gap-3 border-t pm-border px-3 py-2 text-[11px] pm-faint">
                <span><span class="pm-kbd">↑↓</span> navigate</span>
                <span><span class="pm-kbd">↵</span> select</span>
                <span><span class="pm-kbd">esc</span> close</span>
            </div>
        </div>
    </div>
</template>

<script>
import { store } from '../store.js'
import { methodClass } from '../utils/methods.js'

export default {
    name: 'CommandPalette',
    props: {
        open: { type: Boolean, default: false },
    },
    emits: ['close', 'jump-request', 'jump-collection', 'new-request', 'new-collection', 'new-environment', 'switch-workspace', 'switch-environment', 'run-action'],
    data() {
        return {
            store,
            query: '',
            selectedId: null,
        }
    },
    computed: {
        allRequests() {
            const out = []
            store.collections.forEach((c) => {
                ;(c.requests || []).forEach((r) => out.push({ req: r, collection: c.name }))
                ;(c.folders || []).forEach((f) => {
                    ;(f.requests || []).forEach((r) => out.push({ req: r, collection: c.name }))
                })
            })
            return out
        },
        actions() {
            return [
                { id: 'act-send', label: 'Send request', icon: '▶', shortcut: '⌘↵', action: 'send' },
                { id: 'act-save', label: 'Save request', icon: '↓', shortcut: '⌘S', action: 'save' },
                { id: 'act-new-tab', label: 'New tab', icon: '+', shortcut: '⌘T', action: 'new-tab' },
                { id: 'act-new-request', label: 'New request', icon: '+', action: 'new-request' },
                { id: 'act-new-collection', label: 'New collection', icon: '+', action: 'new-collection' },
                { id: 'act-new-environment', label: 'New environment', icon: '+', action: 'new-environment' },
                { id: 'act-runner', label: 'Open Collection Runner', icon: '▶', action: 'runner' },
                { id: 'act-console', label: 'Toggle console', icon: '⌘', shortcut: '⌘⌥C', action: 'console' },
                { id: 'act-theme', label: 'Toggle light / dark theme', icon: '◐', action: 'theme' },
                { id: 'act-shortcuts', label: 'Keyboard shortcuts', icon: '?', shortcut: '?', action: 'shortcuts' },
            ]
        },
        groups() {
            const q = this.query.trim().toLowerCase()
            const match = (text) => !q || (text || '').toLowerCase().includes(q)
            const requests = this.allRequests
                .filter(({ req, collection }) => match(req.name) || match(req.url) || match(collection))
                .slice(0, 8)
                .map(({ req, collection }) => ({
                    id: 'req-' + req.id,
                    label: req.name || req.url || 'Untitled Request',
                    hint: collection,
                    method: (req.method || 'GET').toUpperCase(),
                    req,
                }))
            const collections = store.collections
                .filter((c) => match(c.name))
                .slice(0, 5)
                .map((c) => ({ id: 'col-' + c.id, label: c.name, hint: 'Collection', icon: '▦', collection: c }))
            const environments = store.environments
                .filter((e) => match(e.name))
                .slice(0, 5)
                .map((e) => ({
                    id: 'env-' + e.id,
                    label: e.name + (e.id === store.activeEnvironmentId ? '  ● active' : ''),
                    hint: 'Environment',
                    icon: '◍',
                    env: e,
                }))
            const workspaces = store.workspaces
                .filter((w) => match(w.name))
                .slice(0, 5)
                .map((w) => ({
                    id: 'ws-' + w.id,
                    label: w.name + (w.id === store.activeWorkspaceId ? '  ● active' : ''),
                    hint: 'Workspace',
                    icon: '▣',
                    workspace: w,
                }))
            const actions = this.actions
                .filter((a) => match(a.label))
                .map((a) => ({ ...a }))
            return [
                { key: 'actions', label: 'Actions', items: q ? actions : actions.slice(0, 6) },
                { key: 'requests', label: 'Requests', items: requests },
                { key: 'collections', label: 'Collections', items: collections },
                { key: 'environments', label: 'Environments', items: environments },
                { key: 'workspaces', label: 'Workspaces', items: workspaces },
            ]
        },
        flatItems() {
            return this.groups.flatMap((g) => g.items)
        },
    },
    watch: {
        open(value) {
            if (value) {
                this.query = ''
                this.selectedId = null
                this.$nextTick(() => {
                    if (this.$refs.input) this.$refs.input.focus()
                    this.selectedId = this.flatItems.length > 0 ? this.flatItems[0].id : null
                })
            }
        },
        query() {
            this.selectedId = this.flatItems.length > 0 ? this.flatItems[0].id : null
        },
    },
    methods: {
        methodClass,
        move(dir) {
            const items = this.flatItems
            if (items.length === 0) return
            let idx = items.findIndex((i) => i.id === this.selectedId)
            idx = idx === -1 ? 0 : (idx + dir + items.length) % items.length
            this.selectedId = items[idx].id
            this.$nextTick(() => {
                const el = this.$refs.list && this.$refs.list.querySelector('[data-selected="true"]')
                if (el) el.scrollIntoView({ block: 'nearest' })
            })
        },
        runSelected() {
            const item = this.flatItems.find((i) => i.id === this.selectedId)
            if (item) this.run(item)
        },
        run(item) {
            if (item.req) {
                this.$emit('jump-request', item.req)
            } else if (item.collection) {
                this.$emit('jump-collection', item.collection)
            } else if (item.env) {
                this.$emit('switch-environment', item.env.id)
            } else if (item.workspace) {
                this.$emit('switch-workspace', item.workspace.id)
            } else if (item.action === 'new-request') {
                this.$emit('new-request')
            } else if (item.action === 'new-collection') {
                this.$emit('new-collection')
            } else if (item.action === 'new-environment') {
                this.$emit('new-environment')
            } else if (item.action) {
                this.$emit('run-action', item.action)
            }
            this.$emit('close')
        },
    },
}
</script>
