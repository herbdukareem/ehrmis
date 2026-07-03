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

export function hasAnyPermission(permissions = []) {
    if (permissions.length === 0) {
        return true;
    }

    return permissions.some((permission) => can(permission));
}

export function defaultAuthenticatedPath() {
    const fallbackRoutes = [
        { path: '/dashboard', permissions: ['view-reports'] },
        { path: '/staff', permissions: ['view-staff'] },
        { path: '/legacy-staff-imports', permissions: ['view-staff-imports', 'import-staff', 'review-staff-imports', 'resolve-staff-import-issues', 'publish-staff-imports', 'publish-own-mda-staff-imports'] },
        { path: '/movement-workbooks', permissions: ['view-movement-sheets', 'create-movement-sheets', 'approve-movement-sheets'] },
        { path: '/promotion-cycles', permissions: ['view-promotions'] },
        { path: '/posting-requests', permissions: ['view-postings'] },
        { path: '/budget-workbooks', permissions: ['view-budgets', 'create-budgets', 'approve-budgets'] },
        { path: '/reports', permissions: ['view-reports', 'export-reports'] },
        { path: '/settings', permissions: ['manage-platform-settings', 'manage-mda-settings'] },
        { path: '/setup-management', permissions: ['manage-departments', 'manage-stations', 'manage-cadres', 'manage-ranks', 'manage-allowance-types', 'manage-salary-scales', 'manage-qualification-types', 'manage-salary-structure'] },
        { path: '/access-management', permissions: ['manage-users', 'manage-roles'] },
    ];

    return fallbackRoutes.find((route) => hasAnyPermission(route.permissions))?.path ?? '/dashboard';
}
