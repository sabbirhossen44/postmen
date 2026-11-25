<template>
    <header class="pm-topbar flex h-12 shrink-0 items-center gap-3 border-b pm-border px-4">
        <div class="flex items-center gap-2">
            <span class="text-lg font-bold pm-text">Postmen<span class="pm-accent-text">.</span></span>
        </div>

        <div class="relative">
            <button
                type="button"
                @click="showNewMenu = !showNewMenu"
                class="pm-btn pm-btn-primary"
                title="New tab, request or collection"
            >
                + New
            </button>
            <div v-if="showNewMenu" class="pm-panel absolute left-0 top-full z-50 mt-1 w-48 rounded-lg border pm-border py-1 shadow-xl">
                <button type="button" @click="newTab" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">New Tab <span class="pm-faint">(Ctrl+T)</span></button>
                <button type="button" @click="newRequest" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">New Request</button>
                <button type="button" @click="showWorkspaceModal = true; showNewMenu = false" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">New Workspace</button>
            </div>
        </div>

        <button
            type="button"
            @click="$emit('open-runner')"
            class="pm-btn pm-btn-ghost"
            title="Collection Runner — run a whole collection in order"
        >
            ▶ Runner
        </button>

        <div class="flex items-center gap-2">
            <select
                :value="store.activeWorkspaceId"
                @change="onWorkspaceChange($event.target.value)"
                class="pm-field max-w-44"
            >
                <option v-for="ws in store.workspaces" :key="ws.id" :value="ws.id">{{ ws.name }}</option>
                <option v-if="store.workspaces.length === 0" value="">No workspace</option>
            </select>
            <button
                v-if="activeWorkspace"
                type="button"
                @click="showTeamModal = true"
                class="rounded border pm-border px-3 py-1.5 text-sm pm-text-2 hover:pm-active"
                title="Team members, invitations and activity"
            >
                Team
            </button>
        </div>

        <!-- Sidebar filter, plus a button that opens the command palette -->
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <input
                v-model="store.searchQuery"
                type="text"
                placeholder="Filter collections, requests, history…"
                class="pm-field w-full max-w-md"
            >
            <button
                type="button"
                @click="$emit('open-palette')"
                class="pm-btn pm-btn-ghost shrink-0"
                title="Command palette (Ctrl/Cmd+K)"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="h-4 w-4"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
                <span class="hidden xl:inline">Search</span>
                <span class="pm-kbd">⌘K</span>
            </button>
        </div>

        <button
            type="button"
            @click="showImportModal = true"
            class="pm-btn pm-btn-ghost"
            title="Import a Postman collection JSON file, or paste JSON"
        >
            Import
        </button>
        <input ref="importInput" type="file" accept=".json,application/json" class="hidden" @change="onImportFile">

        <div class="relative flex items-center gap-2">
            <select
                :value="store.activeEnvironmentId"
                @change="onEnvironmentChange($event.target.value)"
                class="pm-field max-w-44"
                title="Active environment"
            >
                <option value="">No Environment</option>
                <option v-for="env in store.environments" :key="env.id" :value="env.id">{{ env.name }}</option>
            </select>
            <!-- Environment quick-look: current values at a glance, editable -->
            <button
                type="button"
                @click="toggleQuickLook"
                :disabled="!store.activeEnvironmentId"
                class="rounded border pm-border px-2 py-1.5 text-sm pm-text-2 hover:pm-active disabled:opacity-50"
                title="Quick-look: view and edit the active environment's variables"
            >
                👁
            </button>
            <div v-if="showQuickLook" class="pm-panel absolute right-0 top-full z-50 mt-1 w-[26rem] rounded border pm-border p-3 shadow-xl">
                <div class="mb-2 flex items-center gap-2">
                    <span class="text-sm font-medium pm-text">{{ activeEnvironmentName }}</span>
                    <span class="flex-1"></span>
                    <button type="button" @click="showQuickLook = false" class="rounded px-1.5 py-0.5 pm-muted hover:pm-text" title="Close">✕</button>
                </div>
                <p v-if="quickLookRows.length === 0" class="text-xs pm-faint">No variables in this environment.</p>
                <div v-else class="max-h-64 overflow-y-auto">
                    <div class="mb-1 flex gap-2 text-[11px] font-medium pm-muted">
                        <span class="w-1/4">Variable</span>
                        <span class="flex-1">Initial Value</span>
                        <span class="flex-1">Current Value</span>
                        <span class="w-14 shrink-0">Type</span>
                    </div>
                    <div v-for="(row, i) in quickLookRows" :key="i" class="mb-1 flex items-center gap-2">
                        <span class="w-1/4 truncate font-mono text-xs pm-text-2" :title="row.key">{{ row.key }}</span>
                        <span class="min-w-0 flex-1 truncate font-mono text-xs pm-muted" :title="row.initial">{{ row.initial }}</span>
                        <input
                            v-model="row.current"
                            :type="row.type === 'secret' && !row.showSecret ? 'password' : 'text'"
                            class="min-w-0 flex-1 rounded border pm-input px-2 py-1 text-xs outline-none focus:border-[#FF6C37]"
                        >
                        <span class="flex w-14 shrink-0 items-center gap-1 text-xs pm-muted">
                            {{ row.type }}
                            <button
                                v-if="row.type === 'secret'"
                                type="button"
                                @click="row.showSecret = !row.showSecret"
                                class="rounded px-1 py-0.5 pm-muted hover:pm-text"
                                :title="row.showSecret ? 'Hide value' : 'Show value'"
                            >{{ row.showSecret ? '🙈' : '👁' }}</button>
                        </span>
                    </div>
                </div>
                <p v-if="quickLookError" class="mt-2 text-xs text-red-400">{{ quickLookError }}</p>
                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" @click="$emit('manage-environments'); showQuickLook = false" class="rounded border pm-border px-2 py-1 text-xs pm-text-2 hover:pm-active">Open full editor</button>
                    <button type="button" @click="saveQuickLook" :disabled="quickLookSaving" class="pm-accent-bg rounded px-3 py-1 text-xs font-medium text-white disabled:opacity-50">
                        {{ quickLookSaving ? 'Saving…' : 'Save current values' }}
                    </button>
                </div>
            </div>
            <button
                type="button"
                @click="$emit('manage-environments')"
                class="pm-btn pm-btn-ghost"
                title="Manage environments"
            >
                Manage
            </button>
        </div>

        <button
            type="button"
            @click="toggleTheme"
            class="pm-btn pm-btn-ghost"
            :title="store.theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"
        >
            <svg v-if="store.theme === 'dark'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="h-4 w-4"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" /></svg>
            {{ store.theme === 'dark' ? 'Light' : 'Dark' }}
        </button>

        <button
            type="button"
            @click="$emit('toggle-console')"
            class="pm-btn pm-btn-ghost"
            title="Toggle console (Ctrl/Cmd+Alt+C)"
        >
            Console
        </button>

        <button
            type="button"
            @click="$emit('open-shortcuts')"
            class="pm-btn pm-btn-ghost"
            title="Keyboard shortcuts (?)"
        >
            ?
        </button>

        <div class="relative">
            <button
                type="button"
                @click="showSettings = !showSettings"
                class="pm-icon-btn"
                title="Settings"
                aria-label="Settings"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" /></svg>
            </button>
            <div v-if="showSettings" class="pm-panel absolute right-0 top-full z-50 mt-1 w-64 rounded border pm-border py-1 shadow-xl">
                <button type="button" @click="toggleTheme(); showSettings = false" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">
                    Theme: {{ store.theme === 'dark' ? 'Dark' : 'Light' }} (click to switch)
                </button>
                <button type="button" @click="$emit('open-shortcuts'); showSettings = false" class="block w-full px-3 py-1.5 text-left text-sm pm-text-2 pm-hover">
                    Keyboard shortcuts <span class="pm-faint">(?)</span>
                </button>
                <div v-if="store.settings" class="border-t pm-border px-3 py-2">
                    <label class="mb-1 block text-xs font-medium pm-muted">Layout density</label>
                    <select
                        :value="store.settings.layout_density"
                        @change="saveSetting('layout_density', $event.target.value)"
                        class="mb-2 w-full rounded border pm-input px-2 py-1.5 text-sm outline-none"
                    >
                        <option value="comfortable">Comfortable</option>
                        <option value="compact">Compact</option>
                    </select>
                    <label class="mb-1 block text-xs font-medium pm-muted">Default timeout (seconds)</label>
                    <input
                        :value="store.settings.default_timeout"
                        @change="saveSetting('default_timeout', Number($event.target.value) || 30)"
                        type="number" min="1" max="300"
                        class="mb-2 w-full rounded border pm-input px-2 py-1.5 text-sm outline-none"
                    >
                    <label class="mb-1 block text-xs font-medium pm-muted">Default body type (new requests)</label>
                    <select
                        :value="store.settings.default_body_type"
                        @change="saveSetting('default_body_type', $event.target.value)"
                        class="w-full rounded border pm-input px-2 py-1.5 text-sm outline-none"
                    >
                        <option value="none">none</option>
                        <option value="form-data">form-data</option>
                        <option value="urlencoded">x-www-form-urlencoded</option>
                        <option value="raw">raw</option>
                        <option value="binary">binary</option>
                        <option value="graphql">GraphQL</option>
                    </select>
                    <label class="mb-1 mt-2 block text-xs font-medium pm-muted">Sidebar width (px)</label>
                    <input
                        :value="store.settings.sidebar_width"
                        @change="saveSetting('sidebar_width', Math.min(560, Math.max(200, Number($event.target.value) || 320)))"
                        type="number" min="200" max="560"
                        class="w-full rounded border pm-input px-2 py-1.5 text-sm outline-none"
                    >
                </div>
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
                class="pm-btn pm-btn-ghost pm-btn-danger-ghost"
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
                class="pm-field mb-3"
            >
            <label class="mb-1 block text-xs font-medium pm-muted">Description</label>
            <input
                v-model="workspaceDescription"
                type="text"
                placeholder="Optional description"
                class="pm-field mb-3"
            >
            <label class="mb-1 block text-xs font-medium pm-muted">Type</label>
            <select
                v-model="workspaceType"
                class="w-full rounded border pm-input px-3 py-2 text-sm outline-none focus:border-[#FF6C37]"

            >
                <option value="personal">Personal — only you</option>
                <option value="team">Team — invite members</option>
            </select>
            <p v-if="modalError" class="mt-3 text-sm text-red-400">{{ modalError }}</p>
        </Modal>

        <TeamMembers
            :open="showTeamModal"
            :workspace="activeWorkspace"
            @close="showTeamModal = false"
            @left="onTeamLeft"
        />

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
                    :class="importType === t.key ? 'pm-tab-active' : ''"
                    class="pm-tab"
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
                    class="pm-field pm-field-mono"
                ></textarea>
                <button type="button" @click="triggerImport" class="pm-btn pm-btn-ghost pm-btn-sm mt-2">
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
                    class="pm-field pm-field-mono"
                ></textarea>
            </div>

            <div v-if="importType === 'openapi'">
                <p class="mb-2 text-sm pm-muted">Paste an OpenAPI (Swagger) document as JSON, or choose a .json / .yaml file.</p>
                <textarea
                    v-model="openapiJson"
                    rows="8"
                    spellcheck="false"
                    placeholder='{"openapi": "3.0.0", "info": {"title": "My API", "version": "1.0.0"}, "paths": {}}'
                    class="pm-field pm-field-mono"
                ></textarea>
                <button type="button" @click="$refs.openapiInput.click()" class="pm-btn pm-btn-ghost pm-btn-sm mt-2">
                    …or choose a .json / .yaml file
                </button>
                <span v-if="openapiFileName" class="ml-2 text-xs pm-muted">{{ openapiFileName }}</span>
            </div>

            <p v-if="modalError" class="mt-3 text-sm text-red-400">{{ modalError }}</p>
        </Modal>
        <input ref="openapiInput" type="file" accept=".json,.yaml,.yml,application/json" class="hidden" @change="onOpenapiFile">

    </header>
</template>

<script>
import { api, errMessage } from '../api.js'
import { store, clearAuth, loadCollections, loadEnvironments, loadTeam, loadWorkspaces, openTab, setTheme, saveSettings } from '../store.js'
import { toastSuccess, toastError } from '../utils/toast.js'

import Modal from './Modals.vue'
import TeamMembers from './TeamMembers.vue'

export default {
    name: 'TopBar',
    components: { Modal, TeamMembers },
    emits: ['logged-out', 'imported', 'manage-environments', 'toggle-console', 'open-runner', 'open-palette', 'open-shortcuts'],

    data() {
        return {
            store,
            showWorkspaceModal: false,
            showImportModal: false,
            showTeamModal: false,
            showNewMenu: false,
            showSettings: false,
            showQuickLook: false,
            quickLookRows: [],
            quickLookError: '',
            quickLookSaving: false,
            workspaceName: '',
            workspaceDescription: '',
            workspaceType: 'personal',
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
        activeWorkspace() {
            return store.workspaces.find((w) => w.id === store.activeWorkspaceId) || null
        },
        activeEnvironment() {
            return store.environments.find((e) => e.id === store.activeEnvironmentId) || null
        },
        activeEnvironmentName() {
            const env = this.activeEnvironment
            return env ? env.name : 'No Environment'
        },
    },
    methods: {
        toggleTheme() {
            const next = store.theme === 'dark' ? 'light' : 'dark'
            setTheme(next)
            // Persist the theme with the other user settings.
            saveSettings({ theme: next }).catch(() => {})
        },
        async saveSetting(key, value) {
            try {
                await saveSettings({ [key]: value })
            } catch (e) {
                toastError('Setting failed: ' + errMessage(e))
            }
        },
        // ---- Environment quick-look ----
        async toggleQuickLook() {
            if (this.showQuickLook) {
                this.showQuickLook = false
                return
            }
            const env = this.activeEnvironment
            if (!env) return
            // Fetch with secrets revealed: the list response nulls secret
            // values, and saving those nulls back would wipe them.
            let variables = env.variables || []
            try {
                const full = await api.getEnvironment(env.id, true)
                if (full && Array.isArray(full.variables)) variables = full.variables
            } catch (e) { /* fall back to the list copy */ }
            this.quickLookRows = variables.map((v) => ({
                key: v.key || '',
                initial: v.initial_value !== undefined && v.initial_value !== null ? String(v.initial_value) : (v.value !== undefined && v.value !== null ? String(v.value) : ''),
                current: v.current_value !== undefined && v.current_value !== null ? String(v.current_value) : (v.value !== undefined && v.value !== null ? String(v.value) : ''),
                type: v.type || 'text',
                enabled: v.enabled !== false,
                showSecret: false,
            }))
            this.quickLookError = ''
            this.showQuickLook = true
        },
        async saveQuickLook() {
            const env = this.activeEnvironment
            if (!env) return
            this.quickLookSaving = true
            this.quickLookError = ''
            try {
                await api.updateEnvironment(env.id, {
                    variables: this.quickLookRows.map((r) => ({
                        key: r.key,
                        value: r.current,
                        initial_value: r.initial,
                        current_value: r.current,
                        type: r.type,
                        enabled: r.enabled,
                    })),
                })
                await loadEnvironments()
                this.showQuickLook = false
            } catch (e) {
                this.quickLookError = errMessage(e)
            } finally {
                this.quickLookSaving = false
            }
        },
        openWorkspaceModal() {
            this.workspaceName = ''
            this.workspaceDescription = ''
            this.modalError = ''
            this.showWorkspaceModal = true
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
            await Promise.all([loadCollections(), loadEnvironments(), loadTeam(store.activeWorkspaceId)])
            this.$emit('imported')
        },
        async onTeamLeft() {
            await loadWorkspaces()
            store.activeWorkspaceId = store.workspaces.length > 0 ? store.workspaces[0].id : null
            await Promise.all([loadCollections(), loadEnvironments()])
        },
        async createWorkspace() {
            this.modalError = ''
            try {
                const ws = await api.createWorkspace({
                    name: this.workspaceName.trim(),
                    type: this.workspaceType,
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
                this.workspaceType = 'personal'
                toastSuccess('Workspace created.')

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
                toastSuccess('Collection imported.')
            } catch (e) {
                this.modalError = errMessage(e)
                toastError('Import failed: ' + errMessage(e))
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
                toastSuccess('Collection imported.')
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
                toastSuccess('cURL command imported.')
            } catch (e) {
                this.modalError = errMessage(e)
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
                toastSuccess('OpenAPI document imported.')
            } catch (e) {
                this.modalError = errMessage(e)
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
