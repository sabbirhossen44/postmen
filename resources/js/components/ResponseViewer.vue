<template>
    <div class="pm-panel min-h-0 flex-1 overflow-y-auto p-4">
        <div v-if="sending" class="flex h-full items-center justify-center">
            <p class="text-sm pm-muted">Sending request…</p>
        </div>

        <div v-else-if="!response" class="flex h-full flex-col items-center justify-center text-center">
            <p class="text-lg font-medium pm-text-2">No response yet</p>
            <p class="mt-1 max-w-sm text-sm pm-faint">
                Enter a URL above and press Send to see the response here.
            </p>
        </div>

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
                <button type="button" @click="copyBody" class="rounded border pm-border px-2 py-1 text-xs pm-text-2 hover:pm-active">Copy</button>
                <button type="button" @click="downloadBody" class="rounded border pm-border px-2 py-1 text-xs pm-text-2 hover:pm-active">Download</button>
                <button type="button" @click="saveResponse" class="rounded border pm-border px-2 py-1 text-xs pm-text-2 hover:pm-active" title="Save response to a file">Save Response</button>
            </div>

            <div class="mb-3 flex flex-wrap items-center gap-1 border-b pm-border">
                <button
                    v-for="t in responseTabs"
                    :key="t.key"
                    type="button"
                    @click="activeTab = t.key"
                    :class="activeTab === t.key ? 'border-[#FF6C37] pm-text' : 'border-transparent pm-muted hover:pm-text'"
                    class="border-b-2 px-3 py-2 text-xs font-medium"
                >
                    {{ t.label }}
                </button>
                <span class="flex-1"></span>
                <input
                    v-if="activeTab === 'body'"
                    v-model="search"
                    type="text"
                    placeholder="Search in response…"
                    class="mb-1 w-48 rounded border pm-input px-2 py-1 text-xs outline-none focus:border-[#FF6C37]"
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
                        :class="bodyMode === m ? 'pm-accent-text border-[#FF6C37]' : 'pm-muted border-transparent'"
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
                <table v-if="cookies.length > 0" class="w-full text-left text-sm">
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
                <p v-else class="text-sm pm-faint">No cookies in this response.</p>
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
                    <div v-for="(t, i) in testResults" :key="i" class="py-1 text-sm" :class="t.passed ? 'status-ok' : 'status-error'">
                        {{ t.passed ? '✓' : '✗' }} {{ t.name }}
                    </div>
                </div>
                <p v-else class="text-sm pm-faint">
                    No test results. Post-response scripts are stored but not executed yet —
                    the sandboxed script runner (Phase 5) will feed this tab.
                </p>
            </div>
        </div>
    </div>
</template>

<script>
import { prettyJson, formatBytes, formatTime } from '../utils/variables.js'
import { statusClass } from '../utils/methods.js'
import JsonTree from './JsonTree.vue'

export default {
    name: 'ResponseViewer',
    components: { JsonTree },
    props: {
        response: { type: Object, default: null },
        sending: { type: Boolean, default: false },
    },
    data() {
        return { activeTab: 'body', bodyMode: 'pretty', search: '' }
    },
    computed: {
        responseTabs() {
            return [
                { key: 'body', label: 'Body' },
                { key: 'cookies', label: 'Cookies (' + this.cookies.length + ')' },
                { key: 'headers', label: 'Headers (' + this.headerRows.length + ')' },
                { key: 'tests', label: 'Test Results' + (this.testResults.length > 0 ? ' (' + this.testResults.length + ')' : '') },
            ]
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
            if (navigator.clipboard) navigator.clipboard.writeText(this.rawBody).catch(() => {})
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
        saveResponse() {
            // Saved examples (per-request named responses) are Phase 8;
            // until then "Save Response" downloads the body to a file.
            this.downloadBody()
        },
    },
}
</script>
