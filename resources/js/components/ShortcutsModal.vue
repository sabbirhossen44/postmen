<template>
    <Modal :open="open" title="Keyboard shortcuts" confirm-label="Done" @close="$emit('close')" @confirm="$emit('close')">
        <div class="flex flex-col gap-1.5 text-sm">
            <div v-for="s in shortcuts" :key="s.keys" class="flex items-center justify-between gap-4 rounded px-2 py-1.5 pm-hover">
                <span class="pm-text-2">{{ s.label }}</span>
                <span class="flex shrink-0 items-center gap-1">
                    <span v-for="key in s.keys" :key="key" class="pm-kbd">{{ key }}</span>
                </span>
            </div>
        </div>
        <p class="mt-4 text-xs pm-faint">On macOS use Cmd (⌘); on Windows and Linux use Ctrl. Shortcuts work anywhere except while typing in a text field, apart from Send and Save, which also work there.</p>
    </Modal>
</template>

<script>
import Modal from './Modals.vue'

export default {
    name: 'ShortcutsModal',
    components: { Modal },
    props: {
        open: { type: Boolean, default: false },
    },
    emits: ['close'],
    data() {
        return {
            shortcuts: [
                { label: 'Send request', keys: ['⌘ / Ctrl', '↵'] },
                { label: 'Save request', keys: ['⌘ / Ctrl', 'S'] },
                { label: 'New tab', keys: ['⌘ / Ctrl', 'T'] },
                { label: 'Command palette', keys: ['⌘ / Ctrl', 'K'] },
                { label: 'Toggle console', keys: ['⌘ / Ctrl', '⌥', 'C'] },
                { label: 'Toggle sidebar', keys: ['⌘ / Ctrl', 'B'] },
                { label: 'Close dialog / palette', keys: ['Esc'] },
                { label: 'Show this list', keys: ['?'] },
            ],
        }
    },
}
</script>
