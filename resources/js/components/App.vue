<template>
    <AuthView v-if="!store.token" @authenticated="onAuthenticated" />
    <div v-else class="pm-canvas flex h-screen flex-col overflow-hidden">
        <TopBar
            @logged-out="onLoggedOut"
            @manage-environments="sidebarTab = 'environments'"
            @imported="sidebarTab = 'collections'"
            @toggle-console="store.consoleOpen = !store.consoleOpen"
            @open-runner="mainView = 'runner'"
        />
        <div v-if="store.error" class="shrink-0 bg-red-950 px-4 py-2 text-sm text-red-300">
            {{ store.error }}
        </div>
        <div class="flex min-h-0 flex-1">
            <button
                v-if="store.sidebarCollapsed"
                type="button"
                @click="store.sidebarCollapsed = false"
                class="pm-sidebar w-8 shrink-0 border-r pm-border pm-muted hover:pm-text"
                title="Show sidebar"
            >
                ❯
            </button>
            <Sidebar
                v-show="!store.sidebarCollapsed"
                :active-request-id="activeTab().request.id"
                :active-tab="sidebarTab"
                @load-request="loadRequest($event, false)"
                @load-request-new-tab="loadRequest($event, true)"
                @load-history="loadHistoryItem"
                @clear-request="resetActiveTab"
            />
            <div
                v-show="!store.sidebarCollapsed"
                class="pm-resizer w-1"
                @mousedown="startSidebarResize"
                title="Drag to resize sidebar"
            ></div>
            <main class="flex min-h-0 min-w-0 flex-1 flex-col">
                <RunnerView v-if="mainView === 'runner'" @close="mainView = 'builder'" />
                <template v-else>
                <RequestTabs
                    :tabs="store.tabs"
                    :active-tab-id="store.activeTabId"
                    @select="store.activeTabId = $event"
                    @close="closeTab($event)"
                    @new="openTab()"
                />
                <div class="min-h-0 overflow-y-auto" :style="{ height: builderHeightPct + '%' }">
                    <RequestBuilder
                        :request="activeTab().request"
                        :sending="activeTab().sending"
                        :save-message="activeTab().saveMessage"
                        :save-message-is-error="activeTab().saveMessageIsError"
                        :is-dirty="isTabDirty(activeTab())"
                        @send="handleSend(activeTab())"
                        @save="handleSave(activeTab())"
                    />
                </div>
                <div class="pm-resizer pm-resizer-v h-1 w-full" @mousedown="startSplitResize" title="Drag to resize"></div>
                <ResponseViewer :response="activeTab().response" :sending="activeTab().sending" />
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
            <button type="button" @click="store.sidebarCollapsed = !store.sidebarCollapsed" class="hover:pm-text" title="Toggle sidebar">
                {{ store.sidebarCollapsed ? '❯ Sidebar' : '❮ Sidebar' }}
            </button>
            <button type="button" @click="store.consoleOpen = !store.consoleOpen" class="hover:pm-text" title="Toggle console (Ctrl/Cmd+Alt+C)">
                Console
            </button>
            <span class="flex-1"></span>
            <span>{{ store.tabs.length }} tab{{ store.tabs.length === 1 ? '' : 's' }}</span>
            <span>{{ activeEnvironmentName }}</span>
            <span>Theme: {{ store.theme }}</span>
        </footer>
    </div>
</template>

<script>
import { api, errMessage } from '../api.js'
import {
    store, loadAll, loadCollections, loadCollectionDetail, loadHistories,
    blankRequest, activeTab, openTab, closeTab, isTabDirty, markTabSaved, logConsole,
} from '../store.js'
import AuthView from './AuthView.vue'
import TopBar from './TopBar.vue'
import Sidebar from './Sidebar.vue'
import RequestTabs from './RequestTabs.vue'
import RequestBuilder from './RequestBuilder.vue'
import ResponseViewer from './ResponseViewer.vue'
import RunnerView from './RunnerView.vue'
import ConsoleDrawer from './ConsoleDrawer.vue'

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
    components: { AuthView, TopBar, Sidebar, RequestTabs, RequestBuilder, ResponseViewer, RunnerView, ConsoleDrawer },
    data() {
        return {
            store,
            sidebarTab: 'collections',
            builderHeightPct: 48,
            mainView: 'builder',
        }
    },
    computed: {
        activeEnvironmentName() {
            const env = store.environments.find((e) => e.id === store.activeEnvironmentId)
            return env ? 'Env: ' + env.name : 'No Environment'
        },
    },
    mounted() {
        window.addEventListener('postmen:unauthorized', this.onUnauthorized)
        window.addEventListener('keydown', this.onKeydown)
        if (store.token) {
            loadAll().catch(() => {
                // loadAll surfaces the error in store.error; stay logged in
                // with whatever cached state we have.
            })
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
            this.resetActiveTab()
        },
        async onAuthenticated() {
            await loadAll().catch(() => {})
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
            tab.saveMessage = ''
        },
        onKeydown(event) {
            if (!store.token) return
            const mod = event.ctrlKey || event.metaKey
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
                const input = document.querySelector('input[placeholder*="Search collections"]')
                if (input) input.focus()
            }
        },
        async loadRequest(saved, newTab) {
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
            } catch (e) {
                // Fall back to the tree copy if the detail fetch fails.
                const req = normalizeRequest(saved)
                const tab = newTab ? openTab(req) : activeTab()
                if (!newTab) {
                    tab.request = req
                    tab.response = null
                }
                tab.savedSnapshot = JSON.stringify(req)
            }
        },
        // Full restore from a history entry: request snapshot + stored response.
        loadHistoryItem(item) {
            const tab = activeTab()
            const snapshot = item.request_snapshot || null
            if (snapshot) {
                const req = normalizeRequest(snapshot)
                req.id = item.request_id || null
                tab.request = req
                tab.savedSnapshot = JSON.stringify(req)
            } else {
                const req = blankRequest()
                req.method = (item.method || 'GET').toUpperCase()
                req.url = item.url || ''
                tab.request = req
                tab.savedSnapshot = JSON.stringify(req)
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
            }
        },
        async handleSend(tab) {
            if (!tab.request.url.trim() || tab.sending) return
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
            } finally {
                tab.sending = false
            }
        },
        async handleSave(tab) {
            tab.saveMessage = ''
            tab.saveMessageIsError = false
            let collectionId = tab.request.collection_id
            if (!collectionId) {
                if (store.collections.length > 0) {
                    collectionId = store.collections[0].id
                    tab.request.collection_id = collectionId
                } else {
                    tab.saveMessage = 'Create a collection first, then save.'
                    tab.saveMessageIsError = true
                    return
                }
            }
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
                await loadCollectionDetail(collectionId)
            } catch (e) {
                tab.saveMessage = 'Save failed: ' + errMessage(e)
                tab.saveMessageIsError = true
            }
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
            }
            window.addEventListener('mousemove', onMove)
            window.addEventListener('mouseup', onUp)
        },
    },
}
</script>
