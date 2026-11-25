<template>
    <span>
        <template v-if="isObject">
            <button type="button" @click="collapsed = !collapsed" class="pm-muted hover:pm-text">{{ collapsed ? '▸' : '▾' }}</button>
            <span v-if="name !== null" class="json-key">"{{ name }}"</span><span v-if="name !== null">: </span>
            <span class="pm-muted">{{ isArray ? 'Array' : 'Object' }} ({{ count }})</span>
            <span v-if="collapsed"> {{ isArray ? '[…]' : '{…}' }}</span>
            <div v-else class="pl-4 border-l pm-border ml-1">
                <div v-for="(child, key) in entries" :key="key">
                    <JsonTree :value="child" :name="isArray ? null : key" :path="path + '/' + key" :search="search" :depth="depth + 1" />
                </div>
            </div>
        </template>
        <template v-else>
            <span v-if="name !== null" class="json-key">"{{ name }}"</span><span v-if="name !== null">: </span><span :class="valueClass" v-html="highlightedValue"></span>
        </template>
    </span>
</template>

<script>
export default {
    name: 'JsonTree',
    props: {
        value: { required: true },
        name: { type: [String, Number], default: null },
        path: { type: String, default: '' },
        search: { type: String, default: '' },
        depth: { type: Number, default: 0 },
    },
    data() {
        return { collapsed: this.depth >= 2 }
    },
    computed: {
        isObject() {
            return this.value !== null && typeof this.value === 'object'
        },
        isArray() {
            return Array.isArray(this.value)
        },
        count() {
            return this.isObject ? Object.keys(this.value).length : 0
        },
        entries() {
            return this.value
        },
        valueClass() {
            if (typeof this.value === 'string') return 'json-string'
            if (typeof this.value === 'number') return 'json-number'
            if (typeof this.value === 'boolean') return 'json-boolean'
            return 'json-null'
        },
        highlightedValue() {
            const text = typeof this.value === 'string'
                ? '"' + this.value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '"'
                : String(this.value)
            if (!this.search) return text
            const idx = text.toLowerCase().indexOf(this.search.toLowerCase())
            if (idx === -1) return text
            return text.slice(0, idx) + '<mark>' + text.slice(idx, idx + this.search.length) + '</mark>' + text.slice(idx + this.search.length)
        },
    },
}
</script>
