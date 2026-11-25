<template>
    <div>
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <select
                v-model="language"
                @change="generate"
                class="rounded border pm-input px-2 py-1.5 text-sm outline-none"
            >
                <option v-for="l in languages" :key="l.value" :value="l.value">{{ l.label }}</option>
            </select>
            <button
                type="button"
                @click="generate"
                :disabled="loading"
                class="rounded border pm-border px-3 py-1.5 text-xs pm-text-2 hover:pm-active disabled:opacity-50"
            >
                {{ loading ? 'Generating…' : 'Regenerate' }}
            </button>
            <button
                type="button"
                @click="copyCode"
                class="rounded border pm-border px-3 py-1.5 text-xs pm-text-2 hover:pm-active"
            >
                {{ copied ? 'Copied!' : 'Copy' }}
            </button>
            <span v-if="fallbackNote" class="text-xs pm-faint">{{ fallbackNote }}</span>
        </div>
        <pre
            class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded border pm-border pm-code-bg p-3 font-mono text-[13px] leading-relaxed pm-text-2"
        >{{ code || 'No code generated yet.' }}</pre>
        <p v-if="error" class="mt-2 text-xs text-red-400">{{ error }}</p>
    </div>
</template>

<script>
import { api, errMessage } from '../api.js'
import { CODE_LANGUAGES, requestToCodePayload, generateCodeLocal } from '../utils/codegen.js'

export default {
    name: 'CodeSnippet',
    props: {
        request: { type: Object, required: true },
    },
    data() {
        return {
            languages: CODE_LANGUAGES,
            language: 'curl',
            code: '',
            loading: false,
            copied: false,
            fallbackNote: '',
            error: '',
        }
    },
    watch: {
        // Regenerate when the request changes (debounced by the tab switch).
        request: {
            deep: true,
            handler() {
                this.generate()
            },
        },
    },
    mounted() {
        this.generate()
    },
    methods: {
        async generate() {
            this.loading = true
            this.error = ''
            this.fallbackNote = ''
            const payload = requestToCodePayload(this.request)
            try {
                // Prefer the backend generator when it exists.
                const res = await api.generateCode(Object.assign({}, payload, { language: this.language }))
                const code = typeof res === 'string' ? res : (res && (res.code || res.snippet || ''))
                if (code) {
                    this.code = code
                    return
                }
                throw new Error('Empty code response')
            } catch (e) {
                if (!api.isMissingEndpoint(e) && errMessage(e) !== 'Empty code response') {
                    // A real backend error: still fall back locally so the
                    // tab is useful, but surface the error.
                    this.error = 'Code endpoint error: ' + errMessage(e) + ' — showing a local snippet instead.'
                }
                this.code = generateCodeLocal(payload, this.language)
                this.fallbackNote = 'Generated locally — the /api/code/generate endpoint is not available yet.'
            } finally {
                this.loading = false
            }
        },
        copyCode() {
            if (!this.code) return
            if (navigator.clipboard) {
                navigator.clipboard.writeText(this.code).then(() => {
                    this.copied = true
                    setTimeout(() => { this.copied = false }, 1500)
                }).catch(() => {})
            }
        },
    },
}
</script>
