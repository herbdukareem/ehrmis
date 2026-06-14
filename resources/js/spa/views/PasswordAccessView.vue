<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { apiMessage } from '../lib/api';
import { appState } from '../stores/app';

const props = defineProps({ mode: { type: String, required: true } });
const route = useRoute();
const router = useRouter();
const form = reactive({ email: route.query.email ?? '', token: route.params.token ?? '', password: '', password_confirmation: '' });
const feedback = ref('');
const error = ref('');
const submit = async () => {
    feedback.value = ''; error.value = '';
    try {
        await window.axios.post(props.mode === 'forgot' ? '/forgot-password' : '/reset-password', form);
        if (props.mode === 'reset') return router.push('/login');
        feedback.value = 'If the account exists, a password reset link has been sent.';
    } catch (exception) { error.value = apiMessage(exception); }
};
</script>

<template>
    <main class="civic-login">
        <section class="civic-login-statement">
            <img class="civic-login-logo" :src="appState.branding.logo_url" :alt="`${appState.branding.name} logo`">
            <div class="civic-eyebrow civic-eyebrow-light">Government of {{ appState.branding.state_name }}</div>
            <h1>Secure account recovery.</h1>
            <p>{{ appState.branding.name }} account recovery is protected and recorded as part of the official access process.</p>
            <div class="civic-login-note">Authorised government personnel only</div>
        </section>
        <section class="civic-login-panel">
            <div class="civic-eyebrow">Secure access</div>
            <h2>{{ mode === 'forgot' ? 'Request a reset link' : 'Choose a new password' }}</h2>
            <p class="civic-muted">{{ mode === 'forgot' ? 'Enter your official account email.' : 'Use a strong password that is not shared with another service.' }}</p>
            <form class="civic-form-stack" @submit.prevent="submit">
                <label class="civic-field"><span>Email address</span><input v-model="form.email" type="email" required></label>
                <template v-if="mode === 'reset'">
                    <label class="civic-field"><span>New password</span><input v-model="form.password" type="password" required></label>
                    <label class="civic-field"><span>Confirm password</span><input v-model="form.password_confirmation" type="password" required></label>
                </template>
                <div v-if="feedback" class="civic-feedback">{{ feedback }}</div>
                <div v-if="error" class="civic-error">{{ error }}</div>
                <button class="civic-button civic-button-primary civic-button-wide">{{ mode === 'forgot' ? 'Send reset link' : 'Reset password' }}</button>
                <RouterLink class="civic-public-link" to="/login">Return to sign in</RouterLink>
            </form>
        </section>
    </main>
</template>
