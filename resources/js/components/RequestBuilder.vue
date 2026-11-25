<template>
    <div class="border-b border-neutral-800 bg-[#262626] p-4">
        <div class="mb-3 flex items-center gap-2">
            <input
                v-model="request.name"
                type="text"
                placeholder="Untitled Request"
                class="w-56 rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-2 text-sm font-medium text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
            >
            <select
                v-model="request.collection_id"
                class="max-w-52 rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-2 text-sm text-neutral-200 outline-none"
                title="Collection to save into"
            >
                <option :value="null">No collection</option>
                <option v-for="c in store.collections" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <span v-if="request.id" class="rounded bg-neutral-700 px-2 py-1 text-xs text-neutral-300">Saved</span>
            <span v-else class="rounded bg-neutral-700 px-2 py-1 text-xs text-neutral-300">Unsaved</span>
        </div>

        <div class="flex gap-2">
            <select
                v-model="request.method"
                class="rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-2 text-sm font-bold outline-none"
                :class="methodTextClass"
            >
                <option v-for="m in methods" :key="m" :value="m">{{ m }}</option>
            </select>
            <input
                v-model="request.url"
                type="text"
                placeholder="https://api.example.com/users?page=1 — use {{variable}} for environment values"
                class="min-w-0 flex-1 rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 font-mono text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                @keyup.enter="$emit('send')"
            >
            <button
                type="button"
                @click="$emit('send')"
                :disabled="sending || !request.url.trim()"
                class="rounded bg-[#FF6C37] px-6 py-2 text-sm font-semibold text-white hover:bg-[#e85a2a] disabled:opacity-50"
            >
                {{ sending ? 'Sending…' : 'Send' }}
            </button>
            <button
                type="button"
                @click="$emit('save')"
                class="rounded border border-neutral-600 px-4 py-2 text-sm text-neutral-200 hover:bg-neutral-700"
            >
                Save
            </button>
        </div>

        <p v-if="resolvedUrl !== request.url" class="mt-2 truncate font-mono text-xs text-neutral-500" title="Resolved URL preview">
            → {{ resolvedUrl }}
        </p>
        <p v-if="saveMessage" class="mt-2 text-xs" :class="saveMessageIsError ? 'text-red-400' : 'text-green-400'">{{ saveMessage }}</p>

        <div class="mt-4 flex gap-1 border-b border-neutral-700">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                @click="activeTab = t.key"
                :class="activeTab === t.key ? 'border-[#FF6C37] text-neutral-100' : 'border-transparent text-neutral-400 hover:text-neutral-200'"
                class="border-b-2 px-3 py-2 text-xs font-medium"
            >
                {{ t.label }}
                <span v-if="t.count !== undefined && t.count > 0" class="ml-1 rounded bg-neutral-700 px-1.5 py-0.5 text-[10px]">{{ t.count }}</span>
            </button>
        </div>

        <div class="pt-3">
            <!-- Params -->
            <div v-show="activeTab === 'params'">
                <KeyValueEditor :rows="request.params" key-placeholder="Key" value-placeholder="Value" show-description />
                <p class="mt-2 text-xs text-neutral-500">Enabled params are appended to the URL as a query string when sending.</p>
            </div>

            <!-- Authorization -->
            <div v-show="activeTab === 'auth'">
                <label class="mb-1 block text-xs font-medium text-neutral-400">Type</label>
                <select
                    v-model="request.auth_type"
                    class="mb-3 rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-2 text-sm text-neutral-200 outline-none"
                >
                    <option value="none">No Auth</option>
                    <option value="bearer">Bearer Token</option>
                    <option value="basic">Basic Auth</option>
                    <option value="api_key">API Key</option>
                </select>
                <div v-if="request.auth_type === 'bearer'">
                    <label class="mb-1 block text-xs font-medium text-neutral-400">Token</label>
                    <input
                        v-model="request.auth.token"
                        type="text"
                        placeholder="Token ({{token}} works too)"
                        class="w-full max-w-md rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    >
                </div>
                <div v-if="request.auth_type === 'basic'" class="flex max-w-md gap-2">
                    <input
                        v-model="request.auth.username"
                        type="text"
                        placeholder="Username"
                        class="min-w-0 flex-1 rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    >
                    <input
                        v-model="request.auth.password"
                        type="password"
                        placeholder="Password"
                        class="min-w-0 flex-1 rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    >
                </div>
                <div v-if="request.auth_type === 'api_key'" class="flex max-w-xl flex-wrap gap-2">
                    <input
                        v-model="request.auth.key"
                        type="text"
                        placeholder="Key"
                        class="min-w-0 flex-1 rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    >
                    <input
                        v-model="request.auth.value"
                        type="text"
                        placeholder="Value"
                        class="min-w-0 flex-1 rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    >
                    <select
                        v-model="request.auth.add_to"
                        class="rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-2 text-sm text-neutral-200 outline-none"
                    >
                        <option value="header">Add to Header</option>
                        <option value="query">Add to Query Params</option>
                    </select>
                </div>
            </div>

            <!-- Headers -->
            <div v-show="activeTab === 'headers'">
                <KeyValueEditor :rows="request.headers" key-placeholder="Key" value-placeholder="Value" show-description />
            </div>

            <!-- Body -->
            <div v-show="activeTab === 'body'">
                <div class="mb-3 flex flex-wrap gap-4 text-sm text-neutral-300">
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="none" v-model="request.body_type" class="accent-[#FF6C37]"> none
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="form-data" v-model="request.body_type" class="accent-[#FF6C37]"> form-data
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="urlencoded" v-model="request.body_type" class="accent-[#FF6C37]"> x-www-form-urlencoded
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="raw" v-model="request.body_type" class="accent-[#FF6C37]"> raw
                    </label>
                </div>
                <div v-if="request.body_type === 'form-data'">
                    <KeyValueEditor :rows="request.body.formdata" key-placeholder="Key" value-placeholder="Value" allow-file />
                    <p class="mt-2 text-xs text-neutral-500">File rows are uploaded first and sent as their stored path.</p>
                </div>
                <div v-if="request.body_type === 'urlencoded'">
                    <KeyValueEditor :rows="request.body.urlencoded" key-placeholder="Key" value-placeholder="Value" />
                </div>
                <div v-if="request.body_type === 'raw'">
                    <textarea
                        v-model="request.body.raw"
                        rows="6"
                        spellcheck="false"
                        placeholder='{"key": "value"}'
                        class="w-full rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 font-mono text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    ></textarea>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { store, activeEnvironmentVariables } from '../store.js'
import { resolveVariables } from '../utils/variables.js'
import KeyValueEditor from './KeyValueEditor.vue'

const METHOD_TEXT = {
    GET: 'text-green-400',
    POST: 'text-orange-400',
    PUT: 'text-blue-400',
    PATCH: 'text-purple-400',
    DELETE: 'text-red-400',
}

export default {
    name: 'RequestBuilder',
    components: { KeyValueEditor },
    props: {
        request: { type: Object, required: true },
        sending: { type: Boolean, default: false },
        saveMessage: { type: String, default: '' },
        saveMessageIsError: { type: Boolean, default: false },
    },
    emits: ['send', 'save'],
    data() {
        return {
            store,
            methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
            activeTab: 'params',
        }
    },
    computed: {
        tabs() {
            return [
                { key: 'params', label: 'Params', count: this.request.params.filter((r) => r.key).length },
                { key: 'auth', label: 'Authorization' },
                { key: 'headers', label: 'Headers', count: this.request.headers.filter((r) => r.key).length },
                { key: 'body', label: 'Body' },
            ]
        },
        methodTextClass() {
            return METHOD_TEXT[(this.request.method || 'GET').toUpperCase()] || 'text-neutral-200'
        },
        resolvedUrl() {
            return resolveVariables(this.request.url, activeEnvironmentVariables())
        },
    },
}
</script>
