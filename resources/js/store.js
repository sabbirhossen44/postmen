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
    histories: [],
    loading: false,
    error: '',
})

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
    store.histories = []
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

export async function loadHistories() {
    store.histories = await api.listHistories()
    return store.histories
}

// Variables of the active environment, for {{var}} preview resolution.
export function activeEnvironmentVariables() {
    const env = store.environments.find((e) => e.id === store.activeEnvironmentId)
    if (!env || !env.variables) return []
    return collectVariables(env.variables)
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
        await Promise.all([loadCollections(), loadEnvironments(), loadHistories()])
    } catch (e) {
        store.error = errMessage(e)
        throw e
    } finally {
        store.loading = false
    }
}
