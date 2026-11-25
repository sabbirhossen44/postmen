<template>
    <div>
        <div class="mb-1 flex justify-end">
            <button type="button" @click="bulk = !bulk" class="rounded px-2 py-0.5 text-xs pm-muted hover:pm-text">
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
                class="w-full rounded border pm-input px-2 py-1.5 font-mono text-sm outline-none focus:border-[#FF6C37]"
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
                class="h-4 w-4 shrink-0 accent-[#FF6C37]"
                title="Enable this row"
            >
            <input
                v-model="row.key"
                type="text"
                :placeholder="keyPlaceholder"
                class="w-1/3 rounded border pm-input px-2 py-1.5 text-sm pm-text-2 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
            >
            <template v-if="allowFile && row.type === 'file'">
                <label class="flex w-1/3 cursor-pointer items-center gap-2 rounded border pm-input px-2 py-1.5 text-sm pm-muted hover:border-[#FF6C37]">
                    <span class="truncate">{{ row.value || 'Choose a file…' }}</span>
                    <input type="file" class="hidden" @change="onFile(row, $event)">
                </label>
                <select
                    v-model="row.type"
                    class="shrink-0 rounded border pm-input px-1 py-1.5 text-xs pm-text-2 outline-none"
                >
                    <option value="text">Text</option>
                    <option value="file">File</option>
                </select>
            </template>
            <template v-else>
                <input
                    v-model="row.value"
                    type="text"
                    :placeholder="valuePlaceholder"
                    class="flex-1 rounded border pm-input px-2 py-1.5 text-sm pm-text-2 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                >
                <select
                    v-if="allowFile"
                    v-model="row.type"
                    class="shrink-0 rounded border pm-input px-1 py-1.5 text-xs pm-text-2 outline-none"
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
                class="w-1/4 rounded border pm-input px-2 py-1.5 text-sm pm-text-2 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
            >
            <button
                type="button"
                @click="removeRow(i)"
                class="shrink-0 rounded px-2 py-1 pm-faint hover:bg-neutral-700 hover:text-red-400"
                title="Delete row"
            >
                ✕
            </button>
        </div>
        <button
            type="button"
            @click="addRow"
            class="mt-1 rounded px-2 py-1 text-sm pm-muted hover:bg-neutral-700 hover:pm-text-2"
        >
            + Add row
        </button>
        </div>
    </div>
</template>

<script>
export default {
    name: 'KeyValueEditor',
    props: {
        rows: { type: Array, required: true },
        keyPlaceholder: { type: String, default: 'Key' },
        valuePlaceholder: { type: String, default: 'Value' },
        showDescription: { type: Boolean, default: false },
        allowFile: { type: Boolean, default: false },
    },
    data() {
        return { bulk: false }
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
