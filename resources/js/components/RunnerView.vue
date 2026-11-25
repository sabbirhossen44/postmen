<template>
    <div class="pm-panel min-h-0 flex-1 overflow-y-auto p-4">
        <div class="mb-4 flex items-center gap-3">
            <h2 class="text-base font-semibold pm-text">▶ Collection Runner</h2>
            <span class="flex-1"></span>
            <button
                type="button"
                @click="$emit('close')"
                class="rounded border pm-border px-3 py-1.5 text-sm pm-text-2 hover:pm-active"
            >
                ← Back to Builder
            </button>
        </div>

        <p v-if="unavailable" class="mb-4 rounded border border-amber-600/50 bg-amber-950/40 px-3 py-2 text-sm text-amber-300">
            The Collection Runner endpoint (POST /api/collections/{id}/run) is not available yet —
            the backend is still being built. You can configure the run below; it will start once the endpoint lands.
        </p>
        <p v-if="error" class="mb-4 rounded border border-red-600/50 bg-red-950/40 px-3 py-2 text-sm text-red-300">{{ error }}</p>

        <!-- Run configuration -->
        <div class="mb-4 grid max-w-3xl gap-3 md:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-xs font-medium pm-muted">Collection</span>
                <select
                    v-model="collectionId"
                    @change="onCollectionChange"
                    class="w-full rounded border pm-input px-2 py-2 text-sm outline-none"
                >
                    <option :value="null">Select a collection…</option>
                    <option v-for="c in store.collections" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium pm-muted">Folder (optional)</span>
                <select v-model="folderId" class="w-full rounded border pm-input px-2 py-2 text-sm outline-none">
                    <option :value="null">Whole collection</option>
                    <option v-for="f in folders" :key="f.id" :value="f.id">{{ f.name }}</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium pm-muted">Environment</span>
                <select v-model="environmentId" class="w-full rounded border pm-input px-2 py-2 text-sm outline-none">
                    <option :value="null">No Environment</option>
                    <option v-for="e in store.environments" :key="e.id" :value="e.id">{{ e.name }}</option>
                </select>
            </label>
            <div class="flex gap-3">
                <label class="block flex-1">
                    <span class="mb-1 block text-xs font-medium pm-muted">Iterations</span>
                    <input v-model.number="iterations" type="number" min="1" max="100" class="w-full rounded border pm-input px-2 py-2 text-sm outline-none">
                </label>
                <label class="block flex-1">
                    <span class="mb-1 block text-xs font-medium pm-muted">Delay (ms)</span>
                    <input v-model.number="delay" type="number" min="0" max="60000" class="w-full rounded border pm-input px-2 py-2 text-sm outline-none">
                </label>
            </div>
        </div>

        <div class="mb-4 max-w-3xl">
            <label class="mb-1 flex items-center justify-between text-xs font-medium pm-muted">
                <span>Data (JSON array — one object per iteration)</span>
                <button type="button" @click="loadResolvedVariables" class="rounded px-2 py-0.5 pm-muted hover:pm-text" title="Show the resolved variable map for this environment">
                    {{ resolved ? '▾ Hide resolved variables' : '▸ Show resolved variables' }}
                </button>
            </label>
            <textarea
                v-model="dataText"
                rows="4"
                spellcheck="false"
                placeholder='[{"id": 1}, {"id": 2}]'
                class="w-full rounded border pm-input px-3 py-2 font-mono text-sm outline-none focus:border-[#FF6C37]"
            ></textarea>
            <p v-if="dataError" class="mt-1 text-xs text-red-400">{{ dataError }}</p>
            <div v-if="resolved" class="mt-2 max-h-40 overflow-y-auto rounded border pm-border pm-code-bg p-3 font-mono text-xs pm-muted">
                <div v-if="resolvedNote" class="mb-1">{{ resolvedNote }}</div>
                <div v-for="(v, k) in resolvedVariables" :key="k" class="py-0.5">
                    <span class="pm-accent-text">{{ k }}</span> = {{ v }}
                </div>
                <p v-if="Object.keys(resolvedVariables).length === 0 && !resolvedNote" class="pm-faint">No variables resolved for this environment.</p>
            </div>
        </div>

        <div class="mb-4 flex items-center gap-3">
            <button
                type="button"
                @click="startRun"
                :disabled="running || !collectionId"
                class="pm-accent-bg rounded px-6 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
                {{ running ? 'Running…' : 'Run' }}
            </button>
            <button
                v-if="running"
                type="button"
                @click="stopPolling"
                class="rounded border pm-border px-4 py-2 text-sm pm-text-2 hover:pm-active"
            >
                Stop watching
            </button>
            <span v-if="running" class="text-sm pm-muted">Run in progress — results appear live below.</span>
        </div>

        <!-- Summary bar -->
        <div v-if="currentRun" class="mb-4 flex flex-wrap items-center gap-4 rounded border pm-border pm-surface px-4 py-3 text-sm">
            <span class="font-semibold pm-text">{{ currentRun.name || 'Run' }}</span>
            <span class="pm-muted">Status: <span class="pm-text-2">{{ currentRun.status || 'running' }}</span></span>
            <span class="pm-muted">Requests: <span class="pm-text-2">{{ summary.total }}</span></span>
            <span class="status-ok">Passed: {{ summary.passed }}</span>
            <span class="status-error">Failed: {{ summary.failed }}</span>
            <span class="pm-muted">Avg time: {{ summary.avgTime }}</span>
        </div>

        <!-- Live per-request results -->
        <div v-if="results.length > 0" class="mb-6">
            <h3 class="mb-2 text-sm font-medium pm-text-2">Results</h3>
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b pm-border text-xs pm-faint">
                        <th class="py-2 pr-4 font-medium">Method</th>
                        <th class="py-2 pr-4 font-medium">Request</th>
                        <th class="py-2 pr-4 font-medium">Status</th>
                        <th class="py-2 pr-4 font-medium">Time</th>
                        <th class="py-2 font-medium">Tests</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in results" :key="i" class="border-b pm-border align-top">
                        <td class="py-2 pr-4 font-mono text-xs font-bold" :class="methodClass(r.method)">{{ (r.method || 'GET').toUpperCase() }}</td>
                        <td class="py-2 pr-4 pm-text-2">{{ r.name || r.request_name || r.url || '—' }}</td>
                        <td class="py-2 pr-4 font-mono" :class="r.status >= 200 && r.status < 300 ? 'status-ok' : 'status-error'">
                            {{ r.status || '—' }}
                        </td>
                        <td class="py-2 pr-4 font-mono pm-muted">{{ formatTime(r.time_ms || r.timeMs) }}</td>
                        <td class="py-2">
                            <span v-if="r.test_results || r.tests" :class="testFailed(r) ? 'status-error' : 'status-ok'">
                                {{ testSummary(r) }}
                            </span>
                            <span v-else class="pm-faint">—</span>
                            <span v-if="r.error" class="block text-xs status-error">{{ r.error }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else-if="currentRun" class="mb-6 text-sm pm-faint">No request results yet — waiting for the run to report back.</p>

        <!-- Run history -->
        <div>
            <h3 class="mb-2 text-sm font-medium pm-text-2">Run History</h3>
            <p v-if="store.runs.length === 0" class="text-sm pm-faint">No runs yet in this session.</p>
            <div v-for="run in store.runs" :key="run.id" class="group mb-1 flex items-center gap-3 rounded px-2 py-2 pm-hover">
                <button type="button" @click="loadRun(run)" class="min-w-0 flex-1 text-left">
                    <span class="block truncate text-sm pm-text-2">{{ run.name || 'Run' }}</span>
                    <span class="block text-xs pm-faint">
                        {{ run.created_at || '' }} · {{ runStatusLabel(run) }}
                    </span>
                </button>
                <span class="shrink-0 text-xs status-ok">{{ runPassed(run) }} passed</span>
                <span class="shrink-0 text-xs status-error">{{ runFailed(run) }} failed</span>
            </div>
        </div>
    </div>
</template>

<script>
import { api, errMessage } from '../api.js'
import { store, addRun, loadCollectionDetail } from '../store.js'
import { methodClass } from '../utils/methods.js'
import { formatTime } from '../utils/variables.js'

export default {
    name: 'RunnerView',
    emits: ['close'],
    data() {
        return {
            store,
            collectionId: null,
            folderId: null,
            environmentId: store.activeEnvironmentId,
            iterations: 1,
            delay: 0,
            dataText: '',
            dataError: '',
            running: false,
            currentRun: null,
            results: [],
            error: '',
            unavailable: false,
            resolved: false,
            resolvedVariables: {},
            resolvedNote: '',
            pollTimer: null,
        }
    },
    computed: {
        folders() {
            const c = store.collections.find((col) => col.id === this.collectionId)
            return (c && c.folders) || []
        },
        summary() {
            const total = this.results.length
            let passed = 0
            let failed = 0
            let timeSum = 0
            this.results.forEach((r) => {
                const tests = r.test_results || r.tests || []
                const fails = tests.filter((t) => !t.passed)
                if (r.error || (r.status && (r.status < 200 || r.status >= 300))) failed++
                else if (fails.length > 0) failed++
                else passed++
                timeSum += r.time_ms || r.timeMs || 0
            })
            return {
                total,
                passed,
                failed,
                avgTime: total > 0 ? formatTime(Math.round(timeSum / total)) : '—',
            }
        },
    },
    beforeUnmount() {
        this.stopPolling()
    },
    methods: {
        methodClass,
        formatTime,
        onCollectionChange() {
            this.folderId = null
            const c = store.collections.find((col) => col.id === this.collectionId)
            if (c && !c._treeLoaded) {
                loadCollectionDetail(c.id).then(() => { c._treeLoaded = true }).catch(() => {})
            }
        },
        testFailed(r) {
            const tests = r.test_results || r.tests || []
            return tests.some((t) => !t.passed)
        },
        testSummary(r) {
            const tests = r.test_results || r.tests || []
            const passed = tests.filter((t) => t.passed).length
            return passed + '/' + tests.length + ' tests passed'
        },
        runPassed(run) {
            return (run.results || []).filter((r) => {
                const tests = r.test_results || r.tests || []
                return !r.error && (!r.status || (r.status >= 200 && r.status < 300)) && !tests.some((t) => !t.passed)
            }).length
        },
        runFailed(run) {
            return (run.results || []).length - this.runPassed(run)
        },
        runStatusLabel(run) {
            const total = (run.results || []).length
            return (run.status || 'completed') + ' · ' + total + ' request' + (total === 1 ? '' : 's')
        },
        parseData() {
            this.dataError = ''
            const text = (this.dataText || '').trim()
            if (!text) return null
            try {
                const parsed = JSON.parse(text)
                if (!Array.isArray(parsed)) {
                    this.dataError = 'Data must be a JSON array (one object per iteration).'
                    return undefined
                }
                return parsed
            } catch (e) {
                this.dataError = 'Invalid JSON: ' + e.message
                return undefined
            }
        },
        async loadResolvedVariables() {
            if (this.resolved) {
                this.resolved = false
                return
            }
            this.resolved = true
            this.resolvedVariables = {}
            this.resolvedNote = ''
            if (!this.environmentId) {
                this.resolvedNote = 'No environment selected — only globals would apply.'
                return
            }
            try {
                const res = await api.resolveEnvironment(this.environmentId)
                this.resolvedVariables = (res && (res.variables || res.map || res)) || {}
            } catch (e) {
                if (api.isMissingEndpoint(e)) {
                    this.resolvedNote = 'Variable resolution endpoint (GET /api/environments/{id}/resolve) is not available yet.'
                } else {
                    this.resolvedNote = 'Could not resolve variables: ' + errMessage(e)
                }
            }
        },
        async startRun() {
            const data = this.parseData()
            if (data === undefined) return
            this.error = ''
            this.unavailable = false
            this.running = true
            this.currentRun = null
            this.results = []
            try {
                const run = await api.runCollection(this.collectionId, {
                    folder_id: this.folderId,
                    environment_id: this.environmentId,
                    iterations: this.iterations,
                    delay: this.delay,
                    data,
                })
                this.applyRun(run)
                addRun(run)
                if (run && (run.status === 'running' || run.status === 'pending') && run.id) {
                    this.pollRun(run.id)
                } else {
                    this.running = false
                }
            } catch (e) {
                this.running = false
                if (api.isMissingEndpoint(e)) {
                    this.unavailable = true
                } else {
                    this.error = 'Run failed: ' + errMessage(e)
                }
            }
        },
        applyRun(run) {
            if (!run) return
            this.currentRun = {
                id: run.id,
                name: run.name || run.collection_name || 'Run',
                status: run.status || 'completed',
                created_at: run.created_at || new Date().toISOString().slice(0, 19).replace('T', ' '),
                results: run.results || run.items || [],
            }
            this.results = this.currentRun.results
        },
        pollRun(id) {
            this.stopPolling()
            this.pollTimer = setInterval(async () => {
                try {
                    const run = await api.getRun(id)
                    this.applyRun(run)
                    addRun(run)
                    if (run.status !== 'running' && run.status !== 'pending') {
                        this.running = false
                        this.stopPolling()
                    }
                } catch (e) {
                    // A missing run endpoint ends polling quietly.
                    this.running = false
                    this.stopPolling()
                    if (!api.isMissingEndpoint(e)) this.error = 'Could not poll run: ' + errMessage(e)
                }
            }, 2000)
        },
        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer)
                this.pollTimer = null
            }
            this.running = false
        },
        async loadRun(run) {
            this.error = ''
            if (run.id) {
                try {
                    const fresh = await api.getRun(run.id)
                    this.applyRun(fresh)
                    return
                } catch (e) {
                    // Fall back to the stored snapshot below.
                    if (!api.isMissingEndpoint(e)) this.error = 'Could not fetch run: ' + errMessage(e)
                }
            }
            this.applyRun(run)
        },
    },
}
</script>
