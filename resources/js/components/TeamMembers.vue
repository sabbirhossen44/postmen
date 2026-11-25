<template>
    <Modal
        :open="open"
        :title="'Team — ' + (workspace ? workspace.name : '')"
        confirm-label="Done"
        @close="$emit('close')"
        @confirm="$emit('close')"
    >
        <div v-if="!workspace || workspace.type !== 'team'" class="text-sm pm-text-2">
            This is a personal workspace. Only its owner can see it — switch it to a team workspace to invite members.
        </div>
        <div v-else>
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide pm-muted">Members</h4>
            <ul class="mb-4">
                <li v-for="member in store.teamMembers" :key="member.user_id" class="mb-2 flex items-center gap-2 text-sm">
                    <span class="min-w-0 flex-1 truncate pm-text">
                        {{ member.name }}
                        <span class="pm-faint">{{ member.email }}</span>
                    </span>
                    <span v-if="member.is_owner" class="rounded border pm-border px-2 py-0.5 text-xs pm-muted">owner</span>
                    <select
                        v-else-if="canManage"
                        :value="member.role"
                        @change="changeRole(member, $event.target.value)"
                        class="rounded border pm-input px-1 py-0.5 text-xs outline-none"
                    >
                        <option value="admin">admin</option>
                        <option value="editor">editor</option>
                        <option value="viewer">viewer</option>
                    </select>
                    <span v-else class="rounded border pm-border px-2 py-0.5 text-xs pm-muted">{{ member.role }}</span>
                    <button
                        v-if="canManage && !member.is_owner && member.user_id !== store.user?.id"
                        type="button"
                        @click="removeMember(member)"
                        class="rounded px-2 py-0.5 text-xs text-red-400 hover:bg-neutral-700"
                    >
                        Remove
                    </button>
                </li>
            </ul>

            <div v-if="canManage">
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide pm-muted">Invite member</h4>
                <div class="mb-1 flex gap-2">
                    <input
                        v-model="inviteEmail"
                        type="email"
                        placeholder="teammate@example.com"
                        class="min-w-0 flex-1 rounded border pm-input px-3 py-1.5 text-sm outline-none focus:border-[#FF6C37]"
                    >
                    <select v-model="inviteRole" class="rounded border pm-input px-2 py-1.5 text-sm outline-none">
                        <option value="viewer">viewer</option>
                        <option value="editor">editor</option>
                        <option value="admin">admin</option>
                    </select>
                    <button
                        type="button"
                        @click="invite"
                        :disabled="!inviteEmail.trim()"
                        class="rounded bg-[#FF6C37] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#e85a2a] disabled:opacity-50"
                    >
                        Invite
                    </button>
                </div>
                <p v-if="inviteError" class="mb-3 text-sm text-red-400">{{ inviteError }}</p>
                <p v-if="inviteMessage" class="mb-3 text-sm text-green-400">{{ inviteMessage }}</p>
                <div v-if="inviteLink" class="mb-3 flex items-center gap-2 rounded border pm-border px-2 py-1.5">
                    <span class="min-w-0 flex-1 truncate font-mono text-xs pm-text-2">{{ inviteLink }}</span>
                    <button type="button" @click="copyInviteLink(inviteLink)" class="pm-btn pm-btn-ghost pm-btn-sm shrink-0">Copy invite link</button>
                </div>

                <div v-if="store.teamInvitations.length > 0">
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide pm-muted">Pending invitations</h4>
                    <ul class="mb-4">
                        <li v-for="invitation in store.teamInvitations" :key="invitation.id" class="mb-2 flex items-center gap-2 text-sm">
                            <span class="min-w-0 flex-1 truncate pm-text">
                                {{ invitation.email }}
                                <span class="pm-faint">{{ invitation.role }}</span>
                            </span>
                            <button
                                type="button"
                                @click="copyInviteLink(inviteLinkFor(invitation))"
                                class="rounded px-2 py-0.5 text-xs pm-text-2 hover:pm-active"
                            >
                                Copy link
                            </button>
                            <button
                                type="button"
                                @click="declineInvitation(invitation)"
                                class="rounded px-2 py-0.5 text-xs text-red-400 hover:bg-neutral-700"
                            >
                                Cancel
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="!isOwner">
                <button
                    type="button"
                    @click="leave"
                    class="mb-4 rounded border border-red-500/50 px-3 py-1.5 text-sm text-red-400 hover:bg-red-500/10"
                >
                    Leave workspace
                </button>
            </div>

            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide pm-muted">Activity</h4>
            <ul v-if="store.teamActivity.length > 0">
                <li v-for="entry in store.teamActivity" :key="entry.id" class="mb-1.5 text-sm pm-text-2">
                    {{ entry.description }}
                    <span class="pm-faint">— {{ entry.user?.name || 'Someone' }}, {{ entry.created_at }}</span>
                </li>
            </ul>
            <p v-else class="text-sm pm-faint">No activity yet.</p>
        </div>
    </Modal>
</template>

<script>
import { api, errMessage } from '../api.js'
import { store, loadTeam } from '../store.js'
import { toastSuccess, toastError } from '../utils/toast.js'
import Modal from './Modals.vue'

export default {
    name: 'TeamMembers',
    components: { Modal },
    props: {
        open: { type: Boolean, default: false },
        workspace: { type: Object, default: null },
    },
    emits: ['close', 'left'],
    data() {
        return {
            store,
            inviteEmail: '',
            inviteRole: 'viewer',
            inviteError: '',
            inviteMessage: '',
            inviteLink: '',
        }
    },
    computed: {
        canManage() {
            return this.workspace && (this.workspace.role === 'owner' || this.workspace.role === 'admin')
        },
        isOwner() {
            return this.workspace && this.workspace.role === 'owner'
        },
    },
    watch: {
        open(value) {
            if (value && this.workspace) {
                this.inviteError = ''
                this.inviteMessage = ''
                this.inviteLink = ''
                this.inviteEmail = ''
                this.refresh()
            }
        },
    },
    methods: {
        async refresh() {
            await loadTeam(this.workspace.id)
        },
        inviteLinkFor(invitation) {
            return window.location.origin + '/?invite=' + invitation.token
        },
        async copyInviteLink(link) {
            try {
                await navigator.clipboard.writeText(link)
                toastSuccess('Invite link copied.')
            } catch (e) {
                toastError('Could not copy the link: ' + errMessage(e))
            }
        },
        async invite() {
            this.inviteError = ''
            this.inviteMessage = ''
            this.inviteLink = ''
            try {
                const invitation = await api.inviteMember(this.workspace.id, {
                    email: this.inviteEmail.trim(),
                    role: this.inviteRole,
                })
                this.inviteMessage = 'Invitation sent to ' + this.inviteEmail.trim() + '.'
                if (invitation && invitation.token) this.inviteLink = this.inviteLinkFor(invitation)
                this.inviteEmail = ''
                await this.refresh()
            } catch (err) {
                this.inviteError = errMessage(err)
            }
        },
        async changeRole(member, role) {
            try {
                await api.updateMemberRole(this.workspace.id, member.user_id, role)
                await this.refresh()
            } catch (err) {
                this.inviteError = errMessage(err)
            }
        },
        async removeMember(member) {
            try {
                await api.removeMember(this.workspace.id, member.user_id)
                await this.refresh()
            } catch (err) {
                this.inviteError = errMessage(err)
            }
        },
        async declineInvitation(invitation) {
            try {
                await api.declineInvitation(invitation.id)
                await this.refresh()
            } catch (err) {
                this.inviteError = errMessage(err)
            }
        },
        async leave() {
            try {
                await api.leaveWorkspace(this.workspace.id)
                this.$emit('left')
                this.$emit('close')
            } catch (err) {
                this.inviteError = errMessage(err)
            }
        },
    },
}
</script>
