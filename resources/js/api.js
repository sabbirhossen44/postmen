import axios from 'axios'

// Token / user are kept in localStorage so a refresh keeps the session.
export const TOKEN_KEY = 'postmen_token'
export const USER_KEY = 'postmen_user'

const http = axios.create({
    baseURL: '/api',
    headers: { Accept: 'application/json' },
})

// Attach the Bearer token to every request.
http.interceptors.request.use((config) => {
    const token = localStorage.getItem(TOKEN_KEY)
    if (token) {
        config.headers.Authorization = 'Bearer ' + token
    }
    return config
})

// An expired / revoked token logs the user out everywhere.
http.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response && error.response.status === 401) {
            localStorage.removeItem(TOKEN_KEY)
            localStorage.removeItem(USER_KEY)
            window.dispatchEvent(new CustomEvent('postmen:unauthorized'))
        }
        return Promise.reject(error)
    },
)

// The API wraps payloads as { success, data: ... }; list endpoints may also
// return a paginated { data: [...] } shape, so unwrap defensively.
function dataOf(res) {
    if (res.data && res.data.data !== undefined) return res.data.data
    return res.data
}

function listOf(res) {
    const d = dataOf(res)
    if (Array.isArray(d)) return d
    if (d && Array.isArray(d.data)) return d.data
    return []
}

// Pull a readable message out of an axios error for display in the UI.
export function errMessage(err) {
    if (err.response && err.response.data) {
        const d = err.response.data
        if (d.message) return d.message
        if (d.errors) {
            const first = Object.values(d.errors)[0]
            if (Array.isArray(first) && first[0]) return first[0]
        }
    }
    return err.message || 'Something went wrong'
}

// Full URL for a stored media record.
export function mediaUrl(media) {
    if (!media) return ''
    if (media.url) return media.url
    if (media.path) return '/storage/' + media.path.replace(/^\//, '')
    return ''
}

export const api = {
    // Auth
    login(credentials) {
        return http.post('/auth/login', credentials).then(dataOf)
    },
    register(payload) {
        return http.post('/auth/register', payload).then(dataOf)
    },
    logout() {
        return http.post('/auth/logout').then((res) => res.data)
    },
    getUser() {
        return http.get('/auth/user').then(dataOf)
    },

    // Workspaces
    listWorkspaces() {
        return http.get('/workspaces').then(listOf)
    },
    createWorkspace(payload) {
        return http.post('/workspaces', payload).then(dataOf)
    },
    getWorkspace(id) {
        return http.get('/workspaces/' + id).then(dataOf)
    },
    updateWorkspace(id, payload) {
        return http.put('/workspaces/' + id, payload).then(dataOf)
    },
    deleteWorkspace(id) {
        return http.delete('/workspaces/' + id).then((res) => res.data)
    },

    // Collections
    listCollections(workspaceId) {
        const params = workspaceId ? { workspace_id: workspaceId } : {}
        return http.get('/collections', { params }).then(listOf)
    },
    getCollection(id) {
        return http.get('/collections/' + id).then(dataOf)
    },
    createCollection(payload) {
        return http.post('/collections', payload).then(dataOf)
    },
    updateCollection(id, payload) {
        return http.put('/collections/' + id, payload).then(dataOf)
    },
    deleteCollection(id) {
        return http.delete('/collections/' + id).then((res) => res.data)
    },
    exportCollection(id) {
        return http.get('/collections/' + id + '/export', { responseType: 'blob' })
    },
    importCollection(workspaceId, fileOrJson) {
        if (fileOrJson instanceof File) {
            const form = new FormData()
            form.append('workspace_id', workspaceId)
            form.append('file', fileOrJson)
            return http.post('/collections/import', form).then(dataOf)
        }
        return http.post('/collections/import', {
            workspace_id: workspaceId,
            collection: fileOrJson,
        }).then(dataOf)
    },

    // Folders
    createFolder(collectionId, payload) {
        return http.post('/collections/' + collectionId + '/folders', payload).then(dataOf)
    },
    updateFolder(id, payload) {
        return http.put('/folders/' + id, payload).then(dataOf)
    },
    deleteFolder(id) {
        return http.delete('/folders/' + id).then((res) => res.data)
    },

    // Requests
    createRequest(collectionId, payload) {
        return http.post('/collections/' + collectionId + '/requests', payload).then(dataOf)
    },
    getRequest(id) {
        return http.get('/requests/' + id).then(dataOf)
    },
    updateRequest(id, payload) {
        return http.put('/requests/' + id, payload).then(dataOf)
    },
    deleteRequest(id) {
        return http.delete('/requests/' + id).then((res) => res.data)
    },
    duplicateRequest(id) {
        return http.post('/requests/' + id + '/duplicate').then(dataOf)
    },

    // Environments
    listEnvironments(workspaceId) {
        const params = workspaceId ? { workspace_id: workspaceId } : {}
        return http.get('/environments', { params }).then(listOf)
    },
    createEnvironment(payload) {
        return http.post('/environments', payload).then(dataOf)
    },
    updateEnvironment(id, payload) {
        return http.put('/environments/' + id, payload).then(dataOf)
    },
    deleteEnvironment(id) {
        return http.delete('/environments/' + id).then((res) => res.data)
    },
    activateEnvironment(id) {
        return http.post('/environments/' + id + '/activate').then(dataOf)
    },

    // History
    listHistories(page = 1) {
        return http.get('/histories', { params: { page } }).then((res) => {
            const d = res.data && res.data.data !== undefined ? res.data.data : res.data
            // Paginated shape: { data: [...], current_page, last_page, ... }
            if (d && Array.isArray(d.data)) {
                return { items: d.data, currentPage: d.current_page || 1, lastPage: d.last_page || 1, total: d.total || d.data.length }
            }
            return { items: listOf(res), currentPage: 1, lastPage: 1, total: 0 }
        })
    },
    getHistory(id) {
        return http.get('/histories/' + id).then(dataOf)
    },
    clearHistories() {
        return http.delete('/histories').then((res) => res.data)
    },
    deleteHistory(id) {
        return http.delete('/histories/' + id).then((res) => res.data)
    },

    // Media
    uploadMedia(file) {
        const form = new FormData()
        form.append('file', file)
        return http.post('/media', form).then(dataOf)
    },

    // Send a request through the backend proxy
    sendRequest(payload) {
        return http.post('/send', payload).then(dataOf)
    },

    // ---- Backend parity (Phase 4-6): these endpoints land with the
    // backend workers. Callers must handle 404/501 gracefully — the
    // endpoint simply is not available yet. ----
    isMissingEndpoint(err) {
        return !!err.response && (err.response.status === 404 || err.response.status === 501)
    },

    // Globals (workspace-wide variables, same shape as environment vars)
    listGlobals() {
        return http.get('/globals').then(listOf)
    },
    saveGlobals(payload) {
        return http.post('/globals', payload).then(dataOf)
    },
    updateGlobal(id, payload) {
        return http.put('/globals/' + id, payload).then(dataOf)
    },
    deleteGlobal(id) {
        return http.delete('/globals/' + id).then((res) => res.data)
    },

    // Resolve an environment's variables (map + scope) for preview
    resolveEnvironment(id) {
        return http.get('/environments/' + id + '/resolve').then(dataOf)
    },
    exportEnvironment(id) {
        return http.get('/environments/' + id + '/export', { responseType: 'blob' })
    },

    // OAuth 2.0 token exchange for a request
    fetchOAuthToken(requestId, payload) {
        return http.post('/requests/' + requestId + '/oauth/token', payload).then(dataOf)
    },

    // Collection Runner
    runCollection(collectionId, payload) {
        return http.post('/collections/' + collectionId + '/run', payload).then(dataOf)
    },
    getRun(id) {
        return http.get('/runs/' + id).then(dataOf)
    },

    // Import: cURL command or an OpenAPI document
    importCurl(payload) {
        return http.post('/import/curl', payload).then(dataOf)
    },
    importOpenapi(fileOrJson) {
        if (fileOrJson instanceof File) {
            const form = new FormData()
            form.append('file', fileOrJson)
            return http.post('/import/openapi', form).then(dataOf)
        }
        return http.post('/import/openapi', { openapi: fileOrJson }).then(dataOf)
    },

    // Code generation for a request
    generateCode(payload) {
        return http.post('/code/generate', payload).then(dataOf)
    },
    getRequestCode(requestId, language) {
        return http.get('/requests/' + requestId + '/code', { params: { language } }).then(dataOf)
    },

    // Stored cookies (the send proxy's cookie jar)
    listCookies() {
        return http.get('/cookies').then(listOf)
    },
    clearCookies() {
        return http.delete('/cookies').then((res) => res.data)
    },
}
