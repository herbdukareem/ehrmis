import { ensureCsrfCookie } from '../stores/app';

const readOnlyMethods = new Set(['get', 'head', 'options']);

export function isReadOnlyRequest(config) {
    return readOnlyMethods.has((config?.method ?? 'get').toLowerCase());
}

export function refreshCsrfCookie(force = false) {
    return ensureCsrfCookie(force);
}
