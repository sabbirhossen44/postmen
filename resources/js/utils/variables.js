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

// ---- {{var}} autocomplete, shared by the URL bar, key-value editors
// and the raw body editor. Looks backwards from caret for an open
// "{{prefix" and returns the matching variables (max 8).
export function variableSuggestions(text, caretPos, variables) {
    const before = String(text || '').slice(0, caretPos === undefined ? (text || '').length : caretPos)
    const m = before.match(/\{\{\s*([\w.\-]*)$/)
    if (!m) return { prefix: null, matches: [] }
    const prefix = m[1].toLowerCase()
    const matches = (variables || [])
        .filter((v) => v && v.key && v.key.toLowerCase().startsWith(prefix))
        .slice(0, 8)
    return { prefix: m[1], matches }
}

// Replace the open "{{prefix" before caretPos with the full {{key}}.
export function insertVariableAt(text, caretPos, key) {
    const before = String(text || '').slice(0, caretPos)
    const after = String(text || '').slice(caretPos)
    const replaced = before.replace(/\{\{\s*[\w.\-]*$/, '{{' + key + '}}')
    return { text: replaced + after, caret: replaced.length }
}

// Render a small markdown subset (headings, bold, italic, code, links,
// lists) to HTML for request descriptions. Escapes raw HTML first.
export function renderMarkdown(text) {
    const escaped = String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
    const inline = (line) => line
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/\[([^\]]+)\]\((https?:[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
    const lines = escaped.split('\n')
    const out = []
    let list = null
    const closeList = () => {
        if (list) {
            out.push('</ul>')
            list = null
        }
    }
    lines.forEach((line) => {
        const heading = line.match(/^(#{1,3})\s+(.*)$/)
        const item = line.match(/^[-*]\s+(.*)$/)
        if (heading) {
            closeList()
            const level = heading[1].length
            out.push('<h' + level + '>' + inline(heading[2]) + '</h' + level + '>')
        } else if (item) {
            if (!list) {
                out.push('<ul>')
                list = true
            }
            out.push('<li>' + inline(item[1]) + '</li>')
        } else if (line.trim() === '') {
            closeList()
        } else {
            closeList()
            out.push('<p>' + inline(line) + '</p>')
        }
    })
    closeList()
    return out.join('\n')
}
