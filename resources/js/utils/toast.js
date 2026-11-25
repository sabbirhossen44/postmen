// Toast system — replaces raw alert() calls everywhere.
// Toasts auto-dismiss, stack newest-first, and are fully reactive.
import { reactive } from 'vue'

export const toasts = reactive([])

let toastSeq = 1

export function pushToast(message, type = 'info', timeoutMs = 4000) {
    const toast = {
        id: toastSeq++,
        message: String(message || ''),
        type: ['success', 'error', 'info'].includes(type) ? type : 'info',
        leaving: false,
    }
    toasts.push(toast)
    if (toasts.length > 5) toasts.splice(0, toasts.length - 5)
    if (timeoutMs > 0) {
        setTimeout(() => dismissToast(toast.id), timeoutMs)
    }
    return toast.id
}

export function dismissToast(id) {
    const toast = toasts.find((t) => t.id === id)
    if (!toast) return
    toast.leaving = true
    setTimeout(() => {
        const idx = toasts.findIndex((t) => t.id === id)
        if (idx !== -1) toasts.splice(idx, 1)
    }, 180)
}

export function toastSuccess(message) {
    return pushToast(message, 'success')
}

export function toastError(message) {
    return pushToast(message, 'error', 6000)
}

export function toastInfo(message) {
    return pushToast(message, 'info')
}
