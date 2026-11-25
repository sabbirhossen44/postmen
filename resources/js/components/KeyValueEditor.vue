<template>
    <div>
        <div class="mb-1 flex justify-end">
            <button type="button" @click="bulk = !bulk" class="pm-btn pm-btn-ghost pm-btn-sm">
                {{ bulk ? 'Key-Value Edit' : 'Bulk Edit' }}
            </button>
        </div>
        <div v-if="bulk">
            <textarea
                :value="bulkText"
                @input="applyBulk($event.target.value)"
                rows="6"
                spellcheck="false"
                placeholder="key: value&#10;one per line — disabled rows start with #"
                class="pm-field pm-field-mono w-full"
            ></textarea>
        </div>
        <div v-else>
        <div
            v-for="(row, i) in rows"
            :key="i"
            class="flex items-center gap-2 py-1"
        >
            <input
                type="checkbox"
                v-model="row.enabled"
                class="h-4 w-4 shrink-0"
                title="Enable this row"
            >
            <input
                v-model="row.key"
                type="text"
                :placeholder="keyPlaceholder"
                class="pm-field w-1/3"
            >
            <template v-if="allowFile && row.type === 'file'">
                <label class="pm-field flex w-1/3 cursor-pointer items-center gap-2 text-sm pm-muted">
                    <span class="truncate">{{ row.value || 'Choose a file…' }}</span>
                    <input type="file" class="hidden" @change="onFile(row, $event)">
                </label>
                <select
                    v-model="row.type"
                    class="pm-field pm-field-xs shrink-0"
                >
                    <option value="text">Text</option>
                    <option value="file">File</option>
                </select>
            </template>
            <template v-else>
                <span class="relative min-w-0 flex-1">
                    <input
                        v-model="row.value"
                        type="text"
                        :placeholder="valuePlaceholder"
                        class="pm-field w-full"
                        @input="onValueInput(row, i, $event)"
                        @focus="onValueInput(row, i, $event)"
                        @blur="closeAutocompleteSoon"
                        @keydown.down.prevent="autocompleteIndex = Math.min(autocompleteIndex + 1, suggestions.length - 1)"
                        @keydown.up.prevent="autocompleteIndex = Math.max(autocompleteIndex - 1, 0)"
                        @keydown.enter.prevent="pickSuggestion(row)"
                        @keydown.esc="suggestions = []"
                    >
                    <span
                        v-if="suggestions.length > 0 && activeRow === i"
                        class="pm-panel absolute left-0 top-full z-40 mt-1 max-h-48 w-72 overflow-y-auto rounded border pm-border shadow-xl"
                    >
                        <button
                            v-for="(v, vi) in suggestions"
                            :key="v.key"
                            type="button"
                            @mousedown.prevent="insertSuggestion(row, v.key)"
                            :class="vi === autocompleteIndex ? 'pm-active' : ''"
                            class="block w-full px-3 py-1.5 text-left font-mono text-xs pm-text-2 pm-hover"
                        >
                            <span class="font-semibold pm-accent-text">{{ varLabel(v.key) }}</span>
                            <span class="pm-faint"> = {{ v.value }}</span>
                        </button>
                    </span>
                </span>
                <select
                    v-if="allowFile"
                    v-model="row.type"
                    class="pm-field pm-field-xs shrink-0"
                >
                    <option value="text">Text</option>
                    <option value="file">File</option>
                </select>
            </template>
            <input
                v-if="showDescription"
                v-model="row.description"
                type="text"
                placeholder="Description"
                class="pm-field w-1/4"
            >
            <button
                type="button"
                @click="removeRow(i)"
                class="pm-icon-btn pm-icon-btn-danger shrink-0"
                title="Delete row"
            >
                ✕
            </button>
        </div>
        <button
            type="button"
            @click="addRow"
            class="pm-btn pm-btn-ghost pm-btn-sm mt-1"
        >
            + Add row
        </button>
        </div>
    </div>
</template>

<script>
import { variableSuggestions, insertVariableAt } from '../utils/variables.js'

export default {
    name: 'KeyValueEditor',
    props: {
        rows: { type: Array, required: true },
        keyPlaceholder: { type: String, default: 'Key' },
        valuePlaceholder: { type: String, default: 'Value' },
        showDescription: { type: Boolean, default: false },
        allowFile: { type: Boolean, default: false },
        // Variables in scope (environment + collection + globals) for
        // {{var}} autocomplete in the value fields.
        variables: { type: Array, default: () => [] },
    },
    data() {
        return { bulk: false, suggestions: [], activeRow: -1, autocompleteIndex: 0, autocompleteCaret: 0 }
    },
    computed: {
        // Bulk Edit view: one "key: value" line per row.
        bulkText() {
            return this.rows
                .filter((r) => r.key || r.value)
                .map((r) => (r.enabled === false ? '# ' : '') + r.key + ': ' + (r.value || ''))
                .join('\n')
        },
    },
    methods: {
        varLabel(key) {
            return '{{' + key + '}}'
        },
        // {{var}} autocomplete in a value field: suggest while typing
        // an open "{{prefix" at the caret.
        onValueInput(row, i, event) {
            this.activeRow = i
            this.autocompleteIndex = 0
            this.autocompleteCaret = event.target.selectionStart
            const { matches } = variableSuggestions(row.value || '', this.autocompleteCaret, this.variables)
            this.suggestions = matches
        },
        closeAutocompleteSoon() {
            setTimeout(() => { this.suggestions = [] }, 150)
        },
        pickSuggestion(row) {
            const v = this.suggestions[this.autocompleteIndex]
            if (v) this.insertSuggestion(row, v.key)
        },
        insertSuggestion(row, key) {
            const { text } = insertVariableAt(row.value || '', this.autocompleteCaret, key)
            row.value = text
            this.suggestions = []
        },
        applyBulk(text) {
            const rows = text.split('\n').map((line) => {
                const disabled = line.startsWith('#')
                const clean = disabled ? line.slice(1).trim() : line
                const idx = clean.indexOf(':')
                if (idx === -1) return clean.trim() ? { key: clean.trim(), value: '', enabled: !disabled } : null
                return { key: clean.slice(0, idx).trim(), value: clean.slice(idx + 1).trim(), enabled: !disabled }
            }).filter(Boolean)
            if (this.allowFile) rows.forEach((r) => { r.type = 'text' })
            if (this.showDescription) rows.forEach((r) => { r.description = '' })
            this.rows.splice(0, this.rows.length, ...rows)
            if (this.rows.length === 0) this.addRow()
        },
        addRow() {
            const row = { key: '', value: '', enabled: true }
            if (this.showDescription) row.description = ''
            if (this.allowFile) row.type = 'text'
            this.rows.push(row)
        },
        removeRow(i) {
            this.rows.splice(i, 1)
        },
        // Keep the picked File on the row (as _file) and show its name.
        // The file itself is uploaded to /media when the request is sent.
        onFile(row, event) {
            const file = event.target.files && event.target.files[0]
            if (file) {
                row._file = file
                row.value = file.name
            }
        },
    },
}
</script>
