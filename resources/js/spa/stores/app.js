import { reactive } from 'vue';
import axios from 'axios';

const appShowMode = document.querySelector('meta[name="app-show-mode"]')?.content?.trim().toUpperCase() ?? '';
const initialCsrfToken = document.querySelector('meta[name="csrf-token"]')?.content?.trim() ?? '';

export const appState = reactive({
    pendingRequests: 0,
    pageError: '',
    toastSeed: 0,
    toasts: [],
    csrfToken: initialCsrfToken,
    showMode: appShowMode,
    branding: {
        scope: 'platform',
        state_name: 'Niger State',
        platform_name: 'eHRMIS',
        name: 'eHRMIS',
        acronym: 'eHRMIS',
        logo_url: '/storage/images/niger-state-logo.jpg',
    },
});

export function setBranding(branding) {
    appState.branding = {
        ...appState.branding,
        ...branding,
    };
}

export function setCsrfToken(token) {
    if (typeof token !== 'string' || token.trim() === '') {
        return appState.csrfToken;
    }

    appState.csrfToken = token;

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        csrfMeta.setAttribute('content', token);
    }

    return appState.csrfToken;
}

export function syncCsrfTokenFromCookie() {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    if (!cookie) {
        return appState.csrfToken;
    }

    return setCsrfToken(decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)));
}

export async function loadPublicContext() {
    const response = await axios.get('/api/public-context', { headers: { Accept: 'application/json' } });
    const { csrf_token: csrfToken, ...branding } = response.data?.data ?? {};
    setBranding(branding);
    setCsrfToken(csrfToken ?? syncCsrfTokenFromCookie());
    return appState.branding;
}

const toneDefaults = {
    success: { title: 'Success', icon: 'checkCircle', timeout: 3200 },
    error: { title: 'Action blocked', icon: 'xCircle', timeout: 4200 },
    warning: { title: 'Attention needed', icon: 'exclamation', timeout: 4800 },
    info: { title: 'Notice', icon: 'info', timeout: 3600 },
};

function normalizeToast(messageOrOptions, tone, timeout) {
    const options = typeof messageOrOptions === 'object' && messageOrOptions !== null
        ? messageOrOptions
        : { message: messageOrOptions, tone, timeout };

    const resolvedTone = options.tone ?? tone ?? 'success';
    const defaults = toneDefaults[resolvedTone] ?? toneDefaults.info;
    const resolvedTimeout = options.timeout ?? timeout ?? defaults.timeout;

    return {
        title: options.title ?? defaults.title,
        message: options.message ?? '',
        tone: resolvedTone,
        icon: options.icon ?? defaults.icon,
        timeout: resolvedTimeout,
        dismissLabel: options.dismissLabel ?? 'Close alert',
    };
}

export function pushToast(messageOrOptions, tone = 'success', timeout = 3200) {
    const toast = normalizeToast(messageOrOptions, tone, timeout);
    const id = ++appState.toastSeed;
    appState.toasts.push({ id, ...toast });

    if (toast.timeout > 0) {
        window.setTimeout(() => removeToast(id), toast.timeout);
    }

    return id;
}

export function removeToast(id) {
    appState.toasts = appState.toasts.filter((toast) => toast.id !== id);
}

export function setPageError(message) {
    appState.pageError = message;
}

export function clearPageError() {
    appState.pageError = '';
}
