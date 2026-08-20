import axios from 'axios';
import { appState, setPageError } from '../stores/app';
import { applyCsrfToken, syncCsrfCookieToken } from './csrf';

export const api = axios.create({
    baseURL: '/api',
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

api.interceptors.request.use((config) => {
    appState.pendingRequests += 1;
    return applyCsrfToken(config);
});

api.interceptors.response.use(
    (response) => {
        appState.pendingRequests = Math.max(0, appState.pendingRequests - 1);
        syncCsrfCookieToken();
        return response;
    },
    (error) => {
        appState.pendingRequests = Math.max(0, appState.pendingRequests - 1);
        syncCsrfCookieToken();
        if (error?.response?.status === 403 && error?.config?.method?.toLowerCase() === 'get') {
            setPageError(apiMessage(error, 'This action is unauthorized.'));
        }

        return Promise.reject(error);
    },
);

export function apiMessage(error, fallback = 'The request could not be completed.') {
    return error?.response?.data?.message
        ?? Object.values(error?.response?.data?.errors ?? {})?.flat()?.[0]
        ?? fallback;
}
