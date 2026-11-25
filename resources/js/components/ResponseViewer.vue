<template>
    <div class="pm-panel min-h-0 flex-1 overflow-y-auto p-4">
        <div v-if="sending || (loading && !response)" class="flex h-full flex-col gap-3 p-1" aria-label="Loading response">
            <div class="flex items-center gap-3">
                <div class="pm-skeleton h-5 w-24"></div>
                <div class="pm-skeleton h-5 w-20"></div>
                <div class="pm-skeleton h-5 w-20"></div>
                <span class="pm-spinner pm-spinner-dark" aria-hidden="true"></span>
                <span class="text-sm pm-muted">Sending request…</span>
            </div>
            <div class="pm-skeleton h-8 w-full"></div>
            <div class="pm-skeleton h-40 w-full"></div>
            <div class="pm-skeleton h-6 w-3/4"></div>
        </div>

        <EmptyState
            v-else-if="!response"
            title="No response yet"
            description="Enter a URL above and press Send — the status, headers, body and test results appear here."
        >
            <template #icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6"><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>
            </template>
            <template #actions>
                <button type="button" @click="$emit('try-sample')" class="pm-btn pm-btn-primary pm-btn-sm">Try a sample request</button>
                <button type="button" @click="$emit('open-palette')" class="pm-btn pm-btn-ghost pm-btn-sm">Find a request (⌘K)</button>
            </template>
        </EmptyState>

        <div v-else>
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <span class="text-sm font-semibold" :class="statusClass(response.status)">
                    {{ response.status }} {{ response.status_text || '' }}
                </span>
                <span class="text-sm pm-muted">Time: {{ formatTime(response.time_ms) }}</span>
                <span class="text-sm pm-muted">Size: {{ formatBytes(response.size) }}</span>
                <span v-if="response.content_type" class="truncate text-sm pm-muted">{{ response.content_type }}</span>
                <span v-if="response.error" class="text-sm status-error">{{ response.error }}</span>
                <span class="flex-1"></span>
                <button type="button" @click="copyBody" class="pm-btn pm-btn-ghost pm-btn-sm">Copy</button>
                <button type="button" @click="downloadBody" class="pm-btn pm-btn-ghost pm-btn-sm">Download ({{ isJson ? '.json' : '.txt' }})</button>
                <button
                    v-if="request && request.id"
                    type="button"
                    @click="showExampleForm = !showExampleForm"
                    class="pm-btn pm-btn-ghost pm-btn-sm"
                    title="Save this response as a named example on the request"
                >
                    Save as Example
                </button>
            </div>
            <div v-if="showExampleForm" class="mb-3 flex items-center gap-2">
                <input
                    v-model="exampleName"
                    type="text"
                    placeholder="Example name (e.g. Success 200)"
                    class="min-w-0 flex-1 rounded border pm-input px-2 py-1.5 text-sm outline-none focus:border-[#FF6C37]"
                    @keyup.enter="saveAsExample"
                >
                <button type="button" @click="saveAsExample" :disabled="!exampleName.trim() || exampleSaving" class="pm-btn pm-btn-ghost pm-btn-sm">
                    {{ exampleSaving ? 'Saving…' : 'Save Example' }}
                </button>
                <span v-if="exampleMessage" class="text-xs" :class="exampleError ? 'text-red-400' : 'text-green-400'">{{ exampleMessage }}</span>
            </div>

            <div class="mb-3 flex flex-wrap items-center gap-1 border-b pm-border">
                <button
                    v-for="t in responseTabs"
                    :key="t.key"
                    type="button"
                    @click="activeTab = t.key"
                    :class="activeTab === t.key ? 'pm-tab-active' : ''"
                    class="pm-tab"
                >
                    {{ t.label }}
                </button>
                <span class="flex-1"></span>
                <input
                    v-if="activeTab === 'body'"
                    v-model="search"
                    type="text"
                    placeholder="Search in response…"
                    class="pm-field pm-field-xs mb-1 w-48"
                >
                <span v-if="search && matchCount > 0" class="mb-1 text-xs pm-faint">{{ matchCount }} match{{ matchCount === 1 ? '' : 'es' }}</span>
            </div>

            <div v-show="activeTab === 'body'">
                <div class="mb-2 flex gap-1">
                    <button
                        v-for="m in ['pretty', 'raw', 'preview']"
                        :key="m"
                        type="button"
                        @click="bodyMode = m"
                        :class="bodyMode === m ? 'pm-accent-text pm-border-accent' : 'pm-muted border-transparent'"
                        class="rounded border px-2 py-1 text-xs font-medium capitalize"
                    >
                        {{ m }}
                    </button>
                </div>
                <div v-if="bodyMode === 'pretty'">
                    <div v-if="isJson" class="overflow-x-auto rounded border pm-border pm-code-bg p-3 font-mono text-[13px] leading-relaxed">
                        <JsonTree :value="parsedBody" :search="search" />
                    </div>
                    <pre
                        v-else
                        class="overflow-x-auto whitespace-pre-wrap break-words rounded border pm-border pm-code-bg p-3 font-mono text-[13px] leading-relaxed pm-text-2"
                    >{{ rawBody }}</pre>
                </div>
                <pre
                    v-if="bodyMode === 'raw'"
                    class="overflow-x-auto whitespace-pre-wrap break-words rounded border pm-border pm-code-bg p-3 font-mono text-[13px] leading-relaxed pm-text-2"
                >{{ rawBody }}</pre>
                <div v-if="bodyMode === 'preview'">
                    <iframe
                        v-if="isHtml"
                        :srcdoc="rawBody"
                        sandbox=""
                        class="h-96 w-full rounded border pm-border bg-white"
                        title="HTML preview"
                    ></iframe>
                    <p v-else class="text-sm pm-faint">Preview renders HTML responses. This response is {{ response.content_type || 'not HTML' }} — use Pretty or Raw instead.</p>
                </div>
            </div>

            <div v-show="activeTab === 'cookies'">
                <table v-if="cookies.length > 0" class="mb-4 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b pm-border text-xs pm-faint">
                            <th class="py-2 pr-4 font-medium">Name</th>
                            <th class="py-2 pr-4 font-medium">Value</th>
                            <th class="py-2 font-medium">Attributes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(c, i) in cookies" :key="i" class="border-b pm-border align-top">
                            <td class="py-2 pr-4 font-mono pm-text-2">{{ c.name }}</td>
                            <td class="break-all py-2 pr-4 font-mono pm-muted">{{ c.value }}</td>
                            <td class="py-2 font-mono text-xs pm-muted">{{ c.attributes }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mb-4 text-sm pm-faint">No cookies in this response.</p>

                <!-- Stored cookie jar (GET /api/cookies) -->
                <div class="border-t pm-border pt-3">
                    <div class="mb-2 flex items-center gap-2">
                        <h4 class="text-xs font-medium pm-text-2">Stored Cookies</h4>
                        <span class="flex-1"></span>
                        <button
                            type="button"
                            @click="loadStoredCookies"
                            class="pm-btn pm-btn-ghost pm-btn-sm"
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            @click="clearStoredCookies"
                            :disabled="storedCookies.length === 0"
                            class="pm-btn pm-btn-ghost pm-btn-sm"
                        >
                            Clear All
                        </button>
                    </div>
                    <p v-if="cookiesNote" class="mb-2 text-xs text-amber-400">{{ cookiesNote }}</p>
                    <table v-if="storedCookies.length > 0" class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b pm-border text-xs pm-faint">
                                <th class="py-2 pr-4 font-medium">Name</th>
                                <th class="py-2 pr-4 font-medium">Value</th>
                                <th class="py-2 font-medium">Domain</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(c, i) in storedCookies" :key="i" class="border-b pm-border align-top">
                                <td class="py-2 pr-4 font-mono pm-text-2">{{ c.name }}</td>
                                <td class="break-all py-2 pr-4 font-mono pm-muted">{{ c.value }}</td>
                                <td class="py-2 font-mono text-xs pm-muted">{{ c.domain || c.host || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else-if="!cookiesNote" class="text-sm pm-faint">No stored cookies.</p>
                </div>
            </div>

            <div v-show="activeTab === 'headers'">
                <table v-if="headerRows.length > 0" class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b pm-border text-xs pm-faint">
                            <th class="py-2 pr-4 font-medium">Key</th>
                            <th class="py-2 font-medium">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(h, i) in headerRows" :key="i" class="border-b pm-border align-top">
                            <td class="py-2 pr-4 font-mono pm-text-2">{{ h.key }}</td>
                            <td class="break-all py-2 font-mono pm-muted">{{ h.value }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-sm pm-faint">No headers returned.</p>
            </div>

            <div v-show="activeTab === 'tests'">
                <div v-if="testResults.length > 0">
                    <div class="mb-2 text-sm pm-muted">
                        {{ passedTests }} / {{ testResults.length }} passed
                    </div>
                    <div v-for="(t, i) in testResults" :key="i" class="py-1 text-sm" :class="t.passed ? 'status-ok' : 'status-error'">
                        {{ t.passed ? '✓' : '✗' }} {{ t.name }}
                        <span v-if="!t.passed && t.error" class="block pl-5 font-mono text-xs pm-muted">{{ t.error }}</span>
                    </div>
                </div>
                <p v-else class="text-sm pm-faint">
                    No test results. Add a test script in the Scripts tab and send the request — results appear here.
                </p>
                <div v-if="consoleLogs.length > 0" class="mt-4 border-t pm-border pt-3">
                    <h4 class="mb-2 text-xs font-medium pm-text-2">Console</h4>
                    <div class="max-h-48 overflow-y-auto rounded border pm-border pm-code-bg p-3 font-mono text-xs leading-relaxed pm-muted">
                        <div v-for="(line, i) in consoleLogs" :key="i" class="whitespace-pre-wrap break-words py-0.5">{{ formatLog(line) }}</div>
                    </div>
                </div>
            </div>

            <div v-show="activeTab === 'examples'">
                <p v-if="!request || !request.id" class="text-sm pm-faint">Save the request first — examples live on a saved request.</p>
                <p v-else-if="examples.length === 0" class="text-sm pm-faint">No saved examples yet. Send the request, then use “Save as Example” above.</p>
                <div v-for="(ex, i) in examples" :key="i" class="mb-3 rounded border pm-border">
                    <div class="flex items-center gap-2 px-3 py-2">
                        <span class="text-sm font-medium pm-text-2">{{ ex.name }}</span>
                        <span v-if="ex.status" class="text-xs" :class="ex.status >= 200 && ex.status < 300 ? 'status-ok' : 'status-error'">{{ ex.status }} {{ ex.status_text || '' }}</span>
                        <span v-if="ex.saved_at" class="text-xs pm-faint">{{ String(ex.saved_at).slice(0, 10) }}</span>
                        <span class="flex-1"></span>
                        <button type="button" @click="expandedExample = expandedExample === i ? -1 : i" class="rounded border pm-border px-2 py-1 text-xs pm-text-2 hover:pm-active">{{ expandedExample === i ? 'Hide' : 'View' }}</button>
                        <button type="button" @click="deleteExample(i)" class="rounded border pm-border px-2 py-1 text-xs pm-text-2 hover:pm-active hover:text-red-400">Delete</button>
                    </div>
                    <div v-if="expandedExample === i" class="border-t pm-border px-3 py-2">
                        <p v-if="ex.content_type" class="mb-2 font-mono text-xs pm-muted">{{ ex.content_type }}</p>
                        <pre class="overflow-x-auto whitespace-pre-wrap break-words rounded border pm-border pm-code-bg p-3 font-mono text-[13px] leading-relaxed pm-text-2">{{ exampleBody(ex) }}</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { prettyJson, formatBytes, formatTime } from '../utils/variables.js'
import { statusClass } from '../utils/methods.js'
import { api, errMessage } from '../api.js'
import { toastSuccess, toastError } from '../utils/toast.js'
import JsonTree from './JsonTree.vue'
import EmptyState from './EmptyState.vue'

export default {
    name: 'ResponseViewer',
    components: { JsonTree, EmptyState },
    props: {
        response: { type: Object, default: null },
        sending: { type: Boolean, default: false },
        request: { type: Object, default: null },
        loading: { type: Boolean, default: false },
    },
    emits: ['examples-changed', 'open-palette', 'try-sample'],

    data() {
        return {
            activeTab: 'body', bodyMode: 'pretty', search: '', storedCookies: [], cookiesNote: '', cookiesLoaded: false,
            showExampleForm: false, exampleName: '', exampleSaving: false, exampleMessage: '', exampleError: false,
            expandedExample: -1,
        }
    },
    watch: {
        activeTab(value) {
            if (value === 'cookies' && !this.cookiesLoaded) this.loadStoredCookies()
        },
    },
    computed: {
        responseTabs() {
            return [
                { key: 'body', label: 'Body' },
                { key: 'cookies', label: 'Cookies (' + this.cookies.length + ')' },
                { key: 'headers', label: 'Headers (' + this.headerRows.length + ')' },
                { key: 'tests', label: 'Test Results' + (this.testResults.length > 0 ? ' (' + this.testResults.length + ')' : '') },
                { key: 'examples', label: 'Examples' + (this.examples.length > 0 ? ' (' + this.examples.length + ')' : '') },
            ]
        },
        examples() {
            return (this.request && Array.isArray(this.request.examples)) ? this.request.examples : []
        },
        isJson() {
            if (!this.response) return false
            if (this.response.is_json) return true
            const ct = (this.response.content_type || '').toLowerCase()
            return ct.includes('json')
        },
        isHtml() {
            const ct = (this.response && this.response.content_type || '').toLowerCase()
            return ct.includes('html')
        },
        rawBody() {
            if (!this.response) return ''
            const b = this.response.body
            if (b === null || b === undefined) return ''
            return typeof b === 'string' ? b : prettyJson(b)
        },
        parsedBody() {
            try { return JSON.parse(this.rawBody) } catch (e) { return this.rawBody }
        },
        matchCount() {
            if (!this.search) return 0
            const hay = this.rawBody.toLowerCase()
            const needle = this.search.toLowerCase()
            let count = 0
            let idx = 0
            while ((idx = hay.indexOf(needle, idx)) !== -1) { count++; idx += needle.length }
            return count
        },
        testResults() {
            return (this.response && this.response.test_results) || []
        },
        passedTests() {
            return this.testResults.filter((t) => t.passed).length
        },
        consoleLogs() {
            return (this.response && this.response.console_logs) || []
        },
        headerRows() {
            if (!this.response || !this.response.headers) return []
            const h = this.response.headers
            if (Array.isArray(h)) {
                return h.map((item) => {
                    if (typeof item === 'string') {
                        const idx = item.indexOf(':')
                        return idx === -1
                            ? { key: item, value: '' }
                            : { key: item.slice(0, idx).trim(), value: item.slice(idx + 1).trim() }
                    }
                    return { key: item.key || item.name || '', value: item.value || '' }
                })
            }
            return Object.keys(h).map((key) => ({ key, value: Array.isArray(h[key]) ? h[key].join(', ') : String(h[key]) }))
        },
        cookies() {
            const out = []
            const setCookie = this.headerRows.filter((h) => h.key.toLowerCase() === 'set-cookie')
            setCookie.forEach((h) => {
                const parts = String(h.value).split(';').map((p) => p.trim())
                const first = parts[0] || ''
                const eq = first.indexOf('=')
                out.push({
                    name: eq === -1 ? first : first.slice(0, eq),
                    value: eq === -1 ? '' : first.slice(eq + 1),
                    attributes: parts.slice(1).join('; '),
                })
            })
            return out
        },
    },
    methods: {
        formatBytes,
        formatTime,
        statusClass,
        copyBody() {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(this.rawBody)
                    .then(() => toastSuccess('Response copied to clipboard.'))
                    .catch(() => toastError('Could not copy the response — select the text and copy it manually.'))
            } else {
                toastError('Copy is not available in this browser — select the text and copy it manually.')
            }
        },
        downloadBody() {
            const blob = new Blob([this.rawBody], { type: this.response.content_type || 'text/plain' })
            const url = window.URL.createObjectURL(blob)
            const a = document.createElement('a')
            a.href = url
            a.download = 'response' + (this.isJson ? '.json' : '.txt')
            document.body.appendChild(a)
            a.click()
            a.remove()
            window.URL.revokeObjectURL(url)
        },
        exampleBody(ex) {
            const b = ex.body
            if (b === null || b === undefined) return ''
            return typeof b === 'string' ? b : prettyJson(b)
        },
        async saveAsExample() {
            if (!this.request || !this.request.id || !this.exampleName.trim() || !this.response) return
            this.exampleSaving = true
            this.exampleMessage = ''
            this.exampleError = false
            try {
                const examples = await api.saveExample(this.request.id, {
                    name: this.exampleName.trim(),
                    status: this.response.status || null,
                    status_text: this.response.status_text || '',
                    headers: this.response.headers || {},
                    body: this.rawBody,
                    content_type: this.response.content_type || '',
                    time_ms: this.response.time_ms || 0,
                })
                this.$emit('examples-changed', Array.isArray(examples) ? examples : [])
                this.exampleName = ''
                this.showExampleForm = false
                this.exampleMessage = 'Example saved.'
                this.activeTab = 'examples'
            } catch (e) {
                this.exampleMessage = 'Save failed: ' + errMessage(e)
                this.exampleError = true
            } finally {
                this.exampleSaving = false
            }
        },
        async deleteExample(index) {
            if (!this.request || !this.request.id) return
            try {
                const examples = await api.deleteExample(this.request.id, index)
                this.$emit('examples-changed', Array.isArray(examples) ? examples : [])
                this.expandedExample = -1
            } catch (e) {
                toastError('Delete failed: ' + errMessage(e))
            }
        },
        formatLog(line) {
            if (typeof line === 'string') return line
            try { return JSON.stringify(line) } catch (e) { return String(line) }
        },
        async loadStoredCookies() {
            this.cookiesNote = ''
            try {
                const list = await api.listCookies()
                this.storedCookies = Array.isArray(list) ? list : []
                this.cookiesLoaded = true
            } catch (e) {
                this.storedCookies = []
                this.cookiesNote = 'Could not load stored cookies: ' + errMessage(e)
            }
        },
        async clearStoredCookies() {
            try {
                await api.clearCookies()
                this.storedCookies = []
                toastSuccess('Stored cookies cleared.')
            } catch (e) {
                toastError('Clear cookies failed: ' + errMessage(e))
            }
        },
    },
}
</script>
