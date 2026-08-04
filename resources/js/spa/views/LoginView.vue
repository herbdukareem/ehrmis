<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppIcon from '../../Components/AppIcon.vue';
import { apiMessage } from '../lib/api';
import { defaultAuthenticatedPath, signIn } from '../stores/auth';
import { appState } from '../stores/app';
import defaultLoginBackground from '../../../images/hmb-login-background.png';
import mohCommissionerPortrait from '../../../images/murtala-muhammad-bagana.jpg';
import mohLoginAnimation from '../../../images/niger-state-moh-mis-animated.png';
import mohLoginIllustration from '../../../images/niger-state-moh-mis-animated.png';

const router = useRouter();
const route = useRoute();
const form = reactive({ email: '', password: '', remember: false });
const fieldErrors = reactive({ email: '', password: '' });
const error = ref('');
const busy = ref(false);
const showPassword = ref(false);
const prefersReducedMotion = ref(false);
const isMohMode = computed(() => appState.showMode === 'MOH');
const loginBrandTitle = computed(() => isMohMode.value ? 'Niger State Ministry of Health' : appState.branding.name);
const loginBrandSubtitle = computed(() => isMohMode.value ? 'Management Information System' : `${appState.branding.state_name} Government Platform`);
const loginHeading = computed(() => isMohMode.value ? 'Welcome to MOH MIS' : `Welcome to ${appState.branding.acronym}`);
const loginSubtitle = computed(() => isMohMode.value
    ? 'Sign in to access the connected health management platform.'
    : 'Use the account issued by your system administrator.');
const loginArtwork = computed(() => {
    if (isMohMode.value && prefersReducedMotion.value) return mohLoginIllustration;
    return isMohMode.value ? mohLoginAnimation : defaultLoginBackground;
});
const loginStatementStyle = computed(() => ({
    backgroundImage: `url(${loginArtwork.value})`,
}));

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
let motionQuery;

const handleMotionPreference = (query) => {
    prefersReducedMotion.value = query.matches;
};

const clearFieldError = (field) => {
    fieldErrors[field] = '';
};

const validateForm = () => {
    fieldErrors.email = '';
    fieldErrors.password = '';

    const email = form.email.trim();
    let valid = true;

    if (!email) {
        fieldErrors.email = 'Enter your official email address.';
        valid = false;
    } else if (!emailPattern.test(email)) {
        fieldErrors.email = 'Enter a valid email address.';
        valid = false;
    }

    if (!form.password) {
        fieldErrors.password = 'Enter your password to continue.';
        valid = false;
    }

    return valid;
};

const submit = async () => {
    error.value = '';

    if (!validateForm()) {
        return;
    }

    form.email = form.email.trim();
    busy.value = true;

    try {
        await signIn(form);
        await router.push(route.query.redirect || defaultAuthenticatedPath());
    } catch (exception) {
        error.value = apiMessage(exception, 'The supplied credentials could not be verified.');
    } finally {
        busy.value = false;
    }
};

onMounted(() => {
    motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    handleMotionPreference(motionQuery);
    if (typeof motionQuery.addEventListener === 'function') {
        motionQuery.addEventListener('change', handleMotionPreference);
    } else {
        motionQuery.addListener(handleMotionPreference);
    }
});

onBeforeUnmount(() => {
    if (!motionQuery) return;

    if (typeof motionQuery.removeEventListener === 'function') {
        motionQuery.removeEventListener('change', handleMotionPreference);
    } else {
        motionQuery.removeListener(handleMotionPreference);
    }
});
</script>

<template>
    <main class="civic-login">
        <section
            class="civic-login-statement civic-login-statement-image"
            :class="{ 'civic-login-statement-focus': isMohMode }"
            :aria-label="`${appState.branding.name} Human Resource Management Information System`"
            :style="loginStatementStyle"
        />

        <section class="civic-login-panel">
            <div class="civic-login-panel-shell">
                <div class="civic-login-card">
                    <div class="civic-login-brand">
                        <img class="civic-login-brand-logo" :src="appState.branding.logo_url" :alt="`${appState.branding.name} logo`">
                        <div>
                            <div class="civic-login-brand-title">{{ loginBrandTitle }}</div>
                            <div class="civic-login-brand-subtitle">{{ loginBrandSubtitle }}</div>
                        </div>
                    </div>

                    <div class="civic-login-card-copy">
                        <div class="civic-eyebrow">Secure access</div>
                        <h1>{{ loginHeading }}</h1>
                        <p class="civic-muted">{{ loginSubtitle }}</p>
                    </div>

                    <figure v-if="isMohMode" class="civic-login-commissioner">
                        <img
                            :src="mohCommissionerPortrait"
                            alt="Portrait of Dr. Murtala Muhammad Bagana, Honourable Commissioner for Health, Niger State"
                        >
                        <figcaption>
                            <div class="civic-login-commissioner-label">Honourable Commissioner for Health</div>
                            <strong>Dr. Murtala Muhammad Bagana</strong>
                            <span>Niger State Ministry of Health</span>
                        </figcaption>
                    </figure>

                    <form class="civic-form-stack civic-login-form" novalidate @submit.prevent="submit">
                        <label class="civic-field civic-login-field">
                            <span>Email address</span>
                            <div class="civic-login-input-wrap" :class="{ 'is-invalid': fieldErrors.email }">
                                <AppIcon name="envelope" class="civic-login-input-icon" />
                                <input
                                    v-model="form.email"
                                    type="email"
                                    autocomplete="username"
                                    required
                                    autofocus
                                    @input="clearFieldError('email')"
                                >
                            </div>
                            <small v-if="fieldErrors.email" class="civic-field-error">{{ fieldErrors.email }}</small>
                        </label>

                        <label class="civic-field civic-login-field">
                            <span>Password</span>
                            <div class="civic-login-input-wrap" :class="{ 'is-invalid': fieldErrors.password }">
                                <AppIcon name="lockClosed" class="civic-login-input-icon" />
                                <input
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    autocomplete="current-password"
                                    required
                                    @input="clearFieldError('password')"
                                >
                                <button
                                    type="button"
                                    class="civic-login-visibility"
                                    :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                    @click="showPassword = !showPassword"
                                >
                                    <AppIcon :name="showPassword ? 'eyeSlash' : 'eye'" />
                                </button>
                            </div>
                            <small v-if="fieldErrors.password" class="civic-field-error">{{ fieldErrors.password }}</small>
                        </label>

                        <div class="civic-login-assist">
                            <label class="civic-check civic-check-card civic-login-check">
                                <input v-model="form.remember" type="checkbox">
                                <span class="civic-check-copy">
                                    <strong>Keep me signed in on this device</strong>
                                </span>
                            </label>
                            <RouterLink class="civic-public-link civic-login-link" to="/forgot-password">Forgot your password?</RouterLink>
                        </div>

                        <div v-if="error" class="civic-error civic-login-error">{{ error }}</div>

                        <button class="civic-button civic-button-primary civic-button-wide civic-login-submit" type="submit" :disabled="busy">
                            <span>{{ busy ? 'Signing in securely...' : 'Sign in securely' }}</span>
                            <AppIcon :name="busy ? 'lockClosed' : 'chevronRight'" />
                        </button>
                    </form>
                </div>

                <div class="civic-login-footer">
                    <span>Authorized users only</span>
                    <span>Need help? Contact System Support</span>
                    <span>Privacy Policy · Version 1.0</span>
                </div>
            </div>
        </section>
    </main>
</template>
