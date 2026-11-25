<template>
    <div>
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
                class="w-1/3 rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-1.5 text-sm text-neutral-200 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
            >
            <template v-if="allowFile && row.type === 'file'">
                <label class="flex w-1/3 cursor-pointer items-center gap-2 rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-1.5 text-sm text-neutral-400 hover:border-[#FF6C37]">
                    <span class="truncate">{{ row.value || 'Choose a file…' }}</span>
                    <input type="file" class="hidden" @change="onFile(row, $event)">
                </label>
                <select
                    v-model="row.type"
                    class="shrink-0 rounded border border-neutral-700 bg-[#2b2b2b] px-1 py-1.5 text-xs text-neutral-300 outline-none"
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
                    class="flex-1 rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-1.5 text-sm text-neutral-200 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                >
                <select
                    v-if="allowFile"
                    v-model="row.type"
                    class="shrink-0 rounded border border-neutral-700 bg-[#2b2b2b] px-1 py-1.5 text-xs text-neutral-300 outline-none"
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
                class="w-1/4 rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-1.5 text-sm text-neutral-200 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
            >
            <button
                type="button"
                @click="removeRow(i)"
                class="shrink-0 rounded px-2 py-1 text-neutral-500 hover:bg-neutral-700 hover:text-red-400"
                title="Delete row"
            >
                ✕
            </button>
        </div>
        <button
            type="button"
            @click="addRow"
            class="mt-1 rounded px-2 py-1 text-sm text-neutral-400 hover:bg-neutral-700 hover:text-neutral-200"
        >
            + Add row
        </button>
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
    methods: {
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
