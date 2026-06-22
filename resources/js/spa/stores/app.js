import { reactive } from 'vue';
import axios from 'axios';

export const appState = reactive({
    pendingRequests: 0,
    toastSeed: 0,
    toasts: [],
    branding: {
        scope: 'platform',
        state_name: 'Niger State',
        platform_name: 'eHRMIS',
        name: 'eHRMIS',
        acronym: 'eHRMIS',
        logo_url: '/storage/images/niger-state-logo.jpg',
    },
});

export async function loadPublicContext() {
    const response = await axios.get('/api/public-context', { headers: { Accept: 'application/json' } });
    appState.branding = response.data.data;
    return appState.branding;
}

export function pushToast(message, tone = 'success', timeout = 3200) {
    const id = ++appState.toastSeed;
    appState.toasts.push({ id, message, tone });

    if (timeout > 0) {
        window.setTimeout(() => removeToast(id), timeout);
    }

    return id;
}

export function removeToast(id) {
    appState.toasts = appState.toasts.filter((toast) => toast.id !== id);
}
