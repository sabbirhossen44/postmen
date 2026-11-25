<template>
    <aside v-if="!store.sidebarCollapsed" class="pm-sidebar flex h-full shrink-0 flex-col border-r pm-border" :style="{ width: store.sidebarWidth + 'px' }">
        <div class="flex border-b pm-border">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                @click="tab = t.key"
                :class="tab === t.key ? 'border-[#FF6C37] pm-text' : 'border-transparent pm-muted hover:pm-text'"
                class="flex-1 border-b-2 px-2 py-2.5 text-xs font-medium"
            >
                {{ t.label }}
            </button>
        </div>

        <!-- ============ COLLECTIONS ============ -->
        <div v-show="tab === 'collections'" class="flex min-h-0 flex-1 flex-col">
            <div class="p-2">
                <button
                    type="button"
                    @click="openPrompt('collection', null)"
                    class="pm-accent-bg w-full rounded px-3 py-2 text-sm font-medium text-white"
                >
                    + New Collection
                </button>
                <div class="mt-2 flex gap-2">
                    <button type="button" @click="expandAll" class="flex-1 rounded border pm-border px-2 py-1 text-xs pm-text-2 hover:pm-active">Expand all</button>
                    <button type="button" @click="collapseAll" class="flex-1 rounded border pm-border px-2 py-1 text-xs pm-text-2 hover:pm-active">Collapse all</button>
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-4" @click="closeContextMenu">
                <p v-if="store.collections.length === 0" class="px-2 py-4 text-sm pm-faint">
                    No collections yet. Create one to start saving requests.
                </p>
                <p v-else-if="filteredCollections.length === 0" class="px-2 py-4 text-sm pm-faint">
                    Nothing matches "{{ store.searchQuery }}".
                </p>
                <div v-for="collection in filteredCollections" :key="collection.id" class="mb-1">
                    <div
                        class="group flex items-center gap-1 rounded px-2 py-1.5 pm-hover"
                        @contextmenu.prevent="openContextMenu($event, 'collection', collection)"
                    >
                        <button type="button" @click="toggleCollection(collection)" class="w-4 shrink-0 pm-muted">
                            {{ expanded[collection.id] ? '▾' : '▸' }}
                        </button>
                        <button type="button" @click="toggleCollection(collection)" class="min-w-0 flex-1 truncate text-left text-sm font-medium pm-text-2">
                            {{ collection.name }}
                        </button>
                        <span class="shrink-0 text-xs pm-faint">{{ collectionRequestCount(collection) }}</span>
                        <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                            <button type="button" @click="openPrompt('request', collection)" title="New request" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active">+ Req</button>
                            <button type="button" @click="openPrompt('folder', collection)" title="New folder" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active">+ Dir</button>
                            <button type="button" @click="exportCollection(collection)" title="Export collection" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active">↓</button>
                            <button type="button" @click="openPrompt('rename-collection', collection)" title="Rename" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active">✎</button>
                            <button type="button" @click="askDelete('collection', collection)" title="Delete" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active hover:text-red-400">✕</button>
                        </span>
                    </div>

                    <div v-if="expanded[collection.id]">
                        <!-- Folders (sorted by sort_order, indented by nesting depth) -->
                        <div v-for="folder in sortedFolders(collection)" :key="'f' + folder.id">
                            <div
                                class="group flex items-center gap-1 rounded px-2 py-1 pm-hover"
                                :style="{ paddingLeft: (8 + folderDepth(folder, collection) * 16) + 'px' }"
                                @contextmenu.prevent="openContextMenu($event, 'folder', folder, collection)"
                            >
                                <span class="pm-faint">▸</span>
                                <span class="min-w-0 flex-1 truncate text-sm pm-text-2">{{ folder.name }}</span>
                                <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                                    <button type="button" @click="moveFolder(folder, collection, -1)" title="Move up" class="rounded px-1 py-0.5 pm-muted hover:pm-active">↑</button>
                                    <button type="button" @click="moveFolder(folder, collection, 1)" title="Move down" class="rounded px-1 py-0.5 pm-muted hover:pm-active">↓</button>
                                    <button type="button" @click="openPrompt('request-folder', { collection, folder })" title="New request in folder" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active">+ Req</button>
                                    <button type="button" @click="openPrompt('folder-child', { collection, folder })" title="New sub-folder" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active">+ Dir</button>
                                    <button type="button" @click="openPrompt('rename-folder', folder)" title="Rename" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active">✎</button>
                                    <button type="button" @click="askDelete('folder', folder)" title="Delete" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active hover:text-red-400">✕</button>
                                </span>
                            </div>
                            <div
                                v-for="req in filteredRequests(requestsInFolder(collection, folder.id))"
                                :key="req.id"
                                draggable="true"
                                @dragstart="onDragStart($event, req, collection)"
                                @dragover.prevent
                                @drop="onDrop($event, req, collection)"
                            >
                                <button
                                    type="button"
                                    @click="$emit('load-request', req)"
                                    @contextmenu.prevent="openContextMenu($event, 'request', req, collection)"
                                    class="group flex w-full items-center gap-2 rounded px-2 py-1 text-left pm-hover"
                                    :style="{ paddingLeft: (24 + folderDepth(folder, collection) * 16) + 'px' }"
                                    :class="req.id === activeRequestId ? 'pm-active' : ''"
                                >
                                    <span :class="'shrink-0 font-mono text-[11px] font-bold ' + methodClass(req.method)">{{ (req.method || 'GET').toUpperCase() }}</span>
                                    <span class="min-w-0 flex-1 truncate text-sm pm-text-2">{{ req.name || req.url }}</span>
                                    <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                                        <span @click.stop="moveRequest(req, collection, -1)" title="Move up" class="rounded px-1 py-0.5 pm-muted hover:pm-active">↑</span>
                                        <span @click.stop="moveRequest(req, collection, 1)" title="Move down" class="rounded px-1 py-0.5 pm-muted hover:pm-active">↓</span>
                                        <span @click.stop="duplicateRequest(req)" title="Duplicate" class="rounded px-1 py-0.5 pm-muted hover:pm-active">⧉</span>
                                        <span @click.stop="askDelete('request', req)" title="Delete" class="rounded px-1 py-0.5 pm-muted hover:pm-active hover:text-red-400">✕</span>
                                    </span>
                                </button>
                            </div>
                        </div>
                        <!-- Top-level requests (no folder) -->
                        <div
                            v-for="req in filteredRequests(requestsInFolder(collection, null))"
                            :key="req.id"
                            draggable="true"
                            @dragstart="onDragStart($event, req, collection)"
                            @dragover.prevent
                            @drop="onDrop($event, req, collection)"
                        >
                            <button
                                type="button"
                                @click="$emit('load-request', req)"
                                @contextmenu.prevent="openContextMenu($event, 'request', req, collection)"
                                class="group flex w-full items-center gap-2 rounded px-2 py-1 text-left pm-hover"
                                :class="req.id === activeRequestId ? 'pm-active' : ''"
                            >
                                <span :class="'shrink-0 font-mono text-[11px] font-bold ' + methodClass(req.method)">{{ (req.method || 'GET').toUpperCase() }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm pm-text-2">{{ req.name || req.url }}</span>
                                <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                                    <span @click.stop="moveRequest(req, collection, -1)" title="Move up" class="rounded px-1 py-0.5 pm-muted hover:pm-active">↑</span>
                                    <span @click.stop="moveRequest(req, collection, 1)" title="Move down" class="rounded px-1 py-0.5 pm-muted hover:pm-active">↓</span>
                                    <span @click.stop="duplicateRequest(req)" title="Duplicate" class="rounded px-1 py-0.5 pm-muted hover:pm-active">⧉</span>
                                    <span @click.stop="askDelete('request', req)" title="Delete" class="rounded px-1 py-0.5 pm-muted hover:pm-active hover:text-red-400">✕</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ ENVIRONMENTS ============ -->
        <div v-show="tab === 'environments'" class="flex min-h-0 flex-1 flex-col">
            <div class="p-2">
                <button
                    type="button"
                    @click="openEnvironmentEditor(null)"
                    class="pm-accent-bg w-full rounded px-3 py-2 text-sm font-medium text-white"
                >
                    + New Environment
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-4">
                <p v-if="store.environments.length === 0" class="px-2 py-4 text-sm pm-faint">
                    No environments yet. Add one to use {{variables}} in URLs.
                </p>
                <div
                    v-for="env in store.environments"
                    :key="env.id"
                    class="group mb-1 flex items-center gap-2 rounded px-2 py-2 pm-hover"
                >
                    <button type="button" @click="activateEnvironment(env)" class="min-w-0 flex-1 text-left" :title="env.id === store.activeEnvironmentId ? 'Active environment' : 'Click to activate'">
                        <span class="block truncate text-sm" :class="env.id === store.activeEnvironmentId ? 'font-medium pm-accent-text' : 'pm-text-2'">
                            {{ env.id === store.activeEnvironmentId ? '● ' : '' }}{{ env.name }}
                        </span>
                        <span class="block truncate text-xs pm-faint">
                            {{ (env.variables || []).filter((v) => v.enabled !== false).length }} variables
                        </span>
                    </button>
                    <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                        <button type="button" @click="openEnvironmentEditor(env)" title="Edit" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active">✎</button>
                        <button type="button" @click="askDelete('environment', env)" title="Delete" class="rounded px-1.5 py-0.5 pm-text-2 hover:pm-active hover:text-red-400">✕</button>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============ HISTORY ============ -->
        <div v-show="tab === 'history'" class="flex min-h-0 flex-1 flex-col">
            <div class="p-2">
                <button
                    type="button"
                    @click="askDelete('clear-history', null)"
                    :disabled="store.histories.length === 0"
                    class="w-full rounded border pm-border px-3 py-2 text-sm pm-text-2 hover:pm-active disabled:opacity-50"
                >
                    Clear History
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-4">
                <p v-if="store.histories.length === 0" class="px-2 py-4 text-sm pm-faint">
                    No requests sent yet.
                </p>
                <div v-for="item in filteredHistories" :key="item.id" class="group mb-1 flex items-center gap-2 rounded px-2 py-1.5 pm-hover">
                    <button type="button" @click="loadHistory(item)" class="min-w-0 flex-1 text-left" title="Restore this request and show its stored response">
                        <span class="flex items-center gap-2">
                            <span :class="'shrink-0 font-mono text-[11px] font-bold ' + methodClass(item.method)">{{ (item.method || 'GET').toUpperCase() }}</span>
                            <span v-if="item.status" class="shrink-0 text-xs" :class="item.status >= 200 && item.status < 300 ? 'status-ok' : 'status-error'">{{ item.status }}</span>
                            <span class="shrink-0 text-[11px] pm-faint">{{ item.created_at ? item.created_at.slice(0, 10) : '' }}</span>
                        </span>
                        <span class="mt-0.5 block truncate text-xs pm-muted">{{ item.url }}</span>
                    </button>
                    <button type="button" @click="deleteHistoryItem(item)" title="Delete" class="hidden shrink-0 rounded px-1.5 py-0.5 pm-muted hover:pm-active hover:text-red-400 group-hover:block">✕</button>
                </div>
                <button
                    v-if="store.historyPage < store.historyLastPage"
                    type="button"
                    @click="loadMore"
                    class="mt-2 w-full rounded border pm-border px-3 py-1.5 text-xs pm-text-2 hover:pm-active"
                >
                    Load more (page {{ store.historyPage }} of {{ store.historyLastPage }})
                </button>
                <p v-else-if="store.histories.length > 0" class="mt-2 text-center text-[11px] pm-faint">{{ store.historyTotal }} entries</p>
            </div>
        </div>

        <!-- Context menu (right-click) -->
        <div
            v-if="contextMenu.open"
            class="pm-panel fixed z-50 w-48 rounded border pm-border py-1 shadow-xl"
            :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }"
        >
            <button v-if="contextMenu.kind === 'request'" type="button" @click="contextOpenInNewTab" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">Open in New Tab</button>
            <button v-if="contextMenu.kind === 'request'" type="button" @click="contextDuplicate" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">Duplicate</button>
            <button v-if="contextMenu.kind === 'collection'" type="button" @click="contextExport" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">Export</button>
            <button type="button" @click="contextRename" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">Rename</button>
            <button type="button" @click="contextDelete" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">Delete</button>
        </div>

        <!-- Prompt modal: new collection / folder / request name / rename -->
        <Modal
            :open="prompt.open"
            :title="prompt.title"
            :confirm-label="prompt.confirmLabel"
            :confirm-disabled="!prompt.value.trim()"
            @close="prompt.open = false"
            @confirm="confirmPrompt"
        >
            <label class="mb-1 block text-xs font-medium pm-muted">Name</label>
            <input
                v-model="prompt.value"
                type="text"
                placeholder="Name"
                class="w-full rounded border pm-input px-3 py-2 text-sm outline-none focus:border-[#FF6C37]"
                @keyup.enter="confirmPrompt"
            >
            <p v-if="prompt.error" class="mt-3 text-sm text-red-400">{{ prompt.error }}</p>
        </Modal>

        <!-- Confirm delete modal -->
        <Modal
            :open="confirmDelete.open"
            title="Delete?"
            confirm-label="Delete"
            @close="confirmDelete.open = false"
            @confirm="doDelete"
        >
            <p class="text-sm pm-text-2">{{ confirmDelete.message }}</p>
        </Modal>

        <!-- Environment editor modal -->
        <Modal
            :open="envEditor.open"
            :title="envEditor.id ? 'Edit Environment' : 'New Environment'"
            :confirm-label="envEditor.id ? 'Save' : 'Create'"
            :confirm-disabled="!envEditor.name.trim()"
            @close="envEditor.open = false"
            @confirm="saveEnvironment"
        >
            <label class="mb-1 block text-xs font-medium pm-muted">Name</label>
            <input
                v-model="envEditor.name"
                type="text"
                placeholder="Environment name"
                class="mb-4 w-full rounded border pm-input px-3 py-2 text-sm outline-none focus:border-[#FF6C37]"
            >
            <label class="mb-1 block text-xs font-medium pm-muted">Variables</label>
            <KeyValueEditor :rows="envEditor.variables" key-placeholder="Variable" value-placeholder="Value" />
            <p v-if="envEditor.error" class="mt-3 text-sm text-red-400">{{ envEditor.error }}</p>
        </Modal>
    </aside>
</template>

<script>
import { api, errMessage } from '../api.js'
import { store, loadCollections, loadCollectionDetail, loadEnvironments, loadHistories, loadMoreHistories } from '../store.js'
import Modal from './Modals.vue'
import KeyValueEditor from './KeyValueEditor.vue'
import { methodClass } from '../utils/methods.js'

export default {
    name: 'Sidebar',
    components: { Modal, KeyValueEditor },
    props: {
        activeRequestId: { type: [Number, String], default: null },
        activeTab: { type: String, default: 'collections' },
    },
    emits: ['load-request', 'load-request-new-tab', 'load-history', 'clear-request'],
    data() {
        return {
            store,
            tabs: [
                { key: 'collections', label: 'Collections' },
                { key: 'environments', label: 'Environments' },
                { key: 'history', label: 'History' },
            ],
            tab: this.activeTab,
            expanded: {},
            prompt: { open: false, mode: '', title: '', confirmLabel: 'Create', value: '', target: null, error: '' },
            confirmDelete: { open: false, kind: '', target: null, message: '' },
            envEditor: { open: false, id: null, name: '', variables: [], error: '' },
            contextMenu: { open: false, kind: '', target: null, collection: null, x: 0, y: 0 },
            dragRequest: null,
        }
    },
    computed: {
        filteredCollections() {
            const q = (store.searchQuery || '').trim().toLowerCase()
            if (!q) return store.collections
            return store.collections.filter((c) => {
                if (c.name.toLowerCase().includes(q)) return true
                return this.allRequests(c).some((r) => (r.name || '').toLowerCase().includes(q) || (r.url || '').toLowerCase().includes(q))
            })
        },
        filteredHistories() {
            const q = (store.searchQuery || '').trim().toLowerCase()
            if (!q) return store.histories
            return store.histories.filter((h) => (h.url || '').toLowerCase().includes(q) || (h.method || '').toLowerCase().includes(q))
        },
    },
    watch: {
        activeTab(value) {
            this.tab = value
        },
    },
    methods: {
        methodClass,
        filteredRequests(reqs) {
            const q = (store.searchQuery || '').trim().toLowerCase()
            if (!q) return reqs
            return reqs.filter((r) => (r.name || '').toLowerCase().includes(q) || (r.url || '').toLowerCase().includes(q))
        },
        collectionRequestCount(collection) {
            return this.allRequests(collection).length
        },
        expandAll() {
            store.collections.forEach((c) => {
                this.expanded[c.id] = true
                if (!c._treeLoaded) {
                    loadCollectionDetail(c.id).then(() => { c._treeLoaded = true }).catch(() => {})
                }
            })
        },
        collapseAll() {
            this.expanded = {}
        },
        async toggleCollection(collection) {
            const id = collection.id
            this.expanded[id] = !this.expanded[id]
            if (this.expanded[id] && !collection._treeLoaded) {
                try {
                    await loadCollectionDetail(id)
                    collection._treeLoaded = true
                } catch (e) {
                    // Tree stays as listed; the request can still be opened from search.
                }
            }
        },
        // All requests of a collection, including ones nested under folders.
        allRequests(collection) {
            const out = []
            ;(collection.requests || []).forEach((r) => out.push(r))
            ;(collection.folders || []).forEach((f) => {
                ;(f.requests || []).forEach((r) => {
                    if (r.folder_id === undefined || r.folder_id === null) r.folder_id = f.id
                    out.push(r)
                })
            })
            return out
        },
        requestsInFolder(collection, folderId) {
            // sort_order is honoured now (bug §2.7): sort_order, then name.
            return this.allRequests(collection)
                .filter((r) => {
                    const fid = r.folder_id === undefined ? null : r.folder_id
                    return folderId === null ? (fid === null || fid === undefined) : fid === folderId
                })
                .sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0) || String(a.name || '').localeCompare(String(b.name || '')))
        },
        sortedFolders(collection) {
            const folders = (collection.folders || []).slice()
            folders.sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0) || this.folderDepth(a, collection) - this.folderDepth(b, collection) || String(a.name).localeCompare(String(b.name)))
            return folders
        },
        folderDepth(folder, collection) {
            let depth = 0
            let current = folder
            const folders = collection.folders || []
            while (current && current.parent_id) {
                depth += 1
                current = folders.find((f) => f.id === current.parent_id)
                if (depth > 10) break
            }
            return depth
        },

        // ---- Reorder: up/down buttons + drag-drop (same parent only) ----
        async moveRequest(req, collection, dir) {
            const siblings = this.requestsInFolder(collection, req.folder_id || null)
            const idx = siblings.findIndex((r) => r.id === req.id)
            const other = siblings[idx + dir]
            if (!other) return
            await this.swapSortOrders('request', req, other, collection)
        },
        async moveFolder(folder, collection, dir) {
            const siblings = this.sortedFolders(collection).filter((f) => (f.parent_id || null) === (folder.parent_id || null))
            const idx = siblings.findIndex((f) => f.id === folder.id)
            const other = siblings[idx + dir]
            if (!other) return
            await this.swapSortOrders('folder', folder, other, collection)
        },
        async swapSortOrders(kind, a, b, collection) {
            const aOrder = a.sort_order || 0
            const bOrder = b.sort_order || 0
            // Give them distinct orders even when both are 0.
            const newA = bOrder === aOrder ? aOrder + 1 : bOrder
            const newB = aOrder
            try {
                if (kind === 'request') {
                    await api.updateRequest(a.id, { sort_order: newA })
                    await api.updateRequest(b.id, { sort_order: newB })
                } else {
                    await api.updateFolder(a.id, { sort_order: newA })
                    await api.updateFolder(b.id, { sort_order: newB })
                }
                await loadCollectionDetail(collection.id)
            } catch (e) {
                alert('Reorder failed: ' + errMessage(e))
            }
        },
        onDragStart(event, req) {
            this.dragRequest = req
            event.dataTransfer.effectAllowed = 'move'
            try { event.dataTransfer.setData('text/plain', String(req.id)) } catch (e) { /* ignore */ }
        },
        async onDrop(event, targetReq, collection) {
            const dragged = this.dragRequest
            this.dragRequest = null
            if (!dragged || dragged.id === targetReq.id) return
            // Only reorder within the same folder/collection.
            if ((dragged.folder_id || null) !== (targetReq.folder_id || null)) return
            const siblings = this.requestsInFolder(collection, targetReq.folder_id || null)
            const from = siblings.findIndex((r) => r.id === dragged.id)
            const to = siblings.findIndex((r) => r.id === targetReq.id)
            if (from === -1 || to === -1 || from === to) return
            // Reassign sort_order across the sibling list.
            const ordered = siblings.slice()
            ordered.splice(to, 0, ordered.splice(from, 1)[0])
            try {
                for (let i = 0; i < ordered.length; i++) {
                    await api.updateRequest(ordered[i].id, { sort_order: i })
                }
                await loadCollectionDetail(collection.id)
            } catch (e) {
                alert('Reorder failed: ' + errMessage(e))
            }
        },

        // ---- Context menu ----
        openContextMenu(event, kind, target, collection) {
            this.contextMenu = { open: true, kind, target, collection: collection || null, x: event.clientX, y: event.clientY }
        },
        closeContextMenu() {
            this.contextMenu.open = false
        },
        contextOpenInNewTab() {
            this.$emit('load-request-new-tab', this.contextMenu.target)
            this.closeContextMenu()
        },
        async contextDuplicate() {
            const req = this.contextMenu.target
            this.closeContextMenu()
            await this.duplicateRequest(req)
        },
        contextExport() {
            const collection = this.contextMenu.target
            this.closeContextMenu()
            this.exportCollection(collection)
        },
        contextRename() {
            const kind = this.contextMenu.kind
            const target = this.contextMenu.target
            this.closeContextMenu()
            if (kind === 'collection') this.openPrompt('rename-collection', target)
            else if (kind === 'folder') this.openPrompt('rename-folder', target)
            else if (kind === 'request') this.openPrompt('rename-request', target)
        },
        contextDelete() {
            const kind = this.contextMenu.kind
            const target = this.contextMenu.target
            this.closeContextMenu()
            this.askDelete(kind, target)
        },

        // ---- Prompt modal (create / rename) ----
        openPrompt(mode, target) {
            const titles = {
                collection: 'New Collection',
                folder: 'New Folder',
                'folder-child': 'New Sub-folder',
                request: 'New Request',
                'request-folder': 'New Request',
                'rename-collection': 'Rename Collection',
                'rename-folder': 'Rename Folder',
                'rename-request': 'Rename Request',
            }
            this.prompt = {
                open: true,
                mode,
                title: titles[mode] || 'New',
                confirmLabel: mode.startsWith('rename') ? 'Rename' : 'Create',
                value: mode.startsWith('rename') && target ? target.name : '',
                target,
                error: '',
            }
        },
        async confirmPrompt() {
            const name = this.prompt.value.trim()
            if (!name) return
            const mode = this.prompt.mode
            const target = this.prompt.target
            try {
                if (mode === 'collection') {
                    await api.createCollection({ workspace_id: store.activeWorkspaceId, name })
                    await loadCollections()
                } else if (mode === 'folder') {
                    await api.createFolder(target.id, { name })
                    await loadCollectionDetail(target.id)
                } else if (mode === 'folder-child') {
                    await api.createFolder(target.collection.id, { name, parent_id: target.folder.id })
                    await loadCollectionDetail(target.collection.id)
                } else if (mode === 'request') {
                    const req = await api.createRequest(target.id, {
                        name, method: 'GET', url: '', params: [], headers: [],
                        auth_type: 'none', auth: {}, body_type: 'none', body: {},
                    })
                    await loadCollectionDetail(target.id)
                    this.$emit('load-request', req)
                } else if (mode === 'request-folder') {
                    const req = await api.createRequest(target.collection.id, {
                        name, method: 'GET', url: '', params: [], headers: [],
                        auth_type: 'none', auth: {}, body_type: 'none', body: {},
                        folder_id: target.folder.id,
                    })
                    await loadCollectionDetail(target.collection.id)
                    this.$emit('load-request', req)
                } else if (mode === 'rename-collection') {
                    await api.updateCollection(target.id, { name })
                    await loadCollections()
                } else if (mode === 'rename-folder') {
                    await api.updateFolder(target.id, { name })
                    await loadCollections()
                    for (const c of store.collections) {
                        if (this.expanded[c.id]) await loadCollectionDetail(c.id)
                    }
                } else if (mode === 'rename-request') {
                    await api.updateRequest(target.id, { name })
                    await loadCollections()
                    for (const c of store.collections) {
                        if (this.expanded[c.id]) await loadCollectionDetail(c.id)
                    }
                }
                this.prompt.open = false
            } catch (e) {
                this.prompt.error = errMessage(e)
            }
        },

        // ---- Delete flow ----
        askDelete(kind, target) {
            const names = {
                collection: 'Delete collection "' + (target && target.name) + '" and everything in it?',
                folder: 'Delete folder "' + (target && target.name) + '" and its requests?',
                request: 'Delete request "' + (target && target.name) + '"?',
                environment: 'Delete environment "' + (target && target.name) + '"?',
                'clear-history': 'Clear all history?',
            }
            this.confirmDelete = { open: true, kind, target, message: names[kind] || 'Are you sure?' }
        },
        async doDelete() {
            const kind = this.confirmDelete.kind
            const target = this.confirmDelete.target
            this.confirmDelete.open = false
            try {
                if (kind === 'collection') {
                    await api.deleteCollection(target.id)
                    await loadCollections()
                } else if (kind === 'folder') {
                    await api.deleteFolder(target.id)
                    await loadCollections()
                    for (const c of store.collections) {
                        if (this.expanded[c.id]) await loadCollectionDetail(c.id)
                    }
                } else if (kind === 'request') {
                    await api.deleteRequest(target.id)
                    if (target.id === this.activeRequestId) this.$emit('clear-request')
                    await loadCollections()
                    for (const c of store.collections) {
                        if (this.expanded[c.id]) await loadCollectionDetail(c.id)
                    }
                } else if (kind === 'environment') {
                    await api.deleteEnvironment(target.id)
                    if (target.id === store.activeEnvironmentId) store.activeEnvironmentId = null
                    await loadEnvironments()
                } else if (kind === 'clear-history') {
                    await api.clearHistories()
                    await loadHistories(1)
                }
            } catch (e) {
                alert('Delete failed: ' + errMessage(e))
            }
        },
        async duplicateRequest(req) {
            try {
                await api.duplicateRequest(req.id)
                await loadCollections()
                for (const c of store.collections) {
                    if (this.expanded[c.id]) await loadCollectionDetail(c.id)
                }
            } catch (e) {
                alert('Duplicate failed: ' + errMessage(e))
            }
        },
        async exportCollection(collection) {
            try {
                const res = await api.exportCollection(collection.id)
                const blob = res.data
                const url = window.URL.createObjectURL(blob)
                const a = document.createElement('a')
                a.href = url
                a.download = (collection.name || 'collection').replace(/[^\w\-]+/g, '_') + '.json'
                document.body.appendChild(a)
                a.click()
                a.remove()
                window.URL.revokeObjectURL(url)
            } catch (e) {
                alert('Export failed: ' + errMessage(e))
            }
        },

        // ---- Environments ----
        async activateEnvironment(env) {
            store.activeEnvironmentId = env.id
            try { await api.activateEnvironment(env.id) } catch (e) { /* local selection still stands */ }
            await loadEnvironments()
        },
        openEnvironmentEditor(env) {
            this.envEditor = {
                open: true,
                id: env ? env.id : null,
                name: env ? env.name : '',
                variables: env
                    ? (env.variables || []).map((v) => ({ key: v.key || '', value: v.value || '', enabled: v.enabled !== false }))
                    : [{ key: '', value: '', enabled: true }],
                error: '',
            }
        },
        async saveEnvironment() {
            const variables = this.envEditor.variables
                .filter((v) => v.key.trim() !== '')
                .map((v) => ({ key: v.key.trim(), value: v.value, enabled: v.enabled !== false }))
            try {
                if (this.envEditor.id) {
                    await api.updateEnvironment(this.envEditor.id, { name: this.envEditor.name.trim(), variables })
                } else {
                    await api.createEnvironment({
                        workspace_id: store.activeWorkspaceId,
                        name: this.envEditor.name.trim(),
                        variables,
                    })
                }
                this.envEditor.open = false
                await loadEnvironments()
            } catch (e) {
                this.envEditor.error = errMessage(e)
            }
        },

        // ---- History ----
        async loadHistory(item) {
            // Fetch the full entry (snapshot + stored response) when needed.
            let full = item
            if (!item.request_snapshot || !item.response_body) {
                try { full = await api.getHistory(item.id) } catch (e) { /* list copy is enough */ }
            }
            this.$emit('load-history', full)
        },
        async loadMore() {
            await loadMoreHistories()
        },
        async deleteHistoryItem(item) {
            try {
                await api.deleteHistory(item.id)
                await loadHistories(1)
            } catch (e) {
                alert('Delete failed: ' + errMessage(e))
            }
        },
    },
}
</script>
