// Local code generator — fallback when POST /api/code/generate is not
// available yet (backend parity work in progress). Generates a snippet
// from the builder request state only; no network call.

export const CODE_LANGUAGES = [
    { value: 'curl', label: 'cURL' },
    { value: 'fetch', label: 'JavaScript (fetch)' },
    { value: 'axios', label: 'JavaScript (axios)' },
    { value: 'php', label: 'PHP (Guzzle)' },
    { value: 'python', label: 'Python (requests)' },
    { value: 'go', label: 'Go' },
    { value: 'java', label: 'Java (OkHttp)' },
]

// Flatten a builder request into the plain shape both the backend
// /api/code/generate endpoint and the local generator understand.
export function requestToCodePayload(request) {
    const rows = (list) => (list || [])
        .filter((r) => r.key && String(r.key).trim() !== '' && r.enabled !== false)
        .map((r) => ({ key: r.key, value: r.value || '' }))
    const body = {}
    if (request.body_type === 'raw') {
        body.raw = request.body.raw || ''
        body.raw_language = request.body.raw_language || 'json'
    } else if (request.body_type === 'form-data') {
        body.formdata = rows(request.body.formdata)
    } else if (request.body_type === 'urlencoded') {
        body.urlencoded = rows(request.body.urlencoded)
    } else if (request.body_type === 'graphql') {
        body.graphql = request.body.graphql || { query: '', variables: '' }
    }
    return {
        method: (request.method || 'GET').toUpperCase(),
        url: request.url || '',
        params: rows(request.params),
        headers: rows(request.headers),
        auth_type: request.auth_type || 'none',
        auth: request.auth || {},
        body_type: request.body_type || 'none',
        body,
    }
}

function shellQuote(s) {
    return "'" + String(s).replace(/'/g, "'\\''") + "'"
}

function jsQuote(s) {
    return JSON.stringify(String(s))
}

function buildUrl(payload) {
    let url = payload.url || ''
    const params = (payload.params || []).filter((p) => p.key)
    if (params.length > 0) {
        const qs = params.map((p) => encodeURIComponent(p.key) + '=' + encodeURIComponent(p.value)).join('&')
        url += (url.includes('?') ? '&' : '?') + qs
    }
    return url
}

function authHeaders(payload) {
    const headers = (payload.headers || []).slice()
    const auth = payload.auth || {}
    if (payload.auth_type === 'bearer' && auth.token) {
        headers.push({ key: 'Authorization', value: 'Bearer ' + auth.token })
    } else if (payload.auth_type === 'basic' && (auth.username || auth.password)) {
        headers.push({ key: 'Authorization', value: 'Basic ' + btoa(auth.username + ':' + auth.password) })
    } else if (payload.auth_type === 'api_key' && auth.key && auth.in === 'header') {
        headers.push({ key: auth.key, value: auth.value || '' })
    }
    return headers
}

function bodyPayload(payload) {
    if (payload.body_type === 'raw') return payload.body.raw || ''
    if (payload.body_type === 'urlencoded') {
        return (payload.body.urlencoded || []).map((p) => encodeURIComponent(p.key) + '=' + encodeURIComponent(p.value)).join('&')
    }
    if (payload.body_type === 'graphql') {
        return JSON.stringify({ query: payload.body.graphql.query || '', variables: safeJson(payload.body.graphql.variables) })
    }
    return ''
}

function safeJson(text) {
    try { return JSON.parse(text || '{}') } catch (e) { return {} }
}

function generateCurl(payload) {
    const lines = ['curl --request ' + payload.method + ' \\']
    lines.push('  --url ' + shellQuote(buildUrl(payload)) + ' \\')
    authHeaders(payload).forEach((h) => {
        lines.push('  --header ' + shellQuote(h.key + ': ' + h.value) + ' \\')
    })
    const body = bodyPayload(payload)
    if (body) lines.push('  --data ' + shellQuote(body) + ' \\')
    const last = lines[lines.length - 1]
    lines[lines.length - 1] = last.replace(/ \\$/, '')
    return lines.join('\n')
}

function generateFetch(payload) {
    const headers = {}
    authHeaders(payload).forEach((h) => { headers[h.key] = h.value })
    const body = bodyPayload(payload)
    const opts = ["method: " + jsQuote(payload.method)]
    if (Object.keys(headers).length > 0) opts.push('headers: ' + JSON.stringify(headers, null, 2).replace(/\n/g, '\n  '))
    if (body) opts.push('body: ' + jsQuote(body))
    return 'fetch(' + jsQuote(buildUrl(payload)) + ', {\n  ' + opts.join(',\n  ') + '\n})'
        + '\n  .then((response) => response.text())'
        + '\n  .then((body) => console.log(body))'
        + '\n  .catch((error) => console.error(error));'
}

function generateAxios(payload) {
    const headers = {}
    authHeaders(payload).forEach((h) => { headers[h.key] = h.value })
    const body = bodyPayload(payload)
    const lines = ['axios.request({']
    lines.push('  method: ' + jsQuote(payload.method.toLowerCase()) + ',')
    lines.push('  url: ' + jsQuote(buildUrl(payload)) + ',')
    if (Object.keys(headers).length > 0) lines.push('  headers: ' + JSON.stringify(headers) + ',')
    if (body) lines.push('  data: ' + jsQuote(body) + ',')
    lines.push('})')
    lines.push('  .then((response) => console.log(response.data))')
    lines.push('  .catch((error) => console.error(error));')
    return lines.join('\n')
}

function generatePhp(payload) {
    const lines = ["<?php", "", "$client = new \\GuzzleHttp\\Client();", ""]
    const headers = {}
    authHeaders(payload).forEach((h) => { headers[h.key] = h.value })
    const body = bodyPayload(payload)
    const opts = []
    if (Object.keys(headers).length > 0) {
        opts.push("    'headers' => " + phpArray(headers) + ',')
    }
    if (body) opts.push("    'body' => " + phpString(body) + ',')
    lines.push('$response = $client->request(' + phpString(payload.method) + ', ' + phpString(buildUrl(payload)) + ', [')
    lines.push(...opts)
    lines.push(']);')
    lines.push('')
    lines.push('echo $response->getBody();')
    return lines.join('\n')
}

function phpString(s) {
    return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"
}

function phpArray(obj) {
    const entries = Object.keys(obj).map((k) => phpString(k) + ' => ' + phpString(obj[k]))
    return '[' + entries.join(', ') + ']'
}

function generatePython(payload) {
    const lines = ['import requests', '']
    const headers = {}
    authHeaders(payload).forEach((h) => { headers[h.key] = h.value })
    const body = bodyPayload(payload)
    lines.push('url = ' + JSON.stringify(buildUrl(payload)))
    if (Object.keys(headers).length > 0) lines.push('headers = ' + JSON.stringify(headers, null, 2))
    if (body) lines.push('payload = ' + JSON.stringify(body))
    lines.push('')
    const call = 'response = requests.request("' + payload.method + '", url'
        + (Object.keys(headers).length > 0 ? ', headers=headers' : '')
        + (body ? ', data=payload' : '') + ')'
    lines.push(call)
    lines.push('')
    lines.push('print(response.text)')
    return lines.join('\n')
}

function generateGo(payload) {
    const lines = ['package main', '', 'import (', '\t"fmt"', '\t"io"', '\t"net/http"', '\t"strings"', ')', '', 'func main() {']
    const body = bodyPayload(payload)
    if (body) {
        lines.push('\tpayload := strings.NewReader(' + JSON.stringify(body) + ')')
        lines.push('\treq, _ := http.NewRequest("' + payload.method + '", ' + JSON.stringify(buildUrl(payload)) + ', payload)')
    } else {
        lines.push('\treq, _ := http.NewRequest("' + payload.method + '", ' + JSON.stringify(buildUrl(payload)) + ', nil)')
    }
    authHeaders(payload).forEach((h) => {
        lines.push('\treq.Header.Add(' + JSON.stringify(h.key) + ', ' + JSON.stringify(h.value) + ')')
    })
    lines.push('')
    lines.push('\tres, _ := http.DefaultClient.Do(req)')
    lines.push('\tdefer res.Body.Close()')
    lines.push('\tbody, _ := io.ReadAll(res.Body)')
    lines.push('\tfmt.Println(string(body))')
    lines.push('}')
    return lines.join('\n')
}

function generateJava(payload) {
    const lines = ['OkHttpClient client = new OkHttpClient();', '']
    const body = bodyPayload(payload)
    if (body) {
        lines.push('MediaType mediaType = MediaType.parse("application/json");')
        lines.push('RequestBody body = RequestBody.create(mediaType, ' + JSON.stringify(body) + ');')
    }
    lines.push('Request request = new Request.Builder()')
    lines.push('  .url(' + JSON.stringify(buildUrl(payload)) + ')')
    lines.push('  .method("' + payload.method + '", ' + (body ? 'body' : 'null') + ')')
    authHeaders(payload).forEach((h) => {
        lines.push('  .addHeader(' + JSON.stringify(h.key) + ', ' + JSON.stringify(h.value) + ')')
    })
    lines.push('  .build();')
    lines.push('')
    lines.push('Response response = client.newCall(request).execute();')
    return lines.join('\n')
}

const GENERATORS = {
    curl: generateCurl,
    fetch: generateFetch,
    axios: generateAxios,
    php: generatePhp,
    python: generatePython,
    go: generateGo,
    java: generateJava,
}

// Generate code locally for a request payload (see requestToCodePayload).
// Returns '' for an unknown language.
export function generateCodeLocal(payload, language) {
    const fn = GENERATORS[language] || GENERATORS.curl
    try {
        return fn(payload)
    } catch (e) {
        return ''
    }
}
