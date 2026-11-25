<template>
    <AuthView v-if="!store.token" @authenticated="onAuthenticated" />
    <div v-else class="flex h-screen flex-col overflow-hidden">
        <TopBar
            @logged-out="onLoggedOut"
            @manage-environments="sidebarTab = 'environments'"
            @imported="sidebarTab = 'collections'"
        />
        <div v-if="store.error" class="shrink-0 bg-red-950 px-4 py-2 text-sm text-red-300">
            {{ store.error }}
        </div>
        <div class="flex min-h-0 flex-1">
            <Sidebar
                :active-request-id="request.id"
                :active-tab="sidebarTab"
                @load-request="loadRequest"
                @load-history="loadHistoryItem"
                @clear-request="resetBuilder"
            />
            <main class="flex min-h-0 min-w-0 flex-1 flex-col">
                <RequestBuilder
                    :request="request"
                    :sending="sending"
                    :save-message="saveMessage"
                    :save-message-is-error="saveMessageIsError"
                    @send="handleSend"
                    @save="handleSave"
                />
                <ResponseViewer :response="response" :sending="sending" />
            </main>
        </div>
    </div>
</template>

<script>
import { api, errMessage, mediaUrl } from '../api.js'
import { store, loadAll, loadCollections, loadCollectionDetail, loadHistories } from '../store.js'
import AuthView from './AuthView.vue'
import TopBar from './TopBar.vue'
import Sidebar from './Sidebar.vue'
import RequestBuilder from './RequestBuilder.vue'
import ResponseViewer from './ResponseViewer.vue'

function blankRequest() {
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
        auth: { token: '', username: '', password: '', key: '', value: '', add_to: 'header' },
        body_type: 'none',
        body: { raw: '', formdata: [{ key: '', value: '', type: 'text', enabled: true }], urlencoded: [{ key: '', value: '', enabled: true }] },
    }
}

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
    req.auth = Object.assign(
        { token: '', username: '', password: '', key: '', value: '', add_to: 'header' },
        saved.auth || {},
    )
    req.body_type = saved.body_type || 'none'
    const body = saved.body || {}
    req.body = {
        raw: typeof body.raw === 'string' ? body.raw : (typeof body === 'string' ? body : ''),
        formdata: normalizeRows(body.formdata, true),
        urlencoded: normalizeRows(body.urlencoded),
    }
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
            return row
        })
}

export default {
    name: 'App',
    components: { AuthView, TopBar, Sidebar, RequestBuilder, ResponseViewer },
    data() {
        return {
            store,
            sidebarTab: 'collections',
            request: blankRequest(),
            response: null,
            sending: false,
            saveMessage: '',
            saveMessageIsError: false,
        }
    },
    mounted() {
        window.addEventListener('postmen:unauthorized', this.onUnauthorized)
        if (store.token) {
            loadAll().catch(() => {
                // loadAll surfaces the error in store.error; stay logged in
                // with whatever cached state we have.
            })
        }
    },
    beforeUnmount() {
        window.removeEventListener('postmen:unauthorized', this.onUnauthorized)
    },
    methods: {
        onUnauthorized() {
            this.resetBuilder()
            this.response = null
        },
        async onAuthenticated() {
            await loadAll().catch(() => {})
        },
        onLoggedOut() {
            this.resetBuilder()
            this.response = null
            this.sidebarTab = 'collections'
        },
        resetBuilder() {
            this.request = blankRequest()
            this.saveMessage = ''
        },
        async loadRequest(saved) {
            try {
                const full = saved.id ? await api.getRequest(saved.id) : saved
                this.request = normalizeRequest(full)
                this.saveMessage = ''
                this.response = null
            } catch (e) {
                // Fall back to the tree copy if the detail fetch fails.
                this.request = normalizeRequest(saved)
            }
        },
        loadHistoryItem(item) {
            this.request = blankRequest()
            this.request.method = (item.method || 'GET').toUpperCase()
            this.request.url = item.url || ''
            this.response = null
        },
        requestPayload() {
            const body = {}
            if (this.request.body_type === 'raw') {
                body.raw = this.request.body.raw || ''
            } else if (this.request.body_type === 'form-data') {
                body.formdata = (this.request.body.formdata || [])
                    .filter((r) => r.key && String(r.key).trim() !== '')
                    .map((r) => ({ key: r.key, value: r.value || '', type: r.type || 'text', enabled: r.enabled !== false }))
            } else if (this.request.body_type === 'urlencoded') {
                body.urlencoded = cleanRows(this.request.body.urlencoded)
            }
            return {
                name: this.request.name || 'Untitled Request',
                method: this.request.method,
                url: this.request.url,
                params: cleanRows(this.request.params),
                headers: cleanRows(this.request.headers),
                auth_type: this.request.auth_type,
                auth: this.request.auth,
                body_type: this.request.body_type,
                body,
                folder_id: this.request.folder_id,
            }
        },
        async handleSend() {
            if (!this.request.url.trim() || this.sending) return
            this.sending = true
            this.response = null
            try {
                // Upload form-data files first; send their stored path.
                const payload = this.requestPayload()
                if (this.request.body_type === 'form-data') {
                    const rows = this.request.body.formdata || []
                    for (let i = 0; i < rows.length; i++) {
                        const row = rows[i]
                        if (row.type === 'file' && row._file) {
                            const media = await api.uploadMedia(row._file)
                            const sent = payload.body.formdata.find((f) => f.key === row.key)
                            if (sent) sent.value = mediaUrl(media) || media.path || row.value
                        }
                    }
                }
                const result = await api.sendRequest(Object.assign(payload, {
                    environment_id: store.activeEnvironmentId,
                    collection_id: this.request.collection_id,
                    save_history: true,
                }))
                this.response = result
                await loadHistories()
            } catch (e) {
                // Show a failed send as a response so the error is visible
                // in place instead of only an alert.
                this.response = {
                    status: (e.response && e.response.status) || 0,
                    status_text: errMessage(e),
                    time_ms: 0,
                    size: 0,
                    headers: {},
                    body: (e.response && e.response.data) ? JSON.stringify(e.response.data) : errMessage(e),
                    content_type: 'text/plain',
                    is_json: false,
                }
            } finally {
                this.sending = false
            }
        },
        async handleSave() {
            this.saveMessage = ''
            this.saveMessageIsError = false
            let collectionId = this.request.collection_id
            if (!collectionId) {
                if (store.collections.length > 0) {
                    collectionId = store.collections[0].id
                    this.request.collection_id = collectionId
                } else {
                    this.saveMessage = 'Create a collection first, then save.'
                    this.saveMessageIsError = true
                    return
                }
            }
            try {
                const payload = this.requestPayload()
                let saved
                if (this.request.id) {
                    saved = await api.updateRequest(this.request.id, payload)
                } else {
                    saved = await api.createRequest(collectionId, payload)
                }
                if (saved && saved.id) this.request.id = saved.id
                this.saveMessage = 'Saved.'
                await loadCollectionDetail(collectionId)
            } catch (e) {
                this.saveMessage = 'Save failed: ' + errMessage(e)
                this.saveMessageIsError = true
            }
        },
    },
}
</script>
