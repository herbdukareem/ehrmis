<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { apiMessage } from '../lib/api';
import { signIn } from '../stores/auth';
import { appState } from '../stores/app';

const router = useRouter();
const route = useRoute();
const form = reactive({ email: '', password: '', remember: false });
const error = ref('');
const busy = ref(false);
const isLocal = document.querySelector('meta[name="app-environment"]')?.content === 'local';

const submit = async () => {
    error.value = '';
    busy.value = true;

    try {
        await signIn(form);
        await router.push(route.query.redirect || '/dashboard');
    } catch (exception) {
        error.value = apiMessage(exception, 'The supplied credentials could not be verified.');
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <main class="civic-login">
        <section class="civic-login-statement">
            <img class="civic-login-logo" :src="appState.branding.logo_url" :alt="`${appState.branding.name} logo`">
            <div class="civic-eyebrow civic-eyebrow-light">Government of {{ appState.branding.state_name }}</div>
            <h1>Workforce administration, with public-service discipline.</h1>
            <p>{{ appState.branding.name }} provides one secure record for staff establishment, movement decisions, approvals, and recurrent personnel budgets.</p>
            <div class="civic-login-note">Authorised government personnel only</div>
        </section>

        <section class="civic-login-panel">
            <div class="civic-eyebrow">Secure access</div>
            <h2>Sign in to {{ appState.branding.acronym }}</h2>
            <p class="civic-muted">Use the account issued by your system administrator.</p>

            <form class="civic-form-stack" @submit.prevent="submit">
                <label class="civic-field">
                    <span>Email address</span>
                    <input v-model="form.email" type="email" autocomplete="username" required autofocus>
                </label>
                <label class="civic-field">
                    <span>Password</span>
                    <input v-model="form.password" type="password" autocomplete="current-password" required>
                </label>
                <label class="civic-check">
                    <input v-model="form.remember" type="checkbox">
                    <span>Keep this workstation signed in</span>
                </label>
                <div v-if="error" class="civic-error">{{ error }}</div>
                <button class="civic-button civic-button-primary civic-button-wide" type="submit" :disabled="busy">
                    {{ busy ? 'Verifying…' : 'Continue securely' }}
                </button>
                <RouterLink class="civic-public-link" to="/forgot-password">Forgot your password?</RouterLink>
            </form>

            <div v-if="isLocal" class="civic-local-hint">
                Local environment: <strong>admin@ehrmis.local</strong> / <strong>password</strong>
            </div>
        </section>
    </main>
</template>
