<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-60 p-4" @click.self="$emit('close')">
        <div class="doc-print pm-panel flex max-h-full w-full max-w-3xl flex-col overflow-hidden rounded border pm-border shadow-xl">
            <div class="no-print flex items-center gap-2 border-b pm-border px-4 py-3">
                <h2 class="flex-1 text-base font-semibold pm-text">Documentation{{ doc ? ' — ' + doc.name : '' }}</h2>
                <button type="button" @click="printDoc" class="rounded border pm-border px-3 py-1.5 text-xs pm-text-2 hover:pm-active">Print</button>
                <button type="button" @click="$emit('close')" class="rounded px-2 py-1.5 text-sm pm-muted hover:pm-text">✕</button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <p v-if="loading" class="text-sm pm-muted">Loading documentation…</p>
                <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
                <div v-else-if="doc">
                    <h1 class="mb-1 text-xl font-bold pm-text">{{ doc.name }}</h1>
                    <div v-if="doc.description" class="doc-markdown mb-4 text-sm pm-text-2" v-html="renderedDocDescription"></div>
                    <p v-if="flatRequests.length === 0" class="text-sm pm-faint">No requests in this collection yet.</p>
                    <section v-for="req in flatRequests" :key="req.id" class="mb-6 border-b pm-border pb-4">
                        <h2 class="mb-1 text-base font-semibold pm-text">
                            <span class="mr-2 font-mono text-sm font-bold" :class="methodClass(req.method)">{{ (req.method || 'GET').toUpperCase() }}</span>
                            {{ req.name || req.url }}
                        </h2>
                        <p class="mb-2 break-all font-mono text-xs pm-muted">{{ req.url }}</p>
                        <div v-if="req.description" class="doc-markdown mb-2 text-sm pm-text-2" v-html="renderMarkdown(req.description)"></div>
                        <div v-if="enabledRows(req.params).length > 0" class="mb-2">
                            <h3 class="mb-1 text-xs font-medium pm-muted">Params</h3>
                            <table class="w-full text-left text-xs">
                                <tbody>
                                    <tr v-for="(row, i) in enabledRows(req.params)" :key="i" class="border-b pm-border">
                                        <td class="py-1 pr-3 font-mono pm-text-2">{{ row.key }}</td>
                                        <td class="break-all py-1 font-mono pm-muted">{{ row.value }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="enabledRows(req.headers).length > 0" class="mb-2">
                            <h3 class="mb-1 text-xs font-medium pm-muted">Headers</h3>
                            <table class="w-full text-left text-xs">
                                <tbody>
                                    <tr v-for="(row, i) in enabledRows(req.headers)" :key="i" class="border-b pm-border">
                                        <td class="py-1 pr-3 font-mono pm-text-2">{{ row.key }}</td>
                                        <td class="break-all py-1 font-mono pm-muted">{{ row.value }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="exampleBody(req)" class="mb-2">
                            <h3 class="mb-1 text-xs font-medium pm-muted">Example body ({{ req.body_type || 'none' }})</h3>
                            <pre class="overflow-x-auto whitespace-pre-wrap break-words rounded border pm-border pm-code-bg p-3 font-mono text-xs leading-relaxed pm-text-2">{{ exampleBody(req) }}</pre>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { api, errMessage } from '../api.js'
import { renderMarkdown } from '../utils/variables.js'
import { methodClass } from '../utils/methods.js'

export default {
    name: 'DocumentationModal',
    props: {
        open: { type: Boolean, default: false },
        collectionId: { type: [Number, String], default: null },
    },
    emits: ['close'],
    data() {
        return { doc: null, loading: false, error: '' }
    },
    computed: {
        renderedDocDescription() {
            return renderMarkdown((this.doc && this.doc.description) || '')
        },
        flatRequests() {
            if (!this.doc || !this.doc.tree) return []
            const out = []
            const walk = (nodes) => {
                ;(nodes || []).forEach((node) => {
                    ;(node.requests || []).forEach((r) => out.push(r))
                    walk(node.children || [])
                })
            }
            walk(this.doc.tree.folders || [])
            ;(this.doc.tree.requests || []).forEach((r) => out.push(r))
            return out
        },
    },
    watch: {
        open(value) {
            if (value && this.collectionId) this.load()
        },
    },
    methods: {
        methodClass,
        renderMarkdown,
        enabledRows(rows) {
            return (rows || []).filter((r) => r && r.key && r.enabled !== false)
        },
        exampleBody(req) {
            const body = req.body || {}
            if (req.body_type === 'raw') return body.raw || ''
            if (req.body_type === 'graphql') return body.graphql ? body.graphql.query || '' : ''
            if (req.body_type === 'form-data' && body.formdata) {
                return body.formdata.filter((r) => r.key).map((r) => r.key + ': ' + (r.value || '')).join('\n')
            }
            if (req.body_type === 'urlencoded' && body.urlencoded) {
                return body.urlencoded.filter((r) => r.key).map((r) => r.key + '=' + encodeURIComponent(r.value || '')).join('&')
            }
            return ''
        },
        async load() {
            this.loading = true
            this.error = ''
            this.doc = null
            try {
                this.doc = await api.getCollectionDocumentation(this.collectionId)
            } catch (e) {
                this.error = errMessage(e)
            } finally {
                this.loading = false
            }
        },
        printDoc() {
            window.print()
        },
    },
}
</script>
