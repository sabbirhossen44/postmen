<template>
    <AuthView v-if="!store.token" @authenticated="onAuthenticated" />
    <div v-else class="pm-canvas flex h-screen flex-col overflow-hidden">
        <TopBar
            ref="topbar"
            @logged-out="onLoggedOut"
            @manage-environments="sidebarTab = 'environments'"
            @imported="sidebarTab = 'collections'"
            @toggle-console="store.consoleOpen = !store.consoleOpen"
            @open-runner="mainView = 'runner'"
            @open-palette="paletteOpen = true"
            @open-shortcuts="shortcutsOpen = true"
            @filter-navigate="onFilterNavigate"
            @filter-activate="onFilterActivate"
        />
        <div v-if="store.error" class="flex shrink-0 items-center gap-2 bg-red-950 px-4 py-2 text-sm text-red-300">
            <span class="flex-1">{{ store.error }}</span>
            <button type="button" @click="store.error = ''" class="rounded px-1.5 py-0.5 hover:bg-white/10" title="Dismiss" aria-label="Dismiss error">✕</button>
        </div>
        <div class="flex min-h-0 flex-1">
            <button
                v-if="store.sidebarCollapsed"
                type="button"
                @click="store.sidebarCollapsed = false"
                class="pm-sidebar flex w-8 shrink-0 items-center justify-center border-r pm-border pm-muted hover:pm-text"
                title="Show sidebar (Ctrl/Cmd+B)"
                aria-label="Show sidebar"
            >
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4"><path d="M6 3l5 5-5 5" /></svg>
            </button>
            <Sidebar
                ref="sidebar"
                v-show="!store.sidebarCollapsed"
                :active-request-id="activeTab().request.id"
                :active-tab="sidebarTab"
                @load-request="loadRequest($event, false)"
                @load-request-new-tab="loadRequest($event, true)"
                @load-history="loadHistoryItem"
                @clear-request="resetActiveTab"
                @start-first-request="startFirstRequest"
            />
            <div
                v-show="!store.sidebarCollapsed"
                class="pm-resizer w-2"
                role="separator"
                aria-label="Resize sidebar"
                @mousedown="startSidebarResize"
                title="Drag to resize sidebar"
            ></div>
            <main class="flex min-h-0 min-w-0 flex-1 flex-col">
                <RunnerView v-if="mainView === 'runner'" @close="mainView = 'builder'" />
                <!-- No workspace yet: the builder still works — only saving
                     needs a workspace, so this is a banner, not a wall. -->
                <div v-if="!store.loading && store.workspaces.length === 0" class="flex shrink-0 items-center gap-2 border-b border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs">
                    <span class="flex-1 text-amber-200">No workspace yet — you can send requests right away, but saving needs a workspace.</span>
                    <button type="button" @click="openWorkspaceModal" class="pm-btn pm-btn-sm">Create workspace</button>
                </div>
                <template>
                <RequestTabs
                    :tabs="store.tabs"
                    :active-tab-id="store.activeTabId"
                    @select="store.activeTabId = $event"
                    @close="requestCloseTab($event)"
                    @new="openTab()"
                />
                <div class="min-h-0 overflow-y-auto" :style="{ height: builderHeightPct + '%' }">
                    <div v-if="activeTab().saveConflict" class="flex items-center gap-2 border-b border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs">
                        <span class="flex-1 text-amber-200">This request was changed by someone else since you opened it. Your edits are still here — reload to see their version first.</span>
                        <button type="button" @click="reloadConflict(activeTab())" class="pm-btn pm-btn-sm">Reload their version</button>
                        <button type="button" @click="activeTab().saveConflict = null" class="pm-btn pm-btn-ghost pm-btn-sm">Keep editing mine</button>
                    </div>
                    <RequestBuilder
                        ref="requestBuilder"
                        :request="activeTab().request"
                        :sending="activeTab().sending"
                        :saving="activeTab().saving"
                        :save-message="activeTab().saveMessage"
                        :save-message-is-error="activeTab().saveMessageIsError"
                        :is-dirty="isTabDirty(activeTab())"
                        @send="handleSend(activeTab())"
                        @save="handleSave(activeTab())"
                    />
                </div>
                <div class="pm-resizer pm-resizer-v h-2 w-full" role="separator" aria-label="Resize panels" @mousedown="startSplitResize" title="Drag to resize"></div>
                <ResponseViewer :response="activeTab().response" :sending="activeTab().sending" :request="activeTab().request" :loading="store.loading" @examples-changed="onExamplesChanged" @open-palette="paletteOpen = true" @try-sample="startFirstRequest" />

                </template>
            </main>
        </div>
        <ConsoleDrawer
            :open="store.consoleOpen"
            :entries="store.consoleEntries"
            @close="store.consoleOpen = false"
            @clear="store.consoleEntries = []"
        />
        <!-- Status bar -->
        <footer class="pm-topbar flex h-7 shrink-0 items-center gap-4 border-t pm-border px-3 text-[11px] pm-muted">
            <button type="button" @click="store.sidebarCollapsed = !store.sidebarCollapsed" class="hover:pm-text" title="Toggle sidebar (Ctrl/Cmd+B)">
                {{ store.sidebarCollapsed ? '❯ Sidebar' : '❮ Sidebar' }}
            </button>
            <button type="button" @click="store.consoleOpen = !store.consoleOpen" class="hover:pm-text" title="Toggle console (Ctrl/Cmd+Alt+C)">
                Console
            </button>
            <button type="button" @click="shortcutsOpen = true" class="hover:pm-text" title="Keyboard shortcuts (?)">
                Shortcuts
            </button>
            <button type="button" @click="paletteOpen = true" class="hover:pm-text" title="Command palette (Ctrl/Cmd+K)">
                ⌘K
            </button>
            <span class="flex-1"></span>
            <span :class="saveStatusClass">{{ saveStatusText }}</span>
            <span>{{ store.tabs.length }} tab{{ store.tabs.length === 1 ? '' : 's' }}</span>
            <span>{{ activeEnvironmentName }}</span>
        </footer>
        <CommandPalette
            :open="paletteOpen"
            @close="paletteOpen = false"
            @jump-request="loadRequest($event, false)"
            @jump-collection="jumpToCollection"
            @new-request="openTab()"
            @new-collection="openNewCollection"
            @new-environment="openNewEnvironment"
            @switch-workspace="switchWorkspace"
            @switch-environment="switchEnvironment"
            @run-action="runPaletteAction"
        />
        <ShortcutsModal :open="shortcutsOpen" @close="shortcutsOpen = false" />
        <Toasts />

        <!-- Save dialog: pick the destination before saving, instead of
             silently saving into the first collection. -->
        <Modal
            :open="saveDialog.open"
            title="Save request"
            confirm-label="Save"
            :confirm-disabled="saveDialog.busy || !saveDialog.name.trim() || (saveDialog.useNewCollection && !saveDialog.newCollectionName.trim()) || (!saveDialog.useNewCollection && !saveDialog.collectionId)"
            @close="closeSaveDialog"
            @confirm="confirmSaveDialog"
        >
            <label class="mb-1 block text-xs font-medium pm-muted">Name</label>
            <input v-model="saveDialog.name" type="text" placeholder="Request name" class="pm-field mb-3">
            <label class="mb-1 block text-xs font-medium pm-muted">Collection</label>
            <select v-model="saveDialog.collectionId" :disabled="saveDialog.useNewCollection" class="pm-field mb-2 w-full">
                <option v-for="c in store.collections" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <label class="flex cursor-pointer items-center gap-2 text-sm pm-text-2">
                <input v-model="saveDialog.useNewCollection" type="checkbox"> New collection
            </label>
            <input v-if="saveDialog.useNewCollection" v-model="saveDialog.newCollectionName" type="text" placeholder="New collection name" class="pm-field mt-2">
            <p class="mt-3 text-xs pm-faint">Saving to: {{ saveDestinationText }}</p>
            <p v-if="saveDialog.error" class="mt-3 text-sm text-red-400">{{ saveDialog.error }}</p>
        </Modal>

        <!-- Unsaved-changes dialog: closing a dirty tab or loading another
             request over it asks first — never silently discards. -->
        <Modal
            :open="discardDialogOpen"
            title="Unsaved changes"
            confirm-label="Discard"
            danger
            extra-label="Save"
            @close="discardDialogOpen = false; pendingDiscard = null"
            @confirm="confirmDiscard"
            @extra="saveAndContinue"
        >
            <p class="text-sm pm-text-2">This tab has unsaved changes. Save them before continuing, or discard them.</p>
        </Modal>
    </div>
</template>

<script>
import { api, errMessage } from '../api.js'
import {
    store, loadAll, loadCollections, loadCollectionDetail, loadHistories, loadEnvironments,
    blankRequest, activeTab, openTab, closeTab, isTabDirty, markTabSaved, logConsole,
    storedBuilderHeightPct, persistBuilderHeightPct, persistSidebarWidth, setTheme,
} from '../store.js'
import { toastSuccess, toastError, toastInfo } from '../utils/toast.js'
import AuthView from './AuthView.vue'
import TopBar from './TopBar.vue'
import Sidebar from './Sidebar.vue'
import RequestTabs from './RequestTabs.vue'
import RequestBuilder from './RequestBuilder.vue'
import ResponseViewer from './ResponseViewer.vue'
import RunnerView from './RunnerView.vue'
import ConsoleDrawer from './ConsoleDrawer.vue'
import CommandPalette from './CommandPalette.vue'
import ShortcutsModal from './ShortcutsModal.vue'
import Toasts from './Toasts.vue'
import Modal from './Modals.vue'

// Normalise a saved request (either shape) into builder state.
function normalizeRequest(saved) {
    const req = blankRequest()
    req.id = saved.id || null
    req.collection_id = saved.collection_id || null
    req.folder_id = saved.folder_id || null
    req.name = saved.name || ''
    req.method = (saved.method || 'GET').toUpperCase()
    req.url = saved.url || ''
    req.params = normalizeRows(saved.params)
    req.headers = normalizeRows(saved.headers)
    req.auth_type = saved.auth_type || 'none'
    const savedAuth = saved.auth || {}
    req.auth = Object.assign(
        {
            token: '', username: '', password: '', key: '', value: '', in: 'header',
            grant_type: 'client_credentials', token_url: '', client_id: '', client_secret: '',
            scope: '', access_token: '', token_type: 'Bearer',
            access_key: '', secret_key: '', region: '', service: '',
        },
        savedAuth,
    )
    // Legacy saved requests used `add_to` — map it to the canonical `in`.
    if (!savedAuth.in && savedAuth.add_to) req.auth.in = savedAuth.add_to
    req.body_type = saved.body_type || 'none'
    const body = saved.body || {}
    const raw = typeof body.raw === 'string' ? body.raw : (typeof body === 'string' ? body : '')
    req.body = {
        raw,
        raw_language: body.raw_language || 'json',
        formdata: normalizeRows(body.formdata, true),
        urlencoded: normalizeRows(body.urlencoded),
        binary: null,
        binary_content_type: (body && body.binary_content_type) || '',
        graphql: {
            query: (body.graphql && body.graphql.query) || '',
            variables: (body.graphql && body.graphql.variables) || '',
        },
    }
    req.body_file_media_id = saved.body_file_media_id || null
    // Scripts: backend now also sends pre_request_script / test_script.
    req.scripts = Object.assign(
        { pre_request: '', tests: '' },
        saved.scripts || {},
        {
            pre_request: (saved.scripts && saved.scripts.pre_request) || saved.pre_request_script || '',
            tests: (saved.scripts && saved.scripts.tests) || saved.test_script || '',
        },
    )
    req.settings = Object.assign({ timeout: 30, max_redirects: 5, follow_redirects: true, verify_ssl: true, send_cookies: true }, saved.settings || {})
    req.description = saved.description || ''
    req.examples = Array.isArray(saved.examples) ? saved.examples : []
    return req
}

function normalizeRows(rows, allowFile) {
    const list = Array.isArray(rows) ? rows : []
    const out = list.map((r) => {
        const row = {
            key: r.key || '',
            value: r.value !== undefined && r.value !== null ? String(r.value) : '',
            enabled: r.enabled !== false,
        }
        if (r.description !== undefined) row.description = r.description
        if (r.path !== undefined) row.path = !!r.path
        if (r.media_id !== undefined) row.media_id = r.media_id
        if (allowFile) row.type = r.type || 'text'
        return row
    })
    if (out.length === 0) {
        const row = { key: '', value: '', enabled: true }
        if (allowFile) row.type = 'text'
        out.push(row)
    }
    return out
}

function cleanRows(rows) {
    return (rows || [])
        .filter((r) => r.key && String(r.key).trim() !== '')
        .map((r) => {
            const row = { key: r.key, value: r.value || '', enabled: r.enabled !== false }
            if (r.description !== undefined) row.description = r.description
            if (r.path) row.path = true
            if (r.media_id !== undefined) row.media_id = r.media_id
            return row
        })
}

export default {
    name: 'App',
    components: { AuthView, TopBar, Sidebar, RequestTabs, RequestBuilder, ResponseViewer, RunnerView, ConsoleDrawer, CommandPalette, ShortcutsModal, Toasts, Modal },
    data() {
        return {
            store,
            sidebarTab: 'collections',
            builderHeightPct: storedBuilderHeightPct(),
            mainView: 'builder',
            paletteOpen: false,
            shortcutsOpen: false,
            saveDialog: { open: false, name: '', collectionId: null, newCollectionName: '', useNewCollection: false, error: '', busy: false },
            discardDialogOpen: false,
            pendingDiscard: null,
            saveAfterDialog: false,
        }
    },
    computed: {
        activeEnvironmentName() {
            const env = store.environments.find((e) => e.id === store.activeEnvironmentId)
            return env ? 'Env: ' + env.name : 'No Environment'
        },
        // Persistent save status in the footer — not just a toast that
        // disappears after a few seconds.
        saveStatusText() {
            const tab = activeTab()
            if (!tab) return ''
            if (isTabDirty(tab)) return '● Unsaved changes'
            if (tab.savedAt) return 'Saved · ' + tab.savedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            return tab.request.id ? 'Saved' : 'Not saved yet'
        },
        saveStatusClass() {
            const tab = activeTab()
            return tab && isTabDirty(tab) ? 'text-amber-400' : ''
        },
        saveDestinationText() {
            if (this.saveDialog.useNewCollection) {
                return this.saveDialog.newCollectionName.trim()
                    ? 'New collection "' + this.saveDialog.newCollectionName.trim() + '"'
                    : 'a new collection'
            }
            const collection = store.collections.find((c) => c.id === this.saveDialog.collectionId)
            return collection ? '"' + collection.name + '"' : '—'
        },
    },
    watch: {
        // A newly opened tab lands focus in the URL field.
        'store.tabs.length'(next, prev) {
            if (next > prev) this.$nextTick(() => this.focusUrlInput())
        },
    },
    mounted() {
        window.addEventListener('postmen:unauthorized', this.onUnauthorized)
        window.addEventListener('keydown', this.onKeydown)
        if (store.token) {
            loadAll().catch(() => {
                // loadAll surfaces the error in store.error; stay logged in
                // with whatever cached state we have.
            }).finally(() => { this.acceptInviteFromUrl() })
        }
    },
    beforeUnmount() {
        window.removeEventListener('postmen:unauthorized', this.onUnauthorized)
        window.removeEventListener('keydown', this.onKeydown)
    },
    methods: {
        activeTab,
        openTab,
        closeTab,
        isTabDirty,
        onUnauthorized() {
            if (this.confirmIfDirty(activeTab(), { type: 'reset-tab' })) return
            this.resetActiveTab()
        },
        onExamplesChanged(examples) {
            // Examples are saved server-side already; keep the tab's
            // dirty state untouched (other edits may still be unsaved).
            activeTab().request.examples = examples
        },
        async onAuthenticated() {
            await loadAll().catch(() => {})
            this.acceptInviteFromUrl()
        },
        /** Accept a workspace invite link (?invite=<token>) once logged in. */
        async acceptInviteFromUrl() {
            const params = new URLSearchParams(window.location.search)
            const token = params.get('invite')
            if (!token || !store.token) return
            try {
                const workspace = await api.acceptInvitation(token)
                toastSuccess('Joined workspace' + (workspace && workspace.name ? ' ' + workspace.name : '') + '.')
                await loadAll().catch(() => {})
            } catch (e) {
                toastError('Could not accept the invitation: ' + errMessage(e))
            } finally {
                params.delete('invite')
                const query = params.toString()
                window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : ''))
            }
        },
        onLoggedOut() {
            this.resetActiveTab()
            this.sidebarTab = 'collections'
            this.mainView = 'builder'
        },
        resetActiveTab() {
            const tab = activeTab()
            const fresh = blankRequest()
            tab.request = fresh
            tab.response = null
            tab.savedSnapshot = JSON.stringify(fresh)
            tab.savedAt = null
            tab.saveMessage = ''
        },
        focusUrlInput() {
            if (this.$refs.requestBuilder && this.$refs.requestBuilder.focusUrl) {
                this.$refs.requestBuilder.focusUrl()
            }
        },
        // "Send your first request": a fresh tab prefilled with the sample
        // URL, with the cursor already in the URL field.
        startFirstRequest() {
            const tab = activeTab()
            const fresh = blankRequest()
            fresh.url = 'https://postman-echo.com/get'
            tab.request = fresh
            tab.response = null
            tab.savedSnapshot = JSON.stringify(fresh)
            tab.savedAt = null
            tab.saveMessage = ''
            this.$nextTick(() => this.focusUrlInput())
        },
        onFilterNavigate(dir) {
            if (this.$refs.sidebar) this.$refs.sidebar.navigateFilter(dir)
        },
        onFilterActivate() {
            if (this.$refs.sidebar) this.$refs.sidebar.activateFilterSelection()
        },
        // ---- Unsaved-changes guard (tab close / request load / 401) ----
        // Returns true when the action must wait for the dialog.
        confirmIfDirty(tab, action) {
            if (tab && isTabDirty(tab)) {
                this.pendingDiscard = action
                this.discardDialogOpen = true
                return true
            }
            return false
        },
        confirmDiscard() {
            const action = this.pendingDiscard
            this.pendingDiscard = null
            this.discardDialogOpen = false
            if (!action) return
            if (action.type === 'close-tab') closeTab(action.tabId)
            else if (action.type === 'load-request') this.doLoadRequest(action.saved, action.newTab)
            else if (action.type === 'load-history') this.doLoadHistoryItem(action.item)
            else if (action.type === 'reset-tab') this.resetActiveTab()
        },
        async saveAndContinue() {
            const tab = activeTab()
            this.discardDialogOpen = false
            if (!tab.request.id || !tab.request.collection_id) {
                // Saving needs the dialog first; continue afterwards.
                this.saveAfterDialog = true
                this.openSaveDialog(tab)
                return
            }
            await this.handleSave(tab)
            this.confirmDiscard()
        },
        requestCloseTab(id) {
            const tab = store.tabs.find((t) => t.id === id)
            if (this.confirmIfDirty(tab, { type: 'close-tab', tabId: id })) return
            closeTab(id)
        },
        openWorkspaceModal() {
            if (this.$refs.topbar && this.$refs.topbar.openWorkspaceModal) this.$refs.topbar.openWorkspaceModal()
        },
        openNewCollection() {
            store.sidebarCollapsed = false
            this.sidebarTab = 'collections'
            this.$nextTick(() => {
                if (this.$refs.sidebar) this.$refs.sidebar.openPrompt('collection', null)
            })
        },
        openNewEnvironment() {
            store.sidebarCollapsed = false
            this.sidebarTab = 'environments'
            this.$nextTick(() => {
                if (this.$refs.sidebar) this.$refs.sidebar.openEnvironmentEditor(null)
            })
        },
        jumpToCollection(collection) {
            store.sidebarCollapsed = false
            this.sidebarTab = 'collections'
            store.searchQuery = collection.name || ''
        },
        async switchWorkspace(id) {
            store.activeWorkspaceId = id
            store.activeEnvironmentId = null
            try {
                await Promise.all([loadCollections(), loadEnvironments()])
                this.sidebarTab = 'collections'
            } catch (e) {
                toastError('Could not switch workspace: ' + errMessage(e))
            }
        },
        async switchEnvironment(id) {
            store.activeEnvironmentId = id
            try { await api.activateEnvironment(id) } catch (e) { /* local selection still stands */ }
        },
        runPaletteAction(action) {
            if (action === 'send') this.handleSend(activeTab())
            else if (action === 'save') this.handleSave(activeTab())
            else if (action === 'new-tab') openTab()
            else if (action === 'new-request') openTab()
            else if (action === 'new-collection') this.openNewCollection()
            else if (action === 'new-environment') this.openNewEnvironment()
            else if (action === 'runner') this.mainView = 'runner'
            else if (action === 'console') store.consoleOpen = !store.consoleOpen
            else if (action === 'theme') setTheme(store.theme === 'dark' ? 'light' : 'dark')
            else if (action === 'shortcuts') this.shortcutsOpen = true
        },
        onKeydown(event) {
            if (!store.token) return
            const mod = event.ctrlKey || event.metaKey
            const typing = event.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)
            // "?" opens the shortcuts list when not typing.
            if (!mod && !typing && event.key === '?') {
                event.preventDefault()
                this.shortcutsOpen = true
                return
            }
            if (!mod) return
            if (event.key === 'Enter') {
                event.preventDefault()
                this.handleSend(activeTab())
            } else if (event.key.toLowerCase() === 's') {
                event.preventDefault()
                this.handleSave(activeTab())
            } else if (event.key.toLowerCase() === 't') {
                event.preventDefault()
                openTab()
            } else if (event.altKey && event.key.toLowerCase() === 'c') {
                event.preventDefault()
                store.consoleOpen = !store.consoleOpen
            } else if (event.key.toLowerCase() === 'k') {
                event.preventDefault()
                this.paletteOpen = !this.paletteOpen
            } else if (event.key.toLowerCase() === 'b') {
                event.preventDefault()
                store.sidebarCollapsed = !store.sidebarCollapsed
            }
        },
        async loadRequest(saved, newTab) {
            if (!newTab && this.confirmIfDirty(activeTab(), { type: 'load-request', saved, newTab: false })) return
            return this.doLoadRequest(saved, newTab)
        },
        async doLoadRequest(saved, newTab) {
            try {
                const full = saved.id ? await api.getRequest(saved.id) : saved
                const req = normalizeRequest(full)
                const tab = newTab ? openTab(req) : activeTab()
                if (!newTab) {
                    tab.request = req
                    tab.response = null
                    tab.saveMessage = ''
                }
                tab.savedSnapshot = JSON.stringify(req)
                tab.savedAt = null
            } catch (e) {
                // Fall back to the tree copy if the detail fetch fails.
                const req = normalizeRequest(saved)
                const tab = newTab ? openTab(req) : activeTab()
                if (!newTab) {
                    tab.request = req
                    tab.response = null
                }
                tab.savedSnapshot = JSON.stringify(req)
                tab.savedAt = null
                toastError('Could not load the full request: ' + errMessage(e))
            }
        },
        // Full restore from a history entry: request snapshot + stored response.
        loadHistoryItem(item) {
            if (this.confirmIfDirty(activeTab(), { type: 'load-history', item })) return
            this.doLoadHistoryItem(item)
        },
        doLoadHistoryItem(item) {
            const tab = activeTab()
            const snapshot = item.request_snapshot || null
            if (snapshot) {
                const req = normalizeRequest(snapshot)
                req.id = item.request_id || null
                tab.request = req
                tab.savedSnapshot = JSON.stringify(req)
                tab.savedAt = null
            } else {
                const req = blankRequest()
                req.method = (item.method || 'GET').toUpperCase()
                req.url = item.url || ''
                tab.request = req
                tab.savedSnapshot = JSON.stringify(req)
                tab.savedAt = null
            }
            // Show the stored response instead of sending again.
            const headers = item.response_headers || {}
            const contentType = headers['Content-Type'] || headers['content-type'] || null
            tab.response = {
                status: item.status || null,
                status_text: '',
                time_ms: item.time_ms || 0,
                size: item.size || 0,
                headers,
                body: item.response_body || '',
                content_type: contentType,
                is_json: !!contentType && contentType.toLowerCase().includes('json'),
                error: item.error || null,
                from_history: true,
            }
            tab.saveMessage = ''
            this.sidebarTab = 'collections'
        },
        requestPayload(tab) {
            const req = tab.request
            const body = {}
            if (req.body_type === 'raw') {
                body.raw = req.body.raw || ''
                body.raw_language = req.body.raw_language || 'json'
            } else if (req.body_type === 'form-data') {
                body.formdata = (req.body.formdata || [])
                    .filter((r) => r.key && String(r.key).trim() !== '')
                    .map((r) => {
                        const row = { key: r.key, value: r.value || '', type: r.type || 'text', enabled: r.enabled !== false }
                        if (r.media_id) row.media_id = r.media_id
                        return row
                    })
            } else if (req.body_type === 'urlencoded') {
                body.urlencoded = cleanRows(req.body.urlencoded)
            } else if (req.body_type === 'binary') {
                body.binary_content_type = req.body.binary_content_type || ''
            } else if (req.body_type === 'graphql') {
                body.graphql = {
                    query: req.body.graphql.query || '',
                    variables: req.body.graphql.variables || '',
                }
            }
            return {
                request_id: req.id,
                name: req.name || 'Untitled Request',
                method: req.method,
                url: req.url,
                params: cleanRows(req.params),
                headers: cleanRows(req.headers),
                auth_type: req.auth_type,
                auth: req.auth,
                body_type: req.body_type,
                body,
                body_file_media_id: req.body_file_media_id || null,
                scripts: req.scripts,
                // Backend parity shape: top-level script fields.
                pre_request_script: req.scripts.pre_request || '',
                test_script: req.scripts.tests || '',
                settings: req.settings,
                description: req.description || '',
                folder_id: req.folder_id,
                // Optimistic locking: the server 409s when the stored row
                // is newer than the updated_at we last saw.
                updated_at: req.updated_at || null,
            }
        },
        async handleSend(tab) {
            if (!tab.request.url.trim()) {
                toastInfo('Enter a URL first.')
                this.focusUrlInput()
                return
            }
            if (tab.sending) return
            tab.sending = true
            tab.response = null
            try {
                const payload = this.requestPayload(tab)
                // Upload files first; send their media_id so the backend
                // streams the real bytes (never a stored path string).
                if (tab.request.body_type === 'form-data') {
                    const rows = tab.request.body.formdata || []
                    for (let i = 0; i < rows.length; i++) {
                        const row = rows[i]
                        if (row.type === 'file' && row._file) {
                            const media = await api.uploadMedia(row._file)
                            row.media_id = media.id
                            const sent = payload.body.formdata.find((f) => f.key === row.key)
                            if (sent) sent.media_id = media.id
                        }
                    }
                }
                if (tab.request.body_type === 'binary' && tab.request.body.binary && tab.request.body.binary._file) {
                    const media = await api.uploadMedia(tab.request.body.binary._file)
                    payload.body_file_media_id = media.id
                    tab.request.body_file_media_id = media.id
                }
                const result = await api.sendRequest(Object.assign(payload, {
                    environment_id: store.activeEnvironmentId,
                    collection_id: tab.request.collection_id,
                    save_history: true,
                }))
                tab.response = result
                if (result && result.request_echo) {
                    logConsole({
                        method: result.request_echo.method,
                        url: result.request_echo.url,
                        status: result.status,
                        timeMs: result.time_ms,
                        error: result.error,
                        headers: result.request_echo.headers,
                    })
                }
                await loadHistories(1)
            } catch (e) {
                // Show a failed send as a response so the error is visible
                // in place instead of only an alert.
                tab.response = {
                    status: (e.response && e.response.status) || 0,
                    status_text: errMessage(e),
                    time_ms: 0,
                    size: 0,
                    headers: {},
                    body: (e.response && e.response.data) ? JSON.stringify(e.response.data) : errMessage(e),
                    content_type: 'text/plain',
                    is_json: false,
                }
                logConsole({ method: tab.request.method, url: tab.request.url, error: errMessage(e) })
                toastError('Send failed: ' + errMessage(e))
            } finally {
                tab.sending = false
            }
        },
        // Save: a saved request saves in place; a new one asks where it
        // goes first (name + collection picker + inline New Collection).
        async handleSave(tab) {
            if (tab.saving) return
            if (!tab.request.id || !tab.request.collection_id) {
                this.openSaveDialog(tab)
                return
            }
            return this.performSave(tab, tab.request.collection_id)
        },
        openSaveDialog(tab) {
            this.saveDialog = {
                open: true,
                name: tab.request.name || 'Untitled Request',
                collectionId: tab.request.collection_id || (store.collections.length > 0 ? store.collections[0].id : null),
                newCollectionName: '',
                useNewCollection: store.collections.length === 0,
                error: '',
                busy: false,
            }
        },
        closeSaveDialog() {
            this.saveDialog.open = false
            // Cancelling the save abandons the action that was waiting on it.
            this.saveAfterDialog = false
            this.pendingDiscard = null
        },
        async confirmSaveDialog() {
            const tab = activeTab()
            const dialog = this.saveDialog
            if (dialog.busy) return
            let collectionId = dialog.collectionId
            dialog.error = ''
            dialog.busy = true
            try {
                if (dialog.useNewCollection) {
                    const name = dialog.newCollectionName.trim()
                    if (!name) {
                        dialog.error = 'Enter a name for the new collection.'
                        return
                    }
                    if (!store.activeWorkspaceId) {
                        dialog.error = 'Create a workspace first — saving needs somewhere to put the request.'
                        return
                    }
                    const created = await api.createCollection({ workspace_id: store.activeWorkspaceId, name })
                    await loadCollections()
                    collectionId = created && created.id
                }
                if (!collectionId) {
                    dialog.error = 'Choose a collection to save into.'
                    return
                }
                tab.request.name = dialog.name.trim() || 'Untitled Request'
                tab.request.collection_id = collectionId
                dialog.open = false
                await this.performSave(tab, collectionId)
                if (this.saveAfterDialog) {
                    this.saveAfterDialog = false
                    this.confirmDiscard()
                }
            } catch (e) {
                dialog.error = errMessage(e)
            } finally {
                dialog.busy = false
            }
        },
        async performSave(tab, collectionId) {
            if (tab.saving) return
            tab.saveMessage = ''
            tab.saveMessageIsError = false
            if (!collectionId) {
                tab.saveMessage = 'Choose a collection to save into.'
                tab.saveMessageIsError = true
                return
            }
            tab.saving = true
            try {
                const payload = this.requestPayload(tab)
                let saved
                if (tab.request.id) {
                    saved = await api.updateRequest(tab.request.id, payload)
                } else {
                    saved = await api.createRequest(collectionId, payload)
                }
                if (saved && saved.id) tab.request.id = saved.id
                markTabSaved(tab)
                toastSuccess('Request saved.')
                await loadCollectionDetail(collectionId)
            } catch (e) {
                if (e.response && e.response.status === 409) {
                    // Someone else saved this request first — keep the
                    // user's edits, offer their version via the banner.
                    tab.saveConflict = (e.response.data && e.response.data.data) || null
                    tab.saveMessage = 'Someone else changed this request. Reload their version or keep editing yours.'
                    tab.saveMessageIsError = true
                    toastError('Save conflict: this request was changed by someone else.')
                } else {
                    tab.saveMessage = 'Save failed: ' + errMessage(e)
                    tab.saveMessageIsError = true
                    toastError('Save failed: ' + errMessage(e))
                }
            } finally {
                tab.saving = false
            }
        },
        reloadConflict(tab) {
            const fresh = tab.saveConflict
            if (!fresh) return
            tab.saveConflict = null
            this.loadRequest(fresh, false)
            tab.saveMessage = ''
            tab.saveMessageIsError = false
            toastInfo('Reloaded the latest saved version.')
        },
        // ---- Resizable sidebar / builder-response split ----
        startSidebarResize(event) {
            event.preventDefault()
            const startX = event.clientX
            const startWidth = store.sidebarWidth
            const onMove = (e) => {
                store.sidebarWidth = Math.min(560, Math.max(200, startWidth + e.clientX - startX))
            }
            const onUp = () => {
                window.removeEventListener('mousemove', onMove)
                window.removeEventListener('mouseup', onUp)
                persistSidebarWidth(store.sidebarWidth)
            }
            window.addEventListener('mousemove', onMove)
            window.addEventListener('mouseup', onUp)
        },
        startSplitResize(event) {
            event.preventDefault()
            const startY = event.clientY
            const startPct = this.builderHeightPct
            const total = window.innerHeight || 800
            const onMove = (e) => {
                this.builderHeightPct = Math.min(75, Math.max(25, startPct + ((e.clientY - startY) / total) * 100))
            }
            const onUp = () => {
                window.removeEventListener('mousemove', onMove)
                window.removeEventListener('mouseup', onUp)
                persistBuilderHeightPct(this.builderHeightPct)
            }
            window.addEventListener('mousemove', onMove)
            window.addEventListener('mouseup', onUp)
        },
    },
}
</script>
