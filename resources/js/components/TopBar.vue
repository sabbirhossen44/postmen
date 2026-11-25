<template>
    <header class="pm-topbar flex h-12 shrink-0 items-center gap-3 border-b pm-border px-4">
        <div class="flex items-center gap-2">
            <span class="text-lg font-bold pm-text">Postmen<span class="pm-accent-text">.</span></span>
        </div>

        <div class="relative">
            <button
                type="button"
                @click="showNewMenu = !showNewMenu"
                class="pm-accent-bg rounded px-3 py-1.5 text-sm font-medium text-white"
                title="New tab, request or collection"
            >
                + New
            </button>
            <div v-if="showNewMenu" class="pm-panel absolute left-0 top-full z-50 mt-1 w-48 rounded border pm-border py-1 shadow-xl">
                <button type="button" @click="newTab" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">New Tab <span class="pm-faint">(Ctrl+T)</span></button>
                <button type="button" @click="newRequest" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">New Request</button>
                <button type="button" @click="showWorkspaceModal = true; showNewMenu = false" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">New Workspace</button>
            </div>
        </div>

        <button
            type="button"
            @click="$emit('open-runner')"
            class="rounded border pm-border px-3 py-1.5 text-sm pm-text-2 hover:pm-active"
            title="Collection Runner — run a whole collection in order"
        >
            ▶ Runner
        </button>

        <div class="flex items-center gap-2">
            <select
                :value="store.activeWorkspaceId"
                @change="onWorkspaceChange($event.target.value)"
                class="max-w-44 rounded border pm-input px-2 py-1.5 text-sm outline-none"
            >
                <option v-for="ws in store.workspaces" :key="ws.id" :value="ws.id">{{ ws.name }}</option>
                <option v-if="store.workspaces.length === 0" value="">No workspace</option>
            </select>
        </div>

        <!-- Global search: filters collections, requests and history in the sidebar -->
        <div class="min-w-0 flex-1">
            <input
                v-model="store.searchQuery"
                type="text"
                placeholder="Search collections, requests, history… (Ctrl/Cmd+K)"
                class="w-full max-w-md rounded border pm-input px-3 py-1.5 text-sm outline-none focus:border-[#FF6C37]"
            >
        </div>

        <button
            type="button"
            @click="showImportModal = true"
            class="rounded border pm-border px-3 py-1.5 text-sm pm-text-2 hover:pm-active"
            title="Import a Postman collection JSON file, or paste JSON"
        >
            Import
        </button>
        <input ref="importInput" type="file" accept=".json,application/json" class="hidden" @change="onImportFile">

        <div class="flex items-center gap-2">
            <select
                :value="store.activeEnvironmentId"
                @change="onEnvironmentChange($event.target.value)"
                class="max-w-44 rounded border pm-input px-2 py-1.5 text-sm outline-none"
                title="Active environment"
            >
                <option value="">No Environment</option>
                <option v-for="env in store.environments" :key="env.id" :value="env.id">{{ env.name }}</option>
            </select>
            <button
                type="button"
                @click="$emit('manage-environments')"
                class="rounded border pm-border px-2 py-1.5 text-sm pm-text-2 hover:pm-active"
                title="Manage environments"
            >
                Manage
            </button>
        </div>

        <button
            type="button"
            @click="toggleTheme"
            class="rounded border pm-border px-2 py-1.5 text-sm pm-text-2 hover:pm-active"
            :title="store.theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"
        >
            {{ store.theme === 'dark' ? '☀ Light' : '◐ Dark' }}
        </button>

        <button
            type="button"
            @click="$emit('toggle-console')"
            class="rounded border pm-border px-2 py-1.5 text-sm pm-text-2 hover:pm-active"
            title="Toggle console (Ctrl/Cmd+Alt+C)"
        >
            Console
        </button>

        <div class="relative">
            <button
                type="button"
                @click="showSettings = !showSettings"
                class="rounded border pm-border px-2 py-1.5 text-sm pm-text-2 hover:pm-active"
                title="Settings"
            >
                ⚙
            </button>
            <div v-if="showSettings" class="pm-panel absolute right-0 top-full z-50 mt-1 w-56 rounded border pm-border py-1 shadow-xl">
                <button type="button" @click="toggleTheme; showSettings = false" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">
                    Theme: {{ store.theme === 'dark' ? 'Dark' : 'Light' }} (click to switch)
                </button>
                <div class="border-t pm-border px-3 py-2 text-xs pm-muted">
                    Postmen — Postman-compatible API client.<br>
                    SSRF guard blocks localhost / private IPs (deliberate policy).
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 border-l pm-border pl-3">
            <span class="max-w-32 truncate text-sm pm-text-2" :title="store.user && store.user.email">
                {{ store.user ? (store.user.name || store.user.email) : '' }}
            </span>
            <button
                type="button"
                @click="logout"
                class="rounded border pm-border px-2 py-1.5 text-sm pm-text-2 hover:text-red-400"
            >
                Logout
            </button>
        </div>

        <Modal
            :open="showWorkspaceModal"
            title="New Workspace"
            confirm-label="Create"
            :confirm-disabled="!workspaceName.trim()"
            @close="showWorkspaceModal = false"
            @confirm="createWorkspace"
        >
            <label class="mb-1 block text-xs font-medium pm-muted">Name</label>
            <input
                v-model="workspaceName"
                type="text"
                placeholder="Workspace name"
                class="mb-3 w-full rounded border pm-input px-3 py-2 text-sm outline-none focus:border-[#FF6C37]"
            >
            <label class="mb-1 block text-xs font-medium pm-muted">Description</label>
            <input
                v-model="workspaceDescription"
                type="text"
                placeholder="Optional description"
                class="w-full rounded border pm-input px-3 py-2 text-sm outline-none focus:border-[#FF6C37]"
            >
            <p v-if="modalError" class="mt-3 text-sm text-red-400">{{ modalError }}</p>
        </Modal>

        <Modal
            :open="showImportModal"
            title="Import"
            :confirm-label="importConfirmLabel"
            :confirm-disabled="importConfirmDisabled"
            @close="showImportModal = false"
            @confirm="doImport"
        >
            <div class="mb-3 flex gap-1 border-b pm-border">
                <button
                    v-for="t in importTypes"
                    :key="t.key"
                    type="button"
                    @click="importType = t.key; modalError = ''"
                    :class="importType === t.key ? 'border-[#FF6C37] pm-text' : 'border-transparent pm-muted hover:pm-text'"
                    class="border-b-2 px-3 py-2 text-xs font-medium"
                >
                    {{ t.label }}
                </button>
            </div>

            <div v-if="importType === 'collection'">
                <p class="mb-2 text-sm pm-muted">Paste a Postman Collection v2.1 JSON document, or use the file picker.</p>
                <textarea
                    v-model="importJson"
                    rows="8"
                    spellcheck="false"
                    placeholder='{"info": {"name": "My API", "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"}, "item": []}'
                    class="w-full rounded border pm-input px-3 py-2 font-mono text-sm outline-none focus:border-[#FF6C37]"
                ></textarea>
                <button type="button" @click="triggerImport" class="mt-2 rounded border pm-border px-3 py-1.5 text-sm pm-text-2 hover:pm-active">
                    …or choose a .json file
                </button>
            </div>

            <div v-if="importType === 'curl'">
                <p class="mb-2 text-sm pm-muted">Paste a cURL command — it becomes a request in a new collection.</p>
                <textarea
                    v-model="importCurlText"
                    rows="8"
                    spellcheck="false"
                    placeholder="curl --request GET --url https://api.example.com/users --header 'Authorization: Bearer abc'"
                    class="w-full rounded border pm-input px-3 py-2 font-mono text-sm outline-none focus:border-[#FF6C37]"
                ></textarea>
                <p v-if="importType === 'curl'" class="mt-2 text-xs pm-faint">Uses POST /api/import/curl when the backend has it.</p>
            </div>

            <div v-if="importType === 'openapi'">
                <p class="mb-2 text-sm pm-muted">Paste an OpenAPI (Swagger) document as JSON, or choose a .json / .yaml file.</p>
                <textarea
                    v-model="openapiJson"
                    rows="8"
                    spellcheck="false"
                    placeholder='{"openapi": "3.0.0", "info": {"title": "My API", "version": "1.0.0"}, "paths": {}}'
                    class="w-full rounded border pm-input px-3 py-2 font-mono text-sm outline-none focus:border-[#FF6C37]"
                ></textarea>
                <button type="button" @click="$refs.openapiInput.click()" class="mt-2 rounded border pm-border px-3 py-1.5 text-sm pm-text-2 hover:pm-active">
                    …or choose a .json / .yaml file
                </button>
                <span v-if="openapiFileName" class="ml-2 text-xs pm-muted">{{ openapiFileName }}</span>
                <p class="mt-2 text-xs pm-faint">Uses POST /api/import/openapi when the backend has it.</p>
            </div>

            <p v-if="modalError" class="mt-3 text-sm text-red-400">{{ modalError }}</p>
        </Modal>
        <input ref="openapiInput" type="file" accept=".json,.yaml,.yml,application/json" class="hidden" @change="onOpenapiFile">

    </header>
</template>

<script>
import { api, errMessage } from '../api.js'
import { store, clearAuth, loadCollections, loadEnvironments, loadWorkspaces, openTab, setTheme } from '../store.js'
import Modal from './Modals.vue'

export default {
    name: 'TopBar',
    components: { Modal },
    emits: ['logged-out', 'imported', 'manage-environments', 'toggle-console', 'open-runner'],
    data() {
        return {
            store,
            showWorkspaceModal: false,
            showImportModal: false,
            showNewMenu: false,
            showSettings: false,
            workspaceName: '',
            workspaceDescription: '',
            importJson: '',
            importCurlText: '',
            openapiJson: '',
            openapiFile: null,
            openapiFileName: '',
            importType: 'collection',
            importTypes: [
                { key: 'collection', label: 'Collection JSON' },
                { key: 'curl', label: 'cURL' },
                { key: 'openapi', label: 'OpenAPI' },
            ],
            modalError: '',
        }
    },
    computed: {
        importConfirmLabel() {
            if (this.importType === 'curl') return 'Import cURL'
            if (this.importType === 'openapi') return 'Import OpenAPI'
            return 'Import JSON'
        },
        importConfirmDisabled() {
            if (this.importType === 'collection') return !this.importJson.trim()
            if (this.importType === 'curl') return !this.importCurlText.trim()
            return !this.openapiJson.trim() && !this.openapiFile
        },
    },
    methods: {
        toggleTheme() {
            setTheme(store.theme === 'dark' ? 'light' : 'dark')
        },
        newTab() {
            openTab()
            this.showNewMenu = false
        },
        newRequest() {
            openTab()
            this.showNewMenu = false
            this.$emit('imported')
        },
        async onWorkspaceChange(value) {
            store.activeWorkspaceId = value ? Number(value) : null
            store.activeEnvironmentId = null
            await Promise.all([loadCollections(), loadEnvironments()])
            this.$emit('imported')
        },
        async createWorkspace() {
            this.modalError = ''
            try {
                const ws = await api.createWorkspace({
                    name: this.workspaceName.trim(),
                    description: this.workspaceDescription.trim() || null,
                })
                await loadWorkspaces()
                if (ws && ws.id) {
                    store.activeWorkspaceId = ws.id
                    await Promise.all([loadCollections(), loadEnvironments()])
                }
                this.showWorkspaceModal = false
                this.workspaceName = ''
                this.workspaceDescription = ''
            } catch (e) {
                this.modalError = errMessage(e)
            }
        },
        async onEnvironmentChange(value) {
            const id = value ? Number(value) : null
            store.activeEnvironmentId = id
            if (id) {
                try { await api.activateEnvironment(id) } catch (e) { /* local selection still stands */ }
            }
        },
        triggerImport() {
            this.$refs.importInput.click()
        },
        async onImportFile(event) {
            const file = event.target.files && event.target.files[0]
            event.target.value = ''
            if (!file || !store.activeWorkspaceId) return
            try {
                await api.importCollection(store.activeWorkspaceId, file)
                await loadCollections()
                this.showImportModal = false
                this.$emit('imported')
            } catch (e) {
                this.modalError = errMessage(e)
                alert('Import failed: ' + errMessage(e))
            }
        },
        async importJsonText() {
            this.modalError = ''
            let parsed
            try {
                parsed = JSON.parse(this.importJson)
            } catch (e) {
                this.modalError = 'That is not valid JSON: ' + e.message
                return
            }
            try {
                await api.importCollection(store.activeWorkspaceId, parsed)
                await loadCollections()
                this.showImportModal = false
                this.importJson = ''
                this.$emit('imported')
            } catch (e) {
                this.modalError = errMessage(e)
            }
        },
        // Dispatch the import modal confirm by type.
        async doImport() {
            if (this.importType === 'curl') return this.importCurlText()
            if (this.importType === 'openapi') return this.importOpenapiText()
            return this.importJsonText()
        },
        async importCurlText() {
            this.modalError = ''
            try {
                await api.importCurl({
                    workspace_id: store.activeWorkspaceId,
                    command: this.importCurlText.trim(),
                })
                await loadCollections()
                this.showImportModal = false
                this.importCurlText = ''
                this.$emit('imported')
            } catch (e) {
                if (api.isMissingEndpoint(e)) {
                    this.modalError = 'cURL import is not available yet — the POST /api/import/curl endpoint is still being built.'
                } else {
                    this.modalError = errMessage(e)
                }
            }
        },
        onOpenapiFile(event) {
            const file = event.target.files && event.target.files[0]
            event.target.value = ''
            if (!file) return
            this.openapiFile = file
            this.openapiFileName = file.name
            // A YAML file cannot be pasted as JSON — the file wins.
            if (!/\.json$/i.test(file.name)) this.openapiJson = ''
        },
        async importOpenapiText() {
            this.modalError = ''
            try {
                if (this.openapiFile) {
                    await api.importOpenapi(this.openapiFile)
                } else {
                    let parsed
                    try {
                        parsed = JSON.parse(this.openapiJson)
                    } catch (e) {
                        this.modalError = 'That is not valid JSON: ' + e.message
                        return
                    }
                    await api.importOpenapi(parsed)
                }
                await loadCollections()
                this.showImportModal = false
                this.openapiJson = ''
                this.openapiFile = null
                this.openapiFileName = ''
                this.$emit('imported')
            } catch (e) {
                if (api.isMissingEndpoint(e)) {
                    this.modalError = 'OpenAPI import is not available yet — the POST /api/import/openapi endpoint is still being built.'
                } else {
                    this.modalError = errMessage(e)
                }
            }
        },
        async logout() {
            try { await api.logout() } catch (e) { /* token is cleared either way */ }
            clearAuth()
            this.$emit('logged-out')
        },
    },
}
</script>
