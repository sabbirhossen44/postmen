<template>
    <div class="flex min-h-screen items-center justify-center pm-canvas px-4">
        <div class="w-full max-w-md rounded-lg border pm-border pm-panel p-6 shadow-xl">
            <div class="mb-6 text-center">
                <div class="text-2xl font-bold pm-text">
                    Postmen<span class="text-[#FF6C37]">.</span>
                </div>
                <p class="mt-1 text-sm pm-muted">Sign in to your workspace</p>
            </div>

            <div class="mb-4 flex rounded border pm-border">
                <button
                    type="button"
                    @click="mode = 'login'"
                    :class="mode === 'login' ? 'pm-accent-bg text-white' : 'pm-text-2 hover:pm-active'"
                    class="flex-1 rounded-l px-3 py-2 text-sm font-medium"
                >
                    Login
                </button>
                <button
                    type="button"
                    @click="mode = 'register'"
                    :class="mode === 'register' ? 'pm-accent-bg text-white' : 'pm-text-2 hover:pm-active'"
                    class="flex-1 rounded-r px-3 py-2 text-sm font-medium"
                >
                    Register
                </button>
            </div>

            <form @submit.prevent="submit">
                <div v-if="mode === 'register'" class="mb-3">
                    <label class="mb-1 block text-xs font-medium pm-muted">Name</label>
                    <input
                        v-model="name"
                        type="text"
                        required
                        placeholder="Your name"
                        class="w-full rounded border pm-input px-3 py-2 text-sm pm-text placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    >
                </div>
                <div class="mb-3">
                    <label class="mb-1 block text-xs font-medium pm-muted">Email</label>
                    <input
                        v-model="email"
                        type="email"
                        required
                        placeholder="you@example.com"
                        class="w-full rounded border pm-input px-3 py-2 text-sm pm-text placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    >
                </div>
                <div class="mb-3">
                    <label class="mb-1 block text-xs font-medium pm-muted">Password</label>
                    <input
                        v-model="password"
                        type="password"
                        required
                        placeholder="Password"
                        class="w-full rounded border pm-input px-3 py-2 text-sm pm-text placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    >
                </div>
                <div v-if="mode === 'register'" class="mb-4">
                    <label class="mb-1 block text-xs font-medium pm-muted">Confirm password</label>
                    <input
                        v-model="passwordConfirmation"
                        type="password"
                        required
                        placeholder="Repeat password"
                        class="w-full rounded border pm-input px-3 py-2 text-sm pm-text placeholder-neutral-500 outline-none focus:border-[#FF6C37]"
                    >
                </div>

                <p v-if="error" class="mb-3 rounded border border-red-900 bg-red-950 px-3 py-2 text-sm text-red-300">
                    {{ error }}
                </p>

                <button
                    type="submit"
                    :disabled="busy"
                    class="w-full rounded pm-accent-bg px-3 py-2 text-sm font-semibold text-white hover:bg-[#e85a2a] disabled:opacity-50"
                >
                    {{ busy ? 'Please wait…' : (mode === 'login' ? 'Login' : 'Create account') }}
                </button>
            </form>
        </div>
    </div>
</template>

<script>
import { api, errMessage } from '../api.js'
import { setAuth } from '../store.js'

export default {
    name: 'AuthView',
    emits: ['authenticated'],
    data() {
        return {
            mode: 'login',
            name: '',
            email: '',
            password: '',
            passwordConfirmation: '',
            error: '',
            busy: false,
        }
    },
    methods: {
        async submit() {
            this.error = ''
            this.busy = true
            try {
                let result
                if (this.mode === 'login') {
                    result = await api.login({ email: this.email, password: this.password })
                } else {
                    result = await api.register({
                        name: this.name,
                        email: this.email,
                        password: this.password,
                        password_confirmation: this.passwordConfirmation,
                    })
                }
                if (!result || !result.token) {
                    throw new Error('No token returned by the server')
                }
                setAuth(result.token, result.user)
                this.$emit('authenticated')
            } catch (e) {
                this.error = errMessage(e)
            } finally {
                this.busy = false
            }
        },
    },
}
</script>
