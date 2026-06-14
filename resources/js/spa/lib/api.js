import axios from 'axios';
import { appState } from '../stores/app';

export const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

if (csrfToken) {
    api.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

api.interceptors.request.use((config) => {
    appState.pendingRequests += 1;
    return config;
});

api.interceptors.response.use(
    (response) => {
        appState.pendingRequests = Math.max(0, appState.pendingRequests - 1);
        return response;
    },
    (error) => {
        appState.pendingRequests = Math.max(0, appState.pendingRequests - 1);
        return Promise.reject(error);
    },
);

export function apiMessage(error, fallback = 'The request could not be completed.') {
    return error?.response?.data?.message
        ?? Object.values(error?.response?.data?.errors ?? {})?.flat()?.[0]
        ?? fallback;
}
