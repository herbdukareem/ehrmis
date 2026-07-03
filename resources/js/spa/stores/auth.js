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

export function hasModule(moduleCode) {
    return auth.user?.enabled_modules?.includes(moduleCode)
        || auth.user?.modules?.some((module) => module.code === moduleCode && module.enabled !== false)
        || false;
}

export function canAccess(moduleCode, permission) {
    return hasModule(moduleCode) && can(permission);
}

export function hasAnyPermission(permissions = []) {
    if (permissions.length === 0) {
        return true;
    }

    return permissions.some((permission) => can(permission));
}

export function hasAnyAccess(moduleCode, permissions = []) {
    if (!hasModule(moduleCode)) {
        return false;
    }

    return hasAnyPermission(permissions);
}

export function defaultAuthenticatedPath() {
    const fallbackRoutes = [
        { path: '/dashboard', module: 'dashboards_analytics', permissions: ['view-reports'] },
        { path: '/staff', module: 'staff_registry', permissions: ['view-staff'] },
        { path: '/legacy-staff-imports', module: 'legacy_import', permissions: ['view-staff-imports', 'import-staff', 'review-staff-imports', 'resolve-staff-import-issues', 'publish-staff-imports', 'publish-own-mda-staff-imports'] },
        { path: '/movement-workbooks', module: 'movement_budget', permissions: ['view-movement-sheets', 'create-movement-sheets', 'approve-movement-sheets'] },
        { path: '/promotion-cycles', permissions: ['view-promotions'] },
        { path: '/posting-requests', permissions: ['view-postings'] },
        { path: '/budget-workbooks', module: 'movement_budget', permissions: ['view-budgets', 'create-budgets', 'approve-budgets'] },
        { path: '/service-reports', module: 'service_reporting', permissions: ['view-service-reports'] },
        { path: '/reports', module: 'dashboards_analytics', permissions: ['view-reports', 'export-reports'] },
        { path: '/settings', module: 'settings', permissions: ['manage-platform-settings', 'manage-mda-settings'] },
        { path: '/setup-management', permissions: ['manage-departments', 'manage-stations', 'manage-cadres', 'manage-ranks', 'manage-allowance-types', 'manage-salary-scales', 'manage-qualification-types', 'manage-salary-structure'] },
        { path: '/access-management', module: 'access_management', permissions: ['manage-users', 'manage-roles'] },
    ];

    return fallbackRoutes.find((route) => route.module
        ? hasAnyAccess(route.module, route.permissions)
        : hasAnyPermission(route.permissions))?.path ?? '/dashboard';
}
