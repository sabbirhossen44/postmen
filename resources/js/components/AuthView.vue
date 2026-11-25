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
                    <div class="relative">
                        <input
                            v-model="password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            placeholder="Password"
                            class="pm-field w-full pr-14"
                        >
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-xs pm-muted hover:pm-text"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                        >
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                    <ul v-if="mode === 'register'" class="mt-2 list-disc pl-5 text-xs pm-faint">
                        <li>At least 8 characters</li>
                        <li>Must match the confirmation below</li>
                    </ul>
                </div>
                <div v-if="mode === 'register'" class="mb-4">
                    <label class="mb-1 block text-xs font-medium pm-muted">Confirm password</label>
                    <div class="relative">
                        <input
                            v-model="passwordConfirmation"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            placeholder="Repeat password"
                            class="pm-field w-full pr-14"
                        >
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-xs pm-muted hover:pm-text"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                        >
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </div>

                <div v-if="mode === 'login'" class="mb-3 text-right">
                    <button type="button" @click="showForgotNote = !showForgotNote" class="text-xs pm-accent-text hover:underline">
                        Forgot your password?
                    </button>
                </div>
                <p v-if="showForgotNote" class="mb-3 rounded border pm-border px-3 py-2 text-xs pm-muted">
                    Password reset isn't available in Postmen yet — ask a workspace admin to reset your password.
                </p>

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
            showPassword: false,
            showForgotNote: false,
            error: '',
            busy: false,
        }
    },
    methods: {
        // Plain-language auth errors: a mistyped password and a dead
        // server should not look the same.
        friendlyAuthError(e) {
            const status = e.response && e.response.status
            if (!e.response) return "Can't reach the server — check your connection and try again."
            if (status === 401) return 'Wrong email or password. Check them and try again.'
            if (status === 422) {
                const message = errMessage(e)
                if (/credential|password|unauthenticated/i.test(message)) {
                    return 'Wrong email or password. Check them and try again.'
                }
                return message
            }
            if (status === 429) return 'Too many tries — wait a moment and try again.'
            if (status >= 500) return 'Something went wrong on the server — try again in a bit.'
            return errMessage(e)
        },
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
                this.error = this.friendlyAuthError(e)
            } finally {
                this.busy = false
            }
        },
    },
}
</script>
