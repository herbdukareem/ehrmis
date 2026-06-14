import { reactive } from 'vue';
import axios from 'axios';

export const appState = reactive({
    pendingRequests: 0,
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
