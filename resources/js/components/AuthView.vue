<template>
    <div class="flex min-h-screen items-center justify-center pm-canvas px-4">
        <div class="w-full max-w-md rounded-lg border pm-border pm-panel p-6 shadow-xl">
            <div class="mb-6 text-center">
                <div class="text-2xl font-bold pm-text">
                    Postmen<span class="pm-accent-text">.</span>
                </div>
                <p class="mt-1 text-sm pm-muted">Sign in to your workspace</p>
            </div>

            <div class="mb-4 flex rounded border pm-border">
                <button
                    type="button"
                    @click="mode = 'login'"
                    :class="mode === 'login' ? 'pm-btn-primary' : 'pm-text-2 hover:pm-active'"
                    class="pm-btn pm-btn-seg-l flex-1"
                >
                    Login
                </button>
                <button
                    type="button"
                    @click="mode = 'register'"
                    :class="mode === 'register' ? 'pm-btn-primary' : 'pm-text-2 hover:pm-active'"
                    class="pm-btn pm-btn-seg-r flex-1"
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
                        class="pm-field w-full"
                    >
                </div>
                <div class="mb-3">
                    <label class="mb-1 block text-xs font-medium pm-muted">Email</label>
                    <input
                        v-model="email"
                        type="email"
                        required
                        placeholder="you@example.com"
                        class="pm-field w-full"
                    >
                </div>
                <div class="mb-3">
                    <label class="mb-1 block text-xs font-medium pm-muted">Password</label>
                    <input
                        v-model="password"
                        type="password"
                        required
                        placeholder="Password"
                        class="pm-field w-full"
                    >
                </div>
                <div v-if="mode === 'register'" class="mb-4">
                    <label class="mb-1 block text-xs font-medium pm-muted">Confirm password</label>
                    <input
                        v-model="passwordConfirmation"
                        type="password"
                        required
                        placeholder="Repeat password"
                        class="pm-field w-full"
                    >
                </div>

                <p v-if="error" class="mb-3 rounded border border-red-900 bg-red-950 px-3 py-2 text-sm text-red-300">
                    {{ error }}
                </p>

                <button
                    type="submit"
                    :disabled="busy"
                    class="pm-btn pm-btn-primary w-full"
                >
                    <span v-if="busy" class="pm-spinner" aria-hidden="true"></span>
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
