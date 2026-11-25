// Replace {{key}} placeholders with matching variable values.
// Later variable sets win on duplicate keys, matching the backend order
// (environment variables first, then collection variables).
export function resolveVariables(text, variables) {
    if (typeof text !== 'string' || !text) return text || ''
    const map = {}
    ;(variables || []).forEach((v) => {
        if (v && v.key) map[v.key] = v.value !== undefined && v.value !== null ? String(v.value) : ''
    })
    return text.replace(/\{\{\s*([\w.\-]+)\s*\}\}/g, (match, key) => {
        return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : match
    })
}

// Merge several variable arrays (each [{key, value, enabled}]) into one list
// of enabled variables, later arrays overriding earlier ones.
export function collectVariables(...sets) {
    const map = {}
    sets.forEach((set) => {
        ;(set || []).forEach((v) => {
            if (v && v.key && v.enabled !== false) map[v.key] = v.value
        })
    })
    return Object.keys(map).map((key) => ({ key, value: map[key], enabled: true }))
}

// Split text into plain / {{variable}} segments, marking each variable
// resolved or not against the given variable list.
export function variableSegments(text, variables) {
    const map = {}
    ;(variables || []).forEach((v) => {
        if (v && v.key) map[v.key] = v.value !== undefined && v.value !== null ? String(v.value) : ''
    })
    const out = []
    const re = /\{\{\s*([\w.\-]+)\s*\}\}/g
    let last = 0
    let m
    while ((m = re.exec(text || '')) !== null) {
        if (m.index > last) out.push({ text: text.slice(last, m.index), variable: null })
        out.push({ text: m[0], variable: m[1], resolved: Object.prototype.hasOwnProperty.call(map, m[1]), value: map[m[1]] })
        last = m.index + m[0].length
    }
    if (last < (text || '').length) out.push({ text: text.slice(last), variable: null })
    return out
}

// Path variables: :id, :userId segments of the URL path.
export function pathVariables(url) {
    const found = []
    const path = (url || '').split('?')[0]
    const re = /:([A-Za-z_][\w]*)/g
    let m
    while ((m = re.exec(path)) !== null) {
        if (!found.find((v) => v.key === m[1])) found.push({ key: m[1], value: '', enabled: true })
    }
    return found
}

// Pretty-print a JSON string, or return the input unchanged when it is not JSON.
export function prettyJson(body) {
    if (body === null || body === undefined) return ''
    if (typeof body === 'object') {
        try { return JSON.stringify(body, null, 2) } catch (e) { return String(body) }
    }
    try {
        return JSON.stringify(JSON.parse(body), null, 2)
    } catch (e) {
        return String(body)
    }
}

export function formatBytes(bytes) {
    if (bytes === null || bytes === undefined || isNaN(bytes)) return '—'
    if (bytes < 1024) return bytes + ' B'
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB'
}

export function formatTime(ms) {
    if (ms === null || ms === undefined || isNaN(ms)) return '—'
    if (ms < 1000) return Math.round(ms) + ' ms'
    return (ms / 1000).toFixed(2) + ' s'
}
