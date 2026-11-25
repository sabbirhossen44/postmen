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

// User preferences (GET /api/settings/me). Defaults until loaded; the
// backend row is created on first fetch.
const defaultSettings = {
    theme: 'dark',
    layout_density: 'comfortable',
    default_body_type: 'none',
    default_timeout: 30,
    sidebar_width: 320,
}

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
        body_type: defaultSettings.default_body_type || 'none',
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
        settings: { timeout: defaultSettings.default_timeout || 30, max_redirects: 5, follow_redirects: true, verify_ssl: true, send_cookies: true },
        description: '',
        examples: [],
    }
}

function newTab(request) {
    const req = request || blankRequest()
    return {
        id: tabSeq++,
        request: req,
        response: null,
        sending: false,
        saving: false,
        saveMessage: '',
        saveMessageIsError: false,
        // When the tab was last saved (null until the first save).
        savedAt: null,
        // JSON of the request as last saved / loaded — dirty dot compares
        // the live request against this.
        savedSnapshot: JSON.stringify(req),
    }
}

// A fresh tab points at a real public echo API, so a new user can press
// Send straight away (Postman quick-start) instead of a dead placeholder.
function prefillSampleUrl(tab) {
    tab.request.url = 'https://postman-echo.com/get'
    tab.savedSnapshot = JSON.stringify(tab.request)
}

// Panel sizes are persisted in localStorage (there is no user-settings
// endpoint on the backend, so local persistence is the only option).
const SIDEBAR_WIDTH_KEY = 'postmen_sidebar_width'
const SPLIT_KEY = 'postmen_builder_height_pct'
// Last-known user settings, used when /settings/me is unreachable.
const SETTINGS_KEY = 'postmen_settings'

/** True when the current user may edit the active workspace. Viewers on a
 * team workspace can send requests and look around, but not change anything. */
export function canEditActiveWorkspace() {
    const workspace = store.workspaces.find((w) => w.id === store.activeWorkspaceId)
    if (!workspace || workspace.type !== 'team') return true
    return workspace.role === 'owner' || workspace.role === 'admin' || workspace.role === 'editor'
}

function storedSidebarWidth() {
    const v = Number(localStorage.getItem(SIDEBAR_WIDTH_KEY) || '')
    return v >= 200 && v <= 560 ? v : 320
}

export function storedBuilderHeightPct() {
    const v = Number(localStorage.getItem(SPLIT_KEY) || '')
    return v >= 25 && v <= 75 ? v : 48
}

export function persistSidebarWidth(width) {
    try { localStorage.setItem(SIDEBAR_WIDTH_KEY, String(Math.round(width))) } catch (e) { /* ignore */ }
}

export function persistBuilderHeightPct(pct) {
    try { localStorage.setItem(SPLIT_KEY, String(Math.round(pct))) } catch (e) { /* ignore */ }
}

// Plain reactive store — no Pinia / Vuex. Every component imports this
// same object, so state stays in sync across the app.
export const store = reactive({
    user: storedUser(),
    token: localStorage.getItem(TOKEN_KEY) || '',
    workspaces: [],
    activeWorkspaceId: null,
    // Team workspace state for the active workspace.
    teamMembers: [],
    teamInvitations: [],
    teamActivity: [],
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
    // User preferences from GET /api/settings/me (null until loaded).
    settings: null,
    // Global search text from the TopBar — Sidebar filters on it.
    searchQuery: '',
    sidebarCollapsed: false,
    sidebarWidth: storedSidebarWidth(),
    consoleOpen: false,
    consoleEntries: [],
    // Multi-tab request area (Postman parity): one tab per open request.
    tabs: [newTab()],
    activeTabId: 1,
})

// The very first tab opens with the sample URL prefilled.
prefillSampleUrl(store.tabs[0])

export function setTheme(theme) {
    store.theme = theme === 'light' ? 'light' : 'dark'
    localStorage.setItem('postmen_theme', store.theme)
    document.documentElement.setAttribute('data-theme', store.theme)
}

// Apply loaded user settings: theme, layout density, sidebar width.
// New blank requests pick up default body type / timeout via
// defaultSettings (see blankRequest).
export function applySettings(settings) {
    if (!settings) return
    Object.assign(defaultSettings, settings)
    store.settings = settings
    setTheme(settings.theme || 'dark')
    if (settings.layout_density) {
        document.documentElement.setAttribute('data-density', settings.layout_density)
    }
    if (settings.sidebar_width) store.sidebarWidth = settings.sidebar_width
}

export async function loadSettings() {
    try {
        const settings = await api.getSettings()
        applySettings(settings)
        try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)) } catch (e) { /* ignore */ }
        return settings
    } catch (e) {
        // Settings are a preference, not a blocker — fall back to the
        // last-known settings saved in localStorage, if any.
        try {
            const cached = JSON.parse(localStorage.getItem(SETTINGS_KEY) || 'null')
            if (cached) {
                applySettings(cached)
                return cached
            }
        } catch (e2) { /* ignore */ }
        return null
    }
}

export async function saveSettings(patch) {
    try {
        const settings = await api.updateSettings(patch)
        applySettings(settings)
        try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)) } catch (e) { /* ignore */ }
        return settings
    } catch (e) {
        // Offline / backend down: keep the preference locally so the UI
        // still reflects it, and sync it on the next successful save.
        const settings = { ...(store.settings || {}), ...defaultSettings, ...patch }
        applySettings(settings)
        try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)) } catch (e2) { /* ignore */ }
        return settings
    }
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
        tab.savedAt = null
        prefillSampleUrl(tab)
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
    tab.savedAt = new Date()
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
    store.teamMembers = []
    store.teamInvitations = []
    store.teamActivity = []
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
    prefillSampleUrl(store.tabs[0])
}

export async function loadWorkspaces() {
    store.workspaces = await api.listWorkspaces()
    if (!store.activeWorkspaceId && store.workspaces.length > 0) {
        store.activeWorkspaceId = store.workspaces[0].id
    }
    return store.workspaces
}

/** Members, pending invitations and activity for a team workspace. */
export async function loadTeam(workspaceId) {
    if (!workspaceId) {
        store.teamMembers = []
        store.teamInvitations = []
        store.teamActivity = []
        return
    }
    const workspace = store.workspaces.find((w) => w.id === workspaceId)
    if (workspace && workspace.type !== 'team') {
        store.teamMembers = []
        store.teamInvitations = []
        store.teamActivity = []
        return
    }
    const [members, invitations, activity] = await Promise.all([
        api.listMembers(workspaceId).catch(() => []),
        api.listInvitations(workspaceId).catch(() => []),
        api.listActivity(workspaceId).catch(() => []),
    ])
    store.teamMembers = members
    store.teamInvitations = invitations
    store.teamActivity = activity
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

// Globals are optional — a 404/501 just means the section stays empty
// with a "could not be loaded" note.
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
        await Promise.all([loadCollections(), loadEnvironments(), loadHistories(1), loadGlobals(), loadSettings()])
    } catch (e) {
        store.error = errMessage(e)
        throw e
    } finally {
        store.loading = false
    }
}
