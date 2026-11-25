<template>
    <header class="flex h-12 shrink-0 items-center gap-3 border-b border-neutral-800 bg-[#212121] px-4">
        <div class="flex items-center gap-2">
            <span class="text-lg font-bold text-neutral-100">Postmen<span class="text-[#FF6C37]">.</span></span>
        </div>

        <div class="flex items-center gap-2">
            <select
                :value="store.activeWorkspaceId"
                @change="onWorkspaceChange($event.target.value)"
                class="max-w-44 rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-1.5 text-sm text-neutral-200 outline-none"
            >
                <option v-for="ws in store.workspaces" :key="ws.id" :value="ws.id">{{ ws.name }}</option>
                <option v-if="store.workspaces.length === 0" value="">No workspace</option>
            </select>
            <button
                type="button"
                @click="showWorkspaceModal = true"
                class="rounded border border-neutral-700 px-2 py-1.5 text-sm text-neutral-300 hover:bg-neutral-700"
                title="New workspace"
            >
                + Workspace
            </button>
        </div>

        <div class="flex-1"></div>

        <button
            type="button"
            @click="triggerImport"
            class="rounded border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 hover:bg-neutral-700"
            title="Import a Postman collection JSON file"
        >
            Import
        </button>
        <input ref="importInput" type="file" accept=".json,application/json" class="hidden" @change="onImportFile">

        <div class="flex items-center gap-2">
            <select
                :value="store.activeEnvironmentId"
                @change="onEnvironmentChange($event.target.value)"
                class="max-w-44 rounded border border-neutral-700 bg-[#2b2b2b] px-2 py-1.5 text-sm text-neutral-200 outline-none"
                title="Active environment"
            >
                <option value="">No Environment</option>
                <option v-for="env in store.environments" :key="env.id" :value="env.id">{{ env.name }}</option>
            </select>
            <button
                type="button"
                @click="$emit('manage-environments')"
                class="rounded border border-neutral-700 px-2 py-1.5 text-sm text-neutral-300 hover:bg-neutral-700"
                title="Manage environments"
            >
                Manage
            </button>
        </div>

        <div class="flex items-center gap-2 border-l border-neutral-700 pl-3">
            <span class="max-w-32 truncate text-sm text-neutral-300" :title="store.user && store.user.email">
                {{ store.user ? (store.user.name || store.user.email) : '' }}
            </span>
            <button
                type="button"
                @click="logout"
                class="rounded border border-neutral-700 px-2 py-1.5 text-sm text-neutral-300 hover:bg-neutral-700 hover:text-red-400"
            >
                Logout
            </button>
        </div>

        <Modal
            :open="showWorkspaceModal"
            title="New Workspace"
            confirm-label="Create"
            :confirm-disabled="!workspaceName.trim()"
            @close="showWorkspaceModal = false"
            @confirm="createWorkspace"
        >
            <label class="mb-1 block text-xs font-medium text-neutral-400">Name</label>
            <input
                v-model="workspaceName"
                type="text"
                placeholder="Workspace name"
                class="mb-3 w-full rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
            >
            <label class="mb-1 block text-xs font-medium text-neutral-400">Description</label>
            <input
                v-model="workspaceDescription"
                type="text"
                placeholder="Optional description"
                class="w-full rounded border border-neutral-700 bg-[#2b2b2b] px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
            >
            <p v-if="modalError" class="mt-3 text-sm text-red-400">{{ modalError }}</p>
        </Modal>
    </header>
</template>

<script>
import { api, errMessage } from '../api.js'
import { store, clearAuth, loadCollections, loadEnvironments, loadWorkspaces } from '../store.js'
import Modal from './Modals.vue'

export default {
    name: 'TopBar',
    components: { Modal },
    emits: ['logged-out', 'imported', 'manage-environments'],
    data() {
        return {
            store,
            showWorkspaceModal: false,
            workspaceName: '',
            workspaceDescription: '',
            modalError: '',
        }
    },
    methods: {
        async onWorkspaceChange(value) {
            store.activeWorkspaceId = value ? Number(value) : null
            store.activeEnvironmentId = null
            await Promise.all([loadCollections(), loadEnvironments()])
            this.$emit('imported')
        },
        async createWorkspace() {
            this.modalError = ''
            try {
                const ws = await api.createWorkspace({
                    name: this.workspaceName.trim(),
                    description: this.workspaceDescription.trim() || null,
                })
                await loadWorkspaces()
                if (ws && ws.id) {
                    store.activeWorkspaceId = ws.id
                    await Promise.all([loadCollections(), loadEnvironments()])
                }
                this.showWorkspaceModal = false
                this.workspaceName = ''
                this.workspaceDescription = ''
            } catch (e) {
                this.modalError = errMessage(e)
            }
        },
        async onEnvironmentChange(value) {
            const id = value ? Number(value) : null
            store.activeEnvironmentId = id
            if (id) {
                try { await api.activateEnvironment(id) } catch (e) { /* local selection still stands */ }
            }
        },
        triggerImport() {
            this.$refs.importInput.click()
        },
        async onImportFile(event) {
            const file = event.target.files && event.target.files[0]
            event.target.value = ''
            if (!file || !store.activeWorkspaceId) return
            try {
                await api.importCollection(store.activeWorkspaceId, file)
                await loadCollections()
                this.$emit('imported')
            } catch (e) {
                alert('Import failed: ' + errMessage(e))
            }
        },
        async logout() {
            try { await api.logout() } catch (e) { /* token is cleared either way */ }
            clearAuth()
            this.$emit('logged-out')
        },
    },
}
</script>
