<template>
    <div class="min-h-0 flex-1 overflow-y-auto bg-[#262626] p-4">
        <div v-if="sending" class="flex h-full items-center justify-center">
            <p class="text-sm text-neutral-400">Sending request…</p>
        </div>

        <div v-else-if="!response" class="flex h-full flex-col items-center justify-center text-center">
            <p class="text-lg font-medium text-neutral-300">No response yet</p>
            <p class="mt-1 max-w-sm text-sm text-neutral-500">
                Enter a URL above and press Send to see the response here.
            </p>
        </div>

        <div v-else>
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <span class="text-sm font-semibold" :class="statusClass">
                    {{ response.status }} {{ response.status_text || '' }}
                </span>
                <span class="text-sm text-neutral-400">Time: {{ formatTime(response.time_ms) }}</span>
                <span class="text-sm text-neutral-400">Size: {{ formatBytes(response.size) }}</span>
                <span v-if="response.content_type" class="truncate text-sm text-neutral-400">{{ response.content_type }}</span>
            </div>

            <div class="mb-3 flex gap-1 border-b border-neutral-700">
                <button
                    v-for="t in ['body', 'headers']"
                    :key="t"
                    type="button"
                    @click="activeTab = t"
                    :class="activeTab === t ? 'border-[#FF6C37] text-neutral-100' : 'border-transparent text-neutral-400 hover:text-neutral-200'"
                    class="border-b-2 px-3 py-2 text-xs font-medium capitalize"
                >
                    {{ t === 'body' ? 'Body' : 'Headers (' + headerRows.length + ')' }}
                </button>
            </div>

            <div v-show="activeTab === 'body'">
                <pre
                    v-if="isJson"
                    class="overflow-x-auto whitespace-pre-wrap break-words rounded border border-neutral-700 bg-[#1e1e1e] p-3 font-mono text-[13px] leading-relaxed text-neutral-200"
                    v-html="highlightedJson"
                ></pre>
                <pre
                    v-else
                    class="overflow-x-auto whitespace-pre-wrap break-words rounded border border-neutral-700 bg-[#1e1e1e] p-3 font-mono text-[13px] leading-relaxed text-neutral-200"
                >{{ rawBody }}</pre>
            </div>

            <div v-show="activeTab === 'headers'">
                <table v-if="headerRows.length > 0" class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-neutral-700 text-xs text-neutral-500">
                            <th class="py-2 pr-4 font-medium">Key</th>
                            <th class="py-2 font-medium">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(h, i) in headerRows" :key="i" class="border-b border-neutral-800 align-top">
                            <td class="py-2 pr-4 font-mono text-neutral-300">{{ h.key }}</td>
                            <td class="break-all py-2 font-mono text-neutral-400">{{ h.value }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-sm text-neutral-500">No headers returned.</p>
            </div>
        </div>
    </div>
</template>

<script>
import { prettyJson, formatBytes, formatTime } from '../utils/variables.js'

function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

export default {
    name: 'ResponseViewer',
    props: {
        response: { type: Object, default: null },
        sending: { type: Boolean, default: false },
    },
    data() {
        return { activeTab: 'body' }
    },
    computed: {
        statusClass() {
            const s = this.response && this.response.status
            if (!s) return 'text-neutral-300'
            if (s >= 200 && s < 300) return 'text-green-400'
            if (s >= 300 && s < 400) return 'text-yellow-400'
            return 'text-red-400'
        },
        isJson() {
            if (!this.response) return false
            if (this.response.is_json) return true
            const ct = (this.response.content_type || '').toLowerCase()
            return ct.includes('json')
        },
        rawBody() {
            if (!this.response) return ''
            const b = this.response.body
            if (b === null || b === undefined) return ''
            return typeof b === 'string' ? b : prettyJson(b)
        },
        prettyBody() {
            return prettyJson(this.rawBody)
        },
        highlightedJson() {
            // Small JSON syntax highlighter: escape first, then wrap tokens.
            const json = escapeHtml(this.prettyBody)
            return json.replace(
                /(&quot;|")([^"&]*?)(&quot;|")(\s*:)?|(-?\d+(\.\d+)?([eE][+-]?\d+)?)|\b(true|false)\b|\bnull\b/g,
                (match, q1, key, q2, colon, num, _dec, _exp, bool) => {
                    if (key !== undefined) {
                        return colon
                            ? '<span class="json-key">' + q1 + key + q2 + '</span>' + colon
                            : '<span class="json-string">' + match + '</span>'
                    }
                    if (num !== undefined) return '<span class="json-number">' + num + '</span>'
                    if (bool !== undefined) return '<span class="json-boolean">' + bool + '</span>'
                    if (match === 'null') return '<span class="json-null">null</span>'
                    return match
                },
            )
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
    },
    methods: {
        formatBytes,
        formatTime,
    },
}
</script>
