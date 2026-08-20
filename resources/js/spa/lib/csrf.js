import { appState, syncCsrfTokenFromCookie } from '../stores/app';

const readOnlyMethods = new Set(['get', 'head', 'options']);

export function applyCsrfToken(config) {
    const method = (config?.method ?? 'get').toLowerCase();

    if (readOnlyMethods.has(method)) {
        return config;
    }

    const token = appState.csrfToken || syncCsrfTokenFromCookie();

    if (!token) {
        return config;
    }

    const headers = config.headers ?? {};

    if (typeof headers.set === 'function') {
        if (!headers.has('X-CSRF-TOKEN')) {
            headers.set('X-CSRF-TOKEN', token);
        }
    } else if (headers['X-CSRF-TOKEN'] == null) {
        headers['X-CSRF-TOKEN'] = token;
    }

    config.headers = headers;

    return config;
}

export function syncCsrfCookieToken() {
    return syncCsrfTokenFromCookie();
}
