<template>
    <div class="pm-panel border-b pm-border p-4">
        <div class="sticky top-0 z-30 -mx-4 -mt-4 border-b pm-border pm-panel px-4 pb-3 pt-4">
        <div class="mb-3 flex items-center gap-2">
            <input
                v-model="request.name"
                type="text"
                placeholder="Untitled Request"
                class="pm-field w-56 font-medium"
                :title="request.name || 'Untitled Request'"
            >
            <select
                v-model="request.collection_id"
                class="pm-field max-w-52"
                title="Collection to save into"
                aria-label="Collection to save into"
            >
                <option :value="null">No collection</option>
                <option v-for="c in store.collections" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <span v-if="request.id" class="rounded pm-active px-2 py-1 text-xs pm-text-2">Saved</span>
            <span v-else class="rounded pm-active px-2 py-1 text-xs pm-text-2">Unsaved</span>
            <span v-if="isDirty" class="text-xs pm-accent-text" title="Unsaved changes">● unsaved changes</span>
        </div>

        <div class="relative flex gap-2">
            <select
                v-model="request.method"
                class="pm-field pm-field-mono font-bold"
                :class="methodClass(request.method)"
            >
                <option v-for="m in methods" :key="m" :value="m">{{ m }}</option>
            </select>
            <input
                ref="urlInput"
                v-model="request.url"
                type="text"
                placeholder="https://postman-echo.com/get"
                class="pm-field pm-field-mono min-w-0 flex-1"
                @keyup.enter="$emit('send')"
                @input="onUrlInput"
                @keydown.down.prevent="urlAutocompleteIndex = Math.min(urlAutocompleteIndex + 1, autocomplete.length - 1)"
                @keydown.up.prevent="urlAutocompleteIndex = Math.max(urlAutocompleteIndex - 1, 0)"
                @keydown.tab.prevent="pickUrlSuggestion"
            >
            <button
                type="button"
                @click="$emit('send')"
                :disabled="sending"
                class="pm-btn pm-btn-primary pm-btn-lg"
                title="Send request (Ctrl/Cmd+Enter)"
            >
                <span v-if="sending" class="pm-spinner" aria-hidden="true"></span>
                {{ sending ? 'Sending…' : 'Send' }}
            </button>
            <button
                type="button"
                @click="$emit('save')"
                :disabled="saving || !canEdit"
                class="pm-btn pm-btn-secondary pm-btn-lg"
                :title="canEdit ? 'Save request (Ctrl/Cmd+S)' : 'Viewers cannot edit this workspace'"
            >
                <span v-if="saving" class="pm-spinner pm-spinner-dark" aria-hidden="true"></span>
                {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <!-- {{variable}} autocomplete (shared util, same as the editors) -->
            <div
                v-if="autocomplete.length > 0"
                class="pm-panel absolute left-24 top-full z-40 mt-1 max-h-48 w-72 overflow-y-auto rounded border pm-border shadow-xl"
            >
                <button
                    v-for="(v, vi) in autocomplete"
                    :key="v.key"
                    type="button"
                    @click="insertVariable(v.key)"
                    :class="vi === urlAutocompleteIndex ? 'pm-active' : ''"
                    class="block w-full px-3 py-1.5 text-left font-mono text-xs pm-text-2 pm-hover"
                >
                    <span class="font-semibold pm-accent-text">{{ varLabel(v.key) }}</span>
                    <span class="pm-faint"> = {{ v.value }}</span>
                </button>
            </div>
        </div>
        <p v-if="!request.url.trim()" class="mt-2 text-xs text-amber-400">Enter a URL first, then press Send.</p>
        </div>

        <!-- {{var}} highlighting: orange = resolved, red = unresolved -->
        <p v-if="request.url" class="mt-2 truncate font-mono text-xs pm-muted" title="Resolved URL preview">
            <template v-for="(seg, i) in varSegments" :key="i">
                <span v-if="!seg.variable">{{ seg.text }}</span>
                <span v-else :class="seg.resolved ? 'var-resolved' : 'var-unresolved'" :title="seg.resolved ? seg.variable + ' = ' + seg.value : seg.variable + ' is not set in the active environment or this collection'">
                    {{ seg.text }}
                </span>
            </template>
        </p>
        <p v-if="saveMessage" class="mt-2 text-xs" :class="saveMessageIsError ? 'text-red-400' : 'text-green-400'">{{ saveMessage }}</p>

        <div class="mt-4 flex gap-1 border-b pm-border">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                @click="activeTab = t.key"
                :class="activeTab === t.key ? 'pm-tab-active' : ''"
                class="pm-tab"
            >
                {{ t.label }}
                <span v-if="t.count !== undefined && t.count > 0" class="ml-1 rounded pm-active px-1.5 py-0.5 text-[10px]">{{ t.count }}</span>
            </button>
        </div>

        <div class="pt-3">
            <!-- Params -->
            <div v-show="activeTab === 'params'">
                <div v-if="pathRows.length > 0" class="mb-3">
                    <p class="mb-1 text-xs font-medium pm-muted">Path Variables <span class="pm-faint">(from :name in the URL — substituted at send, not sent as query)</span></p>
                    <div v-for="row in pathRows" :key="row.key" class="flex items-center gap-2 py-1">
                        <span class="w-1/3 truncate font-mono text-sm pm-text-2">:{{ row.key }}</span>
                        <input
                            v-model="row.value"
                            type="text"
                            placeholder="Value"
                            class="pm-field flex-1"
                        >
                    </div>
                </div>
                <KeyValueEditor :rows="request.params" key-placeholder="Key" value-placeholder="Value" show-description :variables="previewVariables" />
                <p class="mt-2 text-xs pm-faint">Enabled params are appended to the URL as a query string when sending. Use Bulk Edit for pasting many at once.</p>
            </div>

            <!-- Authorization -->
            <div v-show="activeTab === 'auth'">
                <label class="mb-1 block text-xs font-medium pm-muted">Type</label>
                <select
                    v-model="request.auth_type"
                    class="pm-field mb-3"
                >
                    <option value="none">No Auth</option>
                    <option value="inherit">Inherit auth from collection</option>
                    <option value="bearer">Bearer Token</option>
                    <option value="basic">Basic Auth</option>
                    <option value="digest">Digest Auth</option>
                    <option value="api_key">API Key</option>
                    <option value="oauth2">OAuth 2.0</option>
                    <option value="aws">AWS Signature</option>
                </select>
                <p v-if="request.auth_type === 'inherit'" class="mb-3 text-xs pm-faint">Uses the auth of the collection this request is saved in. No credentials on this request.</p>
                <p v-if="authNote" class="mb-3 text-xs text-amber-400">{{ authNote }}</p>
                <div v-if="request.auth_type === 'bearer'">
                    <label class="mb-1 block text-xs font-medium pm-muted">Token</label>
                    <input
                        v-model="request.auth.token"
                        type="text"
                        placeholder="Token ({{token}} works too)"
                        class="pm-field w-full max-w-md"
                    >
                </div>
                <div v-if="request.auth_type === 'basic'" class="flex max-w-md gap-2">
                    <input
                        v-model="request.auth.username"
                        type="text"
                        placeholder="Username"
                        class="pm-field min-w-0 flex-1"
                    >
                    <input
                        v-model="request.auth.password"
                        type="password"
                        placeholder="Password"
                        class="pm-field min-w-0 flex-1"
                    >
                </div>
                <div v-if="request.auth_type === 'digest'" class="flex max-w-md gap-2">
                    <input
                        v-model="request.auth.username"
                        type="text"
                        placeholder="Username"
                        class="pm-field min-w-0 flex-1"
                    >
                    <input
                        v-model="request.auth.password"
                        type="password"
                        placeholder="Password"
                        class="pm-field min-w-0 flex-1"
                    >
                </div>
                <div v-if="request.auth_type === 'oauth2'" class="max-w-xl">
                    <div class="mb-2 flex flex-wrap gap-2">
                        <label class="flex-1">
                            <span class="mb-1 block text-xs font-medium pm-muted">Grant Type</span>
                            <select v-model="request.auth.grant_type" class="pm-field w-full">
                                <option value="client_credentials">Client Credentials</option>
                                <option value="password">Password</option>
                                <option value="authorization_code">Authorization Code</option>
                                <option value="refresh_token">Refresh Token</option>
                            </select>
                        </label>
                        <label class="flex-1">
                            <span class="mb-1 block text-xs font-medium pm-muted">Access Token URL</span>
                            <input v-model="request.auth.token_url" type="text" placeholder="https://example.com/oauth/token" class="pm-field w-full">
                        </label>
                    </div>
                    <div class="mb-2 flex flex-wrap gap-2">
                        <input v-model="request.auth.client_id" type="text" placeholder="Client ID" class="pm-field min-w-0 flex-1">
                        <input v-model="request.auth.client_secret" type="password" placeholder="Client Secret" class="pm-field min-w-0 flex-1">
                    </div>
                    <div class="mb-2 flex flex-wrap gap-2">
                        <input v-model="request.auth.scope" type="text" placeholder="Scope (space separated)" class="pm-field min-w-0 flex-1">
                        <input
                            v-if="request.auth.grant_type === 'password'"
                            v-model="request.auth.username"
                            type="text"
                            placeholder="Username"
                            class="pm-field min-w-0 flex-1"
                        >
                        <input
                            v-if="request.auth.grant_type === 'password'"
                            v-model="request.auth.password"
                            type="password"
                            placeholder="Password"
                            class="pm-field min-w-0 flex-1"
                        >
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            @click="fetchOAuthToken"
                            :disabled="oauthLoading || !request.auth.token_url"
                            class="pm-btn pm-btn-ghost pm-btn-sm"
                        >
                            {{ oauthLoading ? 'Requesting…' : 'Get New Access Token' }}
                        </button>
                        <span v-if="request.auth.access_token" class="truncate font-mono text-xs text-green-400" title="Access token (stored on the request)">
                            Token: {{ request.auth.access_token.slice(0, 24) }}…
                        </span>
                        <span v-if="oauthMessage" class="text-xs pm-muted">{{ oauthMessage }}</span>
                    </div>
                    <p v-if="!request.id" class="mt-2 text-xs pm-faint">Save the request first — the token endpoint needs a saved request id.</p>
                </div>
                <div v-if="request.auth_type === 'aws'" class="max-w-xl">
                    <div class="mb-2 flex flex-wrap gap-2">
                        <input v-model="request.auth.access_key" type="text" placeholder="Access Key" class="pm-field min-w-0 flex-1">
                        <input v-model="request.auth.secret_key" type="password" placeholder="Secret Key" class="pm-field min-w-0 flex-1">
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <input v-model="request.auth.region" type="text" placeholder="AWS Region (e.g. eu-west-1)" class="pm-field min-w-0 flex-1">
                        <input v-model="request.auth.service" type="text" placeholder="Service (e.g. s3)" class="pm-field min-w-0 flex-1">
                    </div>
                </div>
                <div v-if="request.auth_type === 'api_key'" class="flex max-w-xl flex-wrap gap-2">
                    <input
                        v-model="request.auth.key"
                        type="text"
                        placeholder="Key"
                        class="pm-field min-w-0 flex-1"
                    >
                    <input
                        v-model="request.auth.value"
                        type="text"
                        placeholder="Value"
                        class="pm-field min-w-0 flex-1"
                    >
                    <select
                        v-model="request.auth.in"
                        class="pm-field"
                    >
                        <option value="header">Add to Header</option>
                        <option value="query">Add to Query Params</option>
                    </select>
                </div>
            </div>

            <!-- Headers -->
            <div v-show="activeTab === 'headers'">
                <KeyValueEditor :rows="request.headers" key-placeholder="Key" value-placeholder="Value" show-description :variables="previewVariables" />
                <button type="button" @click="showAutoHeaders = !showAutoHeaders" class="mt-3 text-xs pm-muted hover:pm-text">
                    {{ showAutoHeaders ? '▾ Hide auto headers' : '▸ Show auto headers' }} (added by the client at send time)
                </button>
                <div v-if="showAutoHeaders" class="mt-2 rounded border pm-border pm-surface px-3 py-2 font-mono text-xs pm-muted">
                    <div v-for="h in autoHeaders" :key="h.key" class="py-0.5"><span class="pm-text-2">{{ h.key }}:</span> {{ h.value }}</div>
                </div>
            </div>

            <!-- Body -->
            <div v-show="activeTab === 'body'">
                <div class="mb-3 flex flex-wrap gap-4 text-sm pm-text-2">
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="none" v-model="request.body_type"> none
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="form-data" v-model="request.body_type"> form-data
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="urlencoded" v-model="request.body_type"> x-www-form-urlencoded
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="raw" v-model="request.body_type"> raw
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="binary" v-model="request.body_type"> binary
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input type="radio" value="graphql" v-model="request.body_type"> GraphQL
                    </label>
                </div>
                <div v-if="request.body_type === 'form-data'">
                    <KeyValueEditor :rows="request.body.formdata" key-placeholder="Key" value-placeholder="Value" allow-file :variables="previewVariables" />
                    <p class="mt-2 text-xs pm-faint">Files are uploaded when you send.</p>
                </div>
                <div v-if="request.body_type === 'urlencoded'">
                    <KeyValueEditor :rows="request.body.urlencoded" key-placeholder="Key" value-placeholder="Value" :variables="previewVariables" />
                </div>
                <div v-if="request.body_type === 'raw'">
                    <div class="mb-2 flex items-center gap-2">
                        <select v-model="request.body.raw_language" class="pm-field pm-field-xs">
                            <option value="json">JSON</option>
                            <option value="text">Text</option>
                            <option value="javascript">JavaScript</option>
                            <option value="html">HTML</option>
                            <option value="xml">XML</option>
                        </select>
                        <button type="button" @click="beautifyRaw" class="pm-btn pm-btn-ghost pm-btn-sm">Beautify</button>
                        <span class="text-xs pm-faint">Content-Type is set automatically for JSON. Type &#123;&#123; to insert a variable.</span>
                    </div>
                    <div class="relative">
                        <textarea
                            v-model="request.body.raw"
                            rows="6"
                            spellcheck="false"
                            placeholder='{"key": "value"}'
                            class="w-full rounded border pm-input px-3 py-2 font-mono text-sm outline-none focus:border-[#FF6C37]"
                            @input="onRawInput($event)"
                            @blur="closeRawAutocompleteSoon"
                            @keydown.down.prevent="rawAutocompleteIndex = Math.min(rawAutocompleteIndex + 1, rawSuggestions.length - 1)"
                            @keydown.up.prevent="rawAutocompleteIndex = Math.max(rawAutocompleteIndex - 1, 0)"
                            @keydown.tab.prevent="pickRawSuggestion"
                            @keydown.esc="rawSuggestions = []"
                        ></textarea>
                        <div
                            v-if="rawSuggestions.length > 0"
                            class="pm-panel absolute left-0 top-full z-40 mt-1 max-h-48 w-72 overflow-y-auto rounded border pm-border shadow-xl"
                        >
                            <button
                                v-for="(v, vi) in rawSuggestions"
                                :key="v.key"
                                type="button"
                                @mousedown.prevent="insertRawVariable(v.key)"
                                :class="vi === rawAutocompleteIndex ? 'pm-active' : ''"
                                class="block w-full px-3 py-1.5 text-left font-mono text-xs pm-text-2 pm-hover"
                            >
                                <span class="font-semibold pm-accent-text">{{ varLabel(v.key) }}</span>
                                <span class="pm-faint"> = {{ v.value }}</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div v-if="request.body_type === 'binary'">
                    <label class="pm-field flex w-full max-w-md cursor-pointer items-center gap-2 text-sm pm-muted">
                        <span class="truncate">{{ binaryName || 'Choose a file…' }}</span>
                        <input type="file" class="hidden" @change="onBinaryFile">
                    </label>
                    <p class="mt-2 text-xs pm-faint">The file is uploaded when you send.</p>
                </div>
                <div v-if="request.body_type === 'graphql'">
                    <label class="mb-1 block text-xs font-medium pm-muted">Query</label>
                    <textarea
                        v-model="request.body.graphql.query"
                        rows="5"
                        spellcheck="false"
                        placeholder="query { users { id name } }"
                        class="pm-field pm-field-mono w-full"
                    ></textarea>
                    <label class="mb-1 mt-2 block text-xs font-medium pm-muted">Variables (JSON)</label>
                    <textarea
                        v-model="request.body.graphql.variables"
                        rows="3"
                        spellcheck="false"
                        placeholder='{"id": 1}'
                        class="pm-field pm-field-mono w-full"
                    ></textarea>
                    <p class="mt-2 text-xs pm-faint">Sent as a JSON envelope {"{ query, variables }"} with Content-Type: application/json.</p>
                </div>
            </div>

            <!-- Scripts -->
            <div v-show="activeTab === 'scripts'">
                <p class="mb-2 text-xs pm-faint">Pre-request and test scripts run in the backend sandbox when the request is sent; results appear in the ResponseViewer Test Results tab.</p>
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <label class="text-xs font-medium pm-muted">Pre-request Script</label>
                    <span class="flex-1"></span>
                    <select v-model="preSnippet" class="pm-field pm-field-xs">
                        <option value="">Insert snippet…</option>
                        <option v-for="s in preSnippets" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                    <button type="button" @click="insertSnippet('pre_request')" :disabled="!preSnippet" class="pm-btn pm-btn-ghost pm-btn-sm">Insert</button>
                </div>
                <div class="mb-3 flex overflow-hidden rounded border pm-border">
                    <div ref="preGutter" class="shrink-0 select-none overflow-hidden px-2 py-2 text-right font-mono text-xs leading-5 pm-faint pm-code-bg">
                        <div v-for="n in lineCount(request.scripts.pre_request)" :key="n">{{ n }}</div>
                    </div>
                    <textarea
                        v-model="request.scripts.pre_request"
                        rows="6"
                        spellcheck="false"
                        placeholder="// pm.environment.set('token', 'abc');"
                        class="pm-field pm-field-mono pm-field-flush min-w-0 flex-1 leading-5"
                        @scroll="syncGutter('preGutter', $event)"
                    ></textarea>
                </div>
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <label class="text-xs font-medium pm-muted">Post-response / Tests</label>
                    <span class="flex-1"></span>
                    <select v-model="testSnippet" class="pm-field pm-field-xs">
                        <option value="">Insert snippet…</option>
                        <option v-for="s in testSnippets" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                    <button type="button" @click="insertSnippet('tests')" :disabled="!testSnippet" class="pm-btn pm-btn-ghost pm-btn-sm">Insert</button>
                </div>
                <div class="flex overflow-hidden rounded border pm-border">
                    <div ref="testGutter" class="shrink-0 select-none overflow-hidden px-2 py-2 text-right font-mono text-xs leading-5 pm-faint pm-code-bg">
                        <div v-for="n in lineCount(request.scripts.tests)" :key="n">{{ n }}</div>
                    </div>
                    <textarea
                        v-model="request.scripts.tests"
                        rows="6"
                        spellcheck="false"
                        placeholder="// pm.test('Status is 200', () => pm.response.to.have.status(200));"
                        class="pm-field pm-field-mono pm-field-flush min-w-0 flex-1 leading-5"
                        @scroll="syncGutter('testGutter', $event)"
                    ></textarea>
                </div>
            </div>

            <!-- Code -->
            <div v-show="activeTab === 'code'">
                <CodeSnippet :request="request" />
            </div>

            <!-- Description -->
            <div v-show="activeTab === 'description'">
                <p class="mb-2 text-xs pm-faint">Markdown — shown in the collection documentation view.</p>
                <textarea
                    v-model="request.description"
                    rows="4"
                    spellcheck="false"
                    placeholder="# What does this request do?&#10;Describe the endpoint, its parameters and what it returns."
                    class="w-full rounded border pm-input px-3 py-2 text-sm outline-none focus:border-[#FF6C37]"
                ></textarea>
                <div v-if="request.description" class="doc-markdown mt-3 rounded border pm-border pm-surface px-3 py-2 text-sm pm-text-2" v-html="renderedDescription"></div>
            </div>

            <!-- Settings -->
            <div v-show="activeTab === 'settings'">
                <div class="flex max-w-md flex-col gap-3 text-sm pm-text-2">
                    <label class="flex items-center justify-between gap-4">
                        Timeout (seconds)
                        <input v-model.number="request.settings.timeout" type="number" min="1" max="300" class="pm-field w-24">
                    </label>
                    <label class="flex items-center justify-between gap-4">
                        Max redirects
                        <input v-model.number="request.settings.max_redirects" type="number" min="0" max="10" class="pm-field w-24">
                    </label>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input v-model="request.settings.follow_redirects" type="checkbox"> Follow redirects
                    </label>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input v-model="request.settings.verify_ssl" type="checkbox"> Verify SSL certificates
                    </label>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input v-model="request.settings.send_cookies" type="checkbox"> Send cookies (use the stored cookie jar)
                    </label>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { store, variablesForPreview, canEditActiveWorkspace } from '../store.js'
import { resolveVariables, variableSegments, pathVariables, variableSuggestions, insertVariableAt, renderMarkdown } from '../utils/variables.js'
import { methodClass, METHODS } from '../utils/methods.js'
import { api, errMessage } from '../api.js'
import KeyValueEditor from './KeyValueEditor.vue'
import CodeSnippet from './CodeSnippet.vue'

const PRE_SNIPPETS = [
    { key: 'env-set', label: "pm.environment.set('key', 'value')", code: "pm.environment.set('key', 'value');" },
    { key: 'var-set', label: "pm.variables.set('key', 'value')", code: "pm.variables.set('key', 'value');" },
    { key: 'global-set', label: "pm.globals.set('key', 'value')", code: "pm.globals.set('key', 'value');" },
    { key: 'timestamp', label: 'Set timestamp variable', code: "pm.environment.set('timestamp', Date.now());" },
    { key: 'header', label: 'Add a header', code: "pm.request.headers.add({ key: 'X-Custom', value: 'value' });" },
    { key: 'log', label: 'console.log', code: "console.log('pre-request running');" },
]

const TEST_SNIPPETS = [
    { key: 'status-200', label: "pm.test('Status is 200')", code: "pm.test('Status is 200', () => pm.response.to.have.status(200));" },
    { key: 'time', label: 'Response time under 500ms', code: "pm.test('Response time under 500ms', () => pm.expect(pm.response.responseTime).to.be.below(500));" },
    { key: 'body-field', label: 'Body has a field', code: "pm.test('Body has id', () => {\n    const json = pm.response.json();\n    pm.expect(json.id).to.exist;\n});" },
    { key: 'save-token', label: 'Save token to environment', code: "pm.environment.set('token', pm.response.json().token);" },
    { key: 'log', label: 'console.log response', code: "console.log('response:', pm.response.json());" },
]

export default {
    name: 'RequestBuilder',
    components: { KeyValueEditor, CodeSnippet },
    props: {
        request: { type: Object, required: true },
        sending: { type: Boolean, default: false },
        saving: { type: Boolean, default: false },
        saveMessage: { type: String, default: '' },
        saveMessageIsError: { type: Boolean, default: false },
        isDirty: { type: Boolean, default: false },
    },
    emits: ['send', 'save'],
    computed: {
        canEdit() {
            return canEditActiveWorkspace()
        },
    },
    data() {
        return {
            store,
            methods: METHODS,
            activeTab: 'params',
            showAutoHeaders: false,
            authNote: '',
            oauthLoading: false,
            oauthMessage: '',
            preSnippet: '',
            testSnippet: '',
            preSnippets: PRE_SNIPPETS,
            testSnippets: TEST_SNIPPETS,
            urlAutocompleteIndex: 0,
            rawSuggestions: [],
            rawAutocompleteIndex: 0,
            rawCaret: 0,
        }
    },
    computed: {
        tabs() {
            return [
                { key: 'params', label: 'Params', count: this.request.params.filter((r) => r.key && !r.path).length + this.pathRows.length },
                { key: 'auth', label: 'Authorization' },
                { key: 'headers', label: 'Headers', count: this.request.headers.filter((r) => r.key).length },
                { key: 'body', label: 'Body' },
                { key: 'description', label: 'Description' },
                { key: 'scripts', label: 'Scripts' },
                { key: 'code', label: 'Code' },
                { key: 'settings', label: 'Settings' },
            ]
        },
        renderedDescription() {
            return renderMarkdown(this.request.description || '')
        },
        previewVariables() {
            return variablesForPreview(this.request.collection_id)
        },
        // Path variables first (:id substituted), then {{var}} resolution.
        resolvedUrl() {
            let url = this.request.url || ''
            this.pathRows.forEach((row) => {
                if (row.value) url = url.split(':' + row.key).join(encodeURIComponent(row.value))
            })
            return resolveVariables(url, this.previewVariables)
        },
        varSegments() {
            let url = this.request.url || ''
            this.pathRows.forEach((row) => {
                if (row.value) url = url.split(':' + row.key).join(encodeURIComponent(row.value))
            })
            return variableSegments(url, this.previewVariables)
        },
        pathRows() {
            return (this.request.params || []).filter((r) => r.path)
        },
        binaryName() {
            const b = this.request.body.binary
            if (b && b._file) return b._file.name
            if (b && b.name) return b.name
            return ''
        },
        autocomplete() {
            const { matches } = variableSuggestions(this.request.url || '', (this.request.url || '').length, this.previewVariables)
            return matches
        },
        autoHeaders() {
            const headers = []
            if (this.request.body_type === 'raw' && this.request.body.raw_language === 'json') {
                headers.push({ key: 'Content-Type', value: 'application/json (auto)' })
            } else if (this.request.body_type === 'graphql') {
                headers.push({ key: 'Content-Type', value: 'application/json (auto)' })
            } else if (this.request.body_type === 'urlencoded') {
                headers.push({ key: 'Content-Type', value: 'application/x-www-form-urlencoded (auto)' })
            } else if (this.request.body_type === 'form-data') {
                headers.push({ key: 'Content-Type', value: 'multipart/form-data; boundary=… (auto, set by the HTTP client)' })
            } else if (this.request.body_type === 'binary') {
                headers.push({ key: 'Content-Type', value: 'from the file mime type (auto)' })
            }
            if (this.request.auth_type === 'bearer') headers.push({ key: 'Authorization', value: 'Bearer ••• (auto)' })
            if (this.request.auth_type === 'basic') headers.push({ key: 'Authorization', value: 'Basic ••• (auto)' })
            if (this.request.auth_type === 'digest') headers.push({ key: 'Authorization', value: 'Digest ••• (auto, negotiated at send)' })
            if (this.request.auth_type === 'oauth2') headers.push({ key: 'Authorization', value: 'Bearer ••• (auto, from the access token)' })
            if (this.request.auth_type === 'aws') headers.push({ key: 'Authorization', value: 'AWS4-HMAC-SHA256 ••• (auto, signed at send)' })
            if (this.request.auth_type === 'inherit') headers.push({ key: 'Authorization', value: 'from the collection (auto)' })
            if (this.request.auth_type === 'api_key' && this.request.auth.in === 'header') {
                headers.push({ key: this.request.auth.key || '(api key)', value: '••• (auto)' })
            }
            headers.push({ key: 'User-Agent', value: 'GuzzleHttp (set by the HTTP client)' })
            return headers
        },
    },
    methods: {
        methodClass,
        renderMarkdown,
        // Focus the URL field (used when a new tab opens or the user is
        // pointed back at the builder, e.g. after "Send your first request").
        focusUrl() {
            const input = this.$refs.urlInput
            if (input) input.focus()
        },
        varLabel(key) {
            return '{{' + key + '}}'
        },
        onUrlInput(event) {
            this.urlAutocompleteIndex = 0
            // Keep path-variable rows in sync with :name segments in the URL.
            const found = pathVariables(this.request.url)
            found.forEach((pv) => {
                if (!this.request.params.find((r) => r.path && r.key === pv.key)) {
                    this.request.params.push({ key: pv.key, value: '', enabled: true, path: true })
                }
            })
            // Drop path rows whose :name left the URL.
            for (let i = this.request.params.length - 1; i >= 0; i--) {
                const r = this.request.params[i]
                if (r.path && !found.find((pv) => pv.key === r.key)) this.request.params.splice(i, 1)
            }
        },
        insertVariable(key) {
            const { text } = insertVariableAt(this.request.url || '', (this.request.url || '').length, key)
            this.request.url = text
        },
        pickUrlSuggestion() {
            const v = this.autocomplete[this.urlAutocompleteIndex]
            if (v) this.insertVariable(v.key)
        },
        // {{var}} autocomplete in the raw body editor.
        onRawInput(event) {
            this.rawCaret = event.target.selectionStart
            this.rawAutocompleteIndex = 0
            const { matches } = variableSuggestions(this.request.body.raw || '', this.rawCaret, this.previewVariables)
            this.rawSuggestions = matches
        },
        closeRawAutocompleteSoon() {
            setTimeout(() => { this.rawSuggestions = [] }, 150)
        },
        pickRawSuggestion() {
            const v = this.rawSuggestions[this.rawAutocompleteIndex]
            if (v) this.insertRawVariable(v.key)
        },
        insertRawVariable(key) {
            const { text } = insertVariableAt(this.request.body.raw || '', this.rawCaret, key)
            this.request.body.raw = text
            this.rawSuggestions = []
        },
        beautifyRaw() {
            const lang = this.request.body.raw_language
            const raw = this.request.body.raw || ''
            try {
                if (lang === 'json') {
                    this.request.body.raw = JSON.stringify(JSON.parse(raw), null, 2)
                    return
                }
                if (lang === 'xml' || lang === 'html') {
                    // Small indent pass: newline between tags, indent by depth.
                    let depth = 0
                    this.request.body.raw = raw
                        .replace(/></g, '>\n<')
                        .split('\n')
                        .map((line) => {
                            const trimmed = line.trim()
                            if (/^<\/\w/.test(trimmed)) depth = Math.max(0, depth - 1)
                            const out = '  '.repeat(depth) + trimmed
                            if (/^<\w[^>]*[^/]>/.test(trimmed) && !/^<\w[^>]*\/>$/.test(trimmed)) depth++
                            return out
                        })
                        .join('\n')
                }
            } catch (e) {
                // Leave the text as-is when it is not valid JSON/XML.
            }
        },
        onBinaryFile(event) {
            const file = event.target.files && event.target.files[0]
            if (file) {
                this.request.body.binary = { _file: file, name: file.name, binary_content_type: file.type || '' }
            }
        },
        // ---- Scripts: line numbers + snippet picker ----
        lineCount(text) {
            return Math.max(1, String(text || '').split('\n').length)
        },
        syncGutter(refName, event) {
            const gutter = this.$refs[refName]
            if (gutter) gutter.scrollTop = event.target.scrollTop
        },
        insertSnippet(target) {
            const key = target === 'pre_request' ? this.preSnippet : this.testSnippet
            const list = target === 'pre_request' ? this.preSnippets : this.testSnippets
            const snippet = list.find((s) => s.key === key)
            if (!snippet) return
            const field = target === 'pre_request' ? 'pre_request' : 'tests'
            const current = this.request.scripts[field] || ''
            this.request.scripts[field] = current + (current && !current.endsWith('\n') ? '\n' : '') + snippet.code + '\n'
            if (target === 'pre_request') this.preSnippet = ''
            else this.testSnippet = ''
        },
        // ---- OAuth 2.0: exchange credentials for an access token ----
        async fetchOAuthToken() {
            if (!this.request.id) {
                this.oauthMessage = 'Save the request first.'
                return
            }
            this.oauthLoading = true
            this.oauthMessage = ''
            this.authNote = ''
            try {
                const res = await api.fetchOAuthToken(this.request.id, {
                    grant_type: this.request.auth.grant_type,
                    token_url: this.request.auth.token_url,
                    client_id: this.request.auth.client_id,
                    client_secret: this.request.auth.client_secret,
                    scope: this.request.auth.scope,
                    username: this.request.auth.username,
                    password: this.request.auth.password,
                })
                const token = res && (res.access_token || res.token || (res.data && res.data.access_token))
                if (token) {
                    this.request.auth.access_token = token
                    if (res.token_type) this.request.auth.token_type = res.token_type
                    this.oauthMessage = 'Access token received.'
                } else {
                    this.oauthMessage = 'No token in the response.'
                }
            } catch (e) {
                this.oauthMessage = 'Token request failed: ' + errMessage(e)
            } finally {
                this.oauthLoading = false
            }
        },
    },
}
</script>
