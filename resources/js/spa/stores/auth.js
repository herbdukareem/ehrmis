import { reactive } from 'vue';
import { api } from '../lib/api';

export const auth = reactive({
    user: null,
    ready: false,
});

export async function loadSession() {
    try {
        const response = await api.get('/me');
        auth.user = response.data.data;
    } catch (error) {
        if (error.response?.status !== 401) {
            throw error;
        }

        auth.user = null;
    } finally {
        auth.ready = true;
    }

    return auth.user;
}

export async function signIn(credentials) {
    await api.post('/login', credentials);
    return loadSession();
}

export async function signOut() {
    await api.post('/logout');
    auth.user = null;
}

export function can(permission) {
    return auth.user?.permissions?.includes(permission) ?? false;
}
