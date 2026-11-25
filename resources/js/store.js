import { reactive } from 'vue'
import { api, errMessage, TOKEN_KEY, USER_KEY } from './api.js'
import { collectVariables } from './utils/variables.js'

function storedUser() {
    try {
        return JSON.parse(localStorage.getItem(USER_KEY) || 'null')
    } catch (e) {
        return null
    }
}

const RUNS_KEY = 'postmen_runs'

function storedRuns() {
    try {
        const list = JSON.parse(localStorage.getItem(RUNS_KEY) || '[]')
        return Array.isArray(list) ? list : []
    } catch (e) {
        return []
    }
}

// Record (or refresh) a run in history, newest first, capped at 20.
export function addRun(run) {
    if (!run) return
    const entry = Object.assign({}, run, {
        id: run.id || ('local-' + Date.now()),
        created_at: run.created_at || new Date().toISOString().slice(0, 19).replace('T', ' '),
    })
    const idx = store.runs.findIndex((r) => String(r.id) === String(entry.id))
    if (idx !== -1) store.runs.splice(idx, 1, entry)
    else store.runs.unshift(entry)
    if (store.runs.length > 20) store.runs.length = 20
    try { localStorage.setItem(RUNS_KEY, JSON.stringify(store.runs)) } catch (e) { /* ignore */ }
}

let tabSeq = 1

export function blankRequest() {
    return {
        id: null,
        collection_id: null,
        folder_id: null,
        name: '',
        method: 'GET',
        url: '',
        params: [{ key: '', value: '', enabled: true }],
        headers: [{ key: '', value: '', enabled: true }],
        auth_type: 'none',
        // Canonical API-Key key is `in` ('header' | 'query'), matching the
        // backend and the importer/exporter. `add_to` was the old frontend
        // name and silently never worked — do not bring it back.
        auth: {
            token: '', username: '', password: '', key: '', value: '', in: 'header',
            // Digest
            // OAuth 2.0
            grant_type: 'client_credentials', token_url: '', client_id: '', client_secret: '',
            scope: '', access_token: '', token_type: 'Bearer',
            // AWS Signature
            access_key: '', secret_key: '', region: '', service: '',
        },
        body_type: 'none',
        body: {
            raw: '',
            raw_language: 'json',
            formdata: [{ key: '', value: '', type: 'text', enabled: true }],
            urlencoded: [{ key: '', value: '', enabled: true }],
            binary: null,
            binary_content_type: '',
            graphql: { query: '', variables: '' },
        },
        scripts: { pre_request: '', tests: '' },
        settings: { timeout: 30, max_redirects: 5, follow_redirects: true, verify_ssl: true, send_cookies: true },
        description: '',
    }
}

function newTab(request) {
    const req = request || blankRequest()
    return {
        id: tabSeq++,
        request: req,
        response: null,
        sending: false,
        saveMessage: '',
        saveMessageIsError: false,
        // JSON of the request as last saved / loaded — dirty dot compares
        // the live request against this.
        savedSnapshot: JSON.stringify(req),
    }
}

// Plain reactive store — no Pinia / Vuex. Every component imports this
// same object, so state stays in sync across the app.
export const store = reactive({
    user: storedUser(),
    token: localStorage.getItem(TOKEN_KEY) || '',
    workspaces: [],
    activeWorkspaceId: null,
    collections: [],
    environments: [],
    activeEnvironmentId: null,
    // Workspace-wide variables (GET /api/globals) — lowest precedence.
    globals: [],
    globalsUnavailable: false,
    // Collection Runner history (local, persisted; backend GET /api/runs/{id}
    // refreshes an entry when available).
    runs: storedRuns(),
    histories: [],
    historyPage: 1,
    historyLastPage: 1,
    historyTotal: 0,
    loading: false,
    error: '',
    // Theme: 'dark' (default) or 'light', persisted in localStorage.
    theme: localStorage.getItem('postmen_theme') || 'dark',
    // Global search text from the TopBar — Sidebar filters on it.
    searchQuery: '',
    sidebarCollapsed: false,
    sidebarWidth: 320,
    consoleOpen: false,
    consoleEntries: [],
    // Multi-tab request area (Postman parity): one tab per open request.
    tabs: [newTab()],
    activeTabId: 1,
})

export function setTheme(theme) {
    store.theme = theme === 'light' ? 'light' : 'dark'
    localStorage.setItem('postmen_theme', store.theme)
    document.documentElement.setAttribute('data-theme', store.theme)
}

export function activeTab() {
    return store.tabs.find((t) => t.id === store.activeTabId) || store.tabs[0]
}

export function openTab(request) {
    const tab = newTab(request)
    store.tabs.push(tab)
    store.activeTabId = tab.id
    return tab
}

export function closeTab(id) {
    if (store.tabs.length === 1) {
        // Last tab: clear it instead of leaving nowhere to type.
        const tab = store.tabs[0]
        const fresh = newTab()
        tab.request = fresh.request
        tab.response = null
        tab.savedSnapshot = fresh.savedSnapshot
        return
    }
    const idx = store.tabs.findIndex((t) => t.id === id)
    if (idx === -1) return
    store.tabs.splice(idx, 1)
    if (store.activeTabId === id) {
        store.activeTabId = store.tabs[Math.max(0, idx - 1)].id
    }
}

export function isTabDirty(tab) {
    try {
        return JSON.stringify(tab.request) !== tab.savedSnapshot
    } catch (e) {
        return false
    }
}

export function markTabSaved(tab) {
    tab.savedSnapshot = JSON.stringify(tab.request)
    tab.saveMessage = 'Saved.'
    tab.saveMessageIsError = false
}

export function logConsole(entry) {
    store.consoleEntries.unshift({ id: Date.now() + Math.random(), time: new Date(), ...entry })
    if (store.consoleEntries.length > 100) store.consoleEntries.length = 100
}

export function setAuth(token, user) {
    store.token = token || ''
    store.user = user || null
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
    if (user) localStorage.setItem(USER_KEY, JSON.stringify(user))
    else localStorage.removeItem(USER_KEY)
}

export function clearAuth() {
    setAuth('', null)
    store.workspaces = []
    store.activeWorkspaceId = null
    store.collections = []
    store.environments = []
    store.activeEnvironmentId = null
    store.globals = []
    store.globalsUnavailable = false
    store.histories = []
    store.historyPage = 1
    store.historyLastPage = 1
    store.tabs = [newTab()]
    store.activeTabId = store.tabs[0].id
}

export async function loadWorkspaces() {
    store.workspaces = await api.listWorkspaces()
    if (!store.activeWorkspaceId && store.workspaces.length > 0) {
        store.activeWorkspaceId = store.workspaces[0].id
    }
    return store.workspaces
}

export async function loadCollections() {
    if (!store.activeWorkspaceId) {
        store.collections = []
        return []
    }
    const list = await api.listCollections(store.activeWorkspaceId)
    // Keep any already-loaded tree (folders / requests) on matching rows.
    list.forEach((c) => {
        const old = store.collections.find((o) => o.id === c.id)
        if (old) {
            c.folders = old.folders || c.folders || []
            c.requests = old.requests || c.requests || []
        }
    })
    store.collections = list
    return list
}

// Fetch one collection with its folders + requests tree and merge it in.
export async function loadCollectionDetail(id) {
    const detail = await api.getCollection(id)
    const idx = store.collections.findIndex((c) => c.id === id)
    if (idx !== -1) {
        const merged = Object.assign({}, store.collections[idx], detail)
        store.collections.splice(idx, 1, merged)
    }
    return detail
}

export async function loadEnvironments() {
    if (!store.activeWorkspaceId) {
        store.environments = []
        return []
    }
    store.environments = await api.listEnvironments(store.activeWorkspaceId)
    const active = store.environments.find((e) => e.is_active)
    if (active) {
        store.activeEnvironmentId = active.id
    } else if (store.activeEnvironmentId && !store.environments.find((e) => e.id === store.activeEnvironmentId)) {
        store.activeEnvironmentId = null
    }
    return store.environments
}

// Globals are optional until the backend ships them — a 404/501 just
// means the section stays empty with a "not available yet" note.
export async function loadGlobals() {
    try {
        store.globals = await api.listGlobals()
        store.globalsUnavailable = false
    } catch (e) {
        store.globals = []
        store.globalsUnavailable = api.isMissingEndpoint(e)
        if (!store.globalsUnavailable) throw e
    }
    return store.globals
}

export async function loadHistories(page = 1) {
    const res = await api.listHistories(page)
    store.histories = page === 1 ? res.items : store.histories.concat(res.items)
    store.historyPage = res.currentPage
    store.historyLastPage = res.lastPage
    store.historyTotal = res.total
    return store.histories
}

export async function loadMoreHistories() {
    if (store.historyPage >= store.historyLastPage) return store.histories
    return loadHistories(store.historyPage + 1)
}

// Variables of the active environment, for {{var}} preview resolution.
export function activeEnvironmentVariables() {
    const env = store.environments.find((e) => e.id === store.activeEnvironmentId)
    if (!env || !env.variables) return []
    return collectVariables(env.variables)
}

// Collection variables of one collection (lower precedence than env).
export function collectionVariables(collectionId) {
    const collection = store.collections.find((c) => c.id === collectionId)
    if (!collection || !collection.variables) return []
    return collectVariables(collection.variables)
}

// Full preview scope: globals first, then collection, environment wins on
// duplicates — the same precedence the backend applies at send time.
// (Bug §2.4: the preview used to see environment variables only.)
export function variablesForPreview(collectionId) {
    return collectVariables(
        collectVariables(store.globals),
        collectionVariables(collectionId),
        activeEnvironmentVariables(),
    )
}

export async function loadAll() {
    store.loading = true
    store.error = ''
    try {
        try {
            store.user = await api.getUser()
            localStorage.setItem(USER_KEY, JSON.stringify(store.user))
        } catch (e) {
            // Stored user is good enough if /auth/user is briefly unavailable.
            if (errMessage(e) === 'Unauthenticated.') throw e
        }
        await loadWorkspaces()
        await Promise.all([loadCollections(), loadEnvironments(), loadHistories(1), loadGlobals()])
    } catch (e) {
        store.error = errMessage(e)
        throw e
    } finally {
        store.loading = false
    }
}
