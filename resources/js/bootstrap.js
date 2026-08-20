import axios from 'axios';
import { applyCsrfToken, syncCsrfCookieToken } from './spa/lib/csrf';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.headers.common.Accept = 'application/json';
window.axios.defaults.withXSRFToken = true;

window.axios.interceptors.request.use((config) => applyCsrfToken(config));
window.axios.interceptors.response.use(
    (response) => {
        syncCsrfCookieToken();
        return response;
    },
    (error) => {
        syncCsrfCookieToken();
        return Promise.reject(error);
    },
);
