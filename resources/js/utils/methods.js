// Exact Postman method colours — plain coloured text, never pills.
export const METHOD_COLORS = {
    GET: '#6BDD9A',
    POST: '#FFE47A',
    PUT: '#74AEF6',
    PATCH: '#C0A8F0',
    DELETE: '#F79090',
    HEAD: '#A3A3A3',
    OPTIONS: '#A3A3A3',
}

export function methodClass(method) {
    return 'method-' + (method || 'GET').toLowerCase()
}

export function methodColor(method) {
    return METHOD_COLORS[(method || 'GET').toUpperCase()] || '#A3A3A3'
}

export const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']

export function statusClass(status) {
    if (!status) return ''
    if (status >= 200 && status < 300) return 'status-ok'
    if (status >= 300 && status < 400) return 'status-redirect'
    return 'status-error'
}
