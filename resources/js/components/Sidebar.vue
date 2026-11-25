<template>
    <aside class="flex h-full w-80 shrink-0 flex-col border-r border-neutral-800 bg-[#212121]">
        <div class="flex border-b border-neutral-800">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                @click="tab = t.key"
                :class="tab === t.key ? 'border-[#FF6C37] text-neutral-100' : 'border-transparent text-neutral-400 hover:text-neutral-200'"
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
                    class="w-full rounded bg-[#FF6C37] px-3 py-2 text-sm font-medium text-white hover:bg-[#e85a2a]"
                >
                    + New Collection
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-4">
                <p v-if="store.collections.length === 0" class="px-2 py-4 text-sm text-neutral-500">
                    No collections yet. Create one to start saving requests.
                </p>
                <div v-for="collection in store.collections" :key="collection.id" class="mb-1">
                    <div class="group flex items-center gap-1 rounded px-2 py-1.5 hover:bg-neutral-700/60">
                        <button type="button" @click="toggleCollection(collection)" class="w-4 shrink-0 text-neutral-400">
                            {{ expanded[collection.id] ? '▾' : '▸' }}
                        </button>
                        <button type="button" @click="toggleCollection(collection)" class="min-w-0 flex-1 truncate text-left text-sm font-medium text-neutral-200">
                            {{ collection.name }}
                        </button>
                        <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                            <button type="button" @click="openPrompt('request', collection)" title="New request" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600">+ Req</button>
                            <button type="button" @click="openPrompt('folder', collection)" title="New folder" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600">+ Dir</button>
                            <button type="button" @click="exportCollection(collection)" title="Export collection" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600">↓</button>
                            <button type="button" @click="openPrompt('rename-collection', collection)" title="Rename" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600">✎</button>
                            <button type="button" @click="askDelete('collection', collection)" title="Delete" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600 hover:text-red-400">✕</button>
                        </span>
                    </div>

                    <div v-if="expanded[collection.id]">
                        <!-- Folders (flat list, indented by nesting depth) -->
                        <div v-for="folder in sortedFolders(collection)" :key="'f' + folder.id">
                            <div
                                class="group flex items-center gap-1 rounded px-2 py-1 hover:bg-neutral-700/60"
                                :style="{ paddingLeft: (8 + folderDepth(folder, collection) * 16) + 'px' }"
                            >
                                <span class="text-neutral-500">▸</span>
                                <span class="min-w-0 flex-1 truncate text-sm text-neutral-300">{{ folder.name }}</span>
                                <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                                    <button type="button" @click="openPrompt('request-folder', { collection, folder })" title="New request in folder" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600">+ Req</button>
                                    <button type="button" @click="openPrompt('folder-child', { collection, folder })" title="New sub-folder" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600">+ Dir</button>
                                    <button type="button" @click="openPrompt('rename-folder', folder)" title="Rename" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600">✎</button>
                                    <button type="button" @click="askDelete('folder', folder)" title="Delete" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600 hover:text-red-400">✕</button>
                                </span>
                            </div>
                            <div v-for="req in requestsInFolder(collection, folder.id)" :key="req.id">
                                <button
                                    type="button"
                                    @click="$emit('load-request', req)"
                                    class="group flex w-full items-center gap-2 rounded px-2 py-1 text-left hover:bg-neutral-700/60"
                                    :style="{ paddingLeft: (24 + folderDepth(folder, collection) * 16) + 'px' }"
                                    :class="req.id === activeRequestId ? 'bg-neutral-700/70' : ''"
                                >
                                    <span :class="'shrink-0 rounded px-1 py-0.5 text-[10px] font-bold ' + methodClass(req.method)">{{ (req.method || 'GET').toUpperCase() }}</span>
                                    <span class="min-w-0 flex-1 truncate text-sm text-neutral-300">{{ req.name || req.url }}</span>
                                    <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                                        <span @click.stop="duplicateRequest(req)" title="Duplicate" class="rounded px-1 py-0.5 text-neutral-400 hover:bg-neutral-600">⧉</span>
                                        <span @click.stop="askDelete('request', req)" title="Delete" class="rounded px-1 py-0.5 text-neutral-400 hover:bg-neutral-600 hover:text-red-400">✕</span>
                                    </span>
                                </button>
                            </div>
                        </div>
                        <!-- Top-level requests (no folder) -->
                        <div v-for="req in requestsInFolder(collection, null)" :key="req.id">
                            <button
                                type="button"
                                @click="$emit('load-request', req)"
                                class="group flex w-full items-center gap-2 rounded px-2 py-1 text-left hover:bg-neutral-700/60"
                                :class="req.id === activeRequestId ? 'bg-neutral-700/70' : ''"
                            >
                                <span :class="'shrink-0 rounded px-1 py-0.5 text-[10px] font-bold ' + methodClass(req.method)">{{ (req.method || 'GET').toUpperCase() }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm text-neutral-300">{{ req.name || req.url }}</span>
                                <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                                    <span @click.stop="duplicateRequest(req)" title="Duplicate" class="rounded px-1 py-0.5 text-neutral-400 hover:bg-neutral-600">⧉</span>
                                    <span @click.stop="askDelete('request', req)" title="Delete" class="rounded px-1 py-0.5 text-neutral-400 hover:bg-neutral-600 hover:text-red-400">✕</span>
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
                    class="w-full rounded bg-[#FF6C37] px-3 py-2 text-sm font-medium text-white hover:bg-[#e85a2a]"
                >
                    + New Environment
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-4">
                <p v-if="store.environments.length === 0" class="px-2 py-4 text-sm text-neutral-500">
                    No environments yet. Add one to use {{variables}} in URLs.
                </p>
                <div
                    v-for="env in store.environments"
                    :key="env.id"
                    class="group mb-1 flex items-center gap-2 rounded px-2 py-2 hover:bg-neutral-700/60"
                >
                    <button type="button" @click="activateEnvironment(env)" class="min-w-0 flex-1 text-left" :title="env.id === store.activeEnvironmentId ? 'Active environment' : 'Click to activate'">
                        <span class="block truncate text-sm" :class="env.id === store.activeEnvironmentId ? 'font-medium text-[#FF6C37]' : 'text-neutral-200'">
                            {{ env.id === store.activeEnvironmentId ? '● ' : '' }}{{ env.name }}
                        </span>
                        <span class="block truncate text-xs text-neutral-500">
                            {{ (env.variables || []).filter((v) => v.enabled !== false).length }} variables
                        </span>
                    </button>
                    <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                        <button type="button" @click="openEnvironmentEditor(env)" title="Edit" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600">✎</button>
                        <button type="button" @click="askDelete('environment', env)" title="Delete" class="rounded px-1.5 py-0.5 text-neutral-300 hover:bg-neutral-600 hover:text-red-400">✕</button>
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
                    class="w-full rounded border border-neutral-700 px-3 py-2 text-sm text-neutral-300 hover:bg-neutral-700 disabled:opacity-50"
                >
                    Clear History
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-4">
                <p v-if="store.histories.length === 0" class="px-2 py-4 text-sm text-neutral-500">
                    No requests sent yet.
                </p>
                <div v-for="item in store.histories" :key="item.id" class="group mb-1 flex items-center gap-2 rounded px-2 py-1.5 hover:bg-neutral-700/60">
                    <button type="button" @click="loadHistory(item)" class="min-w-0 flex-1 text-left">
                        <span class="flex items-center gap-2">
                            <span :class="'shrink-0 rounded px-1 py-0.5 text-[10px] font-bold ' + methodClass(item.method)">{{ (item.method || 'GET').toUpperCase() }}</span>
                            <span v-if="item.status" class="shrink-0 text-xs" :class="item.status >= 200 && item.status < 300 ? 'text-green-400' : 'text-red-400'">{{ item.status }}</span>
                        </span>
                        <span class="mt-0.5 block truncate text-xs text-neutral-400">{{ item.url }}</span>
                    </button>
                    <button type="button" @click="deleteHistoryItem(item)" title="Delete" class="hidden shrink-0 rounded px-1.5 py-0.5 text-neutral-400 hover:bg-neutral-600 hover:text-red-400 group-hover:block">✕</button>
                </div>
            </div>
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
            <label class="mb-1 block text-xs font-medium text-neutral-400">Name</label>
            <input
                v-model="prompt.value"
                type="text"
                placeholder="Name"
                class="w-full rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
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
            <p class="text-sm text-neutral-300">{{ confirmDelete.message }}</p>
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
            <label class="mb-1 block text-xs font-medium text-neutral-400">Name</label>
            <input
                v-model="envEditor.name"
                type="text"
                placeholder="Environment name"
                class="mb-4 w-full rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
            >
            <label class="mb-1 block text-xs font-medium text-neutral-400">Variables</label>
            <KeyValueEditor :rows="envEditor.variables" key-placeholder="Variable" value-placeholder="Value" />
            <p v-if="envEditor.error" class="mt-3 text-sm text-red-400">{{ envEditor.error }}</p>
        </Modal>
    </aside>
</template>

<script>
import { api, errMessage } from '../api.js'
import { store, loadCollections, loadCollectionDetail, loadEnvironments, loadHistories } from '../store.js'
import Modal from './Modals.vue'
import KeyValueEditor from './KeyValueEditor.vue'

const METHOD_CLASSES = {
    GET: 'bg-green-900/70 text-green-300',
    POST: 'bg-orange-900/70 text-orange-300',
    PUT: 'bg-blue-900/70 text-blue-300',
    PATCH: 'bg-purple-900/70 text-purple-300',
    DELETE: 'bg-red-900/70 text-red-300',
}

export default {
    name: 'Sidebar',
    components: { Modal, KeyValueEditor },
    props: {
        activeRequestId: { type: [Number, String], default: null },
        activeTab: { type: String, default: 'collections' },
    },
    emits: ['load-request', 'load-history', 'clear-request'],
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
        }
    },
    watch: {
        activeTab(value) {
            this.tab = value
        },
    },
    methods: {
        methodClass(method) {
            return METHOD_CLASSES[(method || 'GET').toUpperCase()] || 'bg-neutral-700 text-neutral-300'
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
            return this.allRequests(collection).filter((r) => {
                const fid = r.folder_id === undefined ? null : r.folder_id
                return folderId === null ? (fid === null || fid === undefined) : fid === folderId
            })
        },
        sortedFolders(collection) {
            const folders = (collection.folders || []).slice()
            folders.sort((a, b) => this.folderDepth(a, collection) - this.folderDepth(b, collection))
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
                    // Reload the tree of every expanded collection so the name updates.
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
                    await loadHistories()
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
        loadHistory(item) {
            this.$emit('load-history', { method: item.method || 'GET', url: item.url || '' })
        },
        async deleteHistoryItem(item) {
            try {
                await api.deleteHistory(item.id)
                await loadHistories()
            } catch (e) {
                alert('Delete failed: ' + errMessage(e))
            }
        },
    },
}
</script>
