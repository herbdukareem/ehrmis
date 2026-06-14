import { createRouter, createWebHistory } from 'vue-router';
import { auth, loadSession } from './stores/auth';
import { appState } from './stores/app';

const routes = [
    { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { guest: true } },
    { path: '/forgot-password', name: 'password.request', component: () => import('./views/PasswordAccessView.vue'), props: { mode: 'forgot' }, meta: { guest: true } },
    { path: '/reset-password/:token', name: 'password.reset', component: () => import('./views/PasswordAccessView.vue'), props: { mode: 'reset' }, meta: { guest: true } },
    { path: '/', redirect: '/dashboard' },
    { path: '/dashboard', name: 'dashboard', component: () => import('./views/DashboardView.vue') },
    { path: '/staff', name: 'staff.index', component: () => import('./views/StaffIndexView.vue') },
    { path: '/staff/:id', name: 'staff.show', component: () => import('./views/StaffShowView.vue') },
    { path: '/staff/:id/edit', name: 'staff.edit', component: () => import('./views/StaffEditView.vue') },
    { path: '/legacy-staff-imports', name: 'imports.index', component: () => import('./views/ImportIndexView.vue') },
    { path: '/legacy-staff-imports/:id', name: 'imports.show', component: () => import('./views/ImportShowView.vue') },
    { path: '/legacy-staff-imports/:batchId/rows/:rowId', name: 'imports.rows.show', component: () => import('./views/ImportRowView.vue') },
    { path: '/movement-workbooks', name: 'movement.index', component: () => import('./views/MovementIndexView.vue') },
    { path: '/movement-workbooks/:id', name: 'movement.show', component: () => import('./views/MovementShowView.vue') },
    { path: '/budget-workbooks', name: 'budgets.index', component: () => import('./views/BudgetIndexView.vue') },
    { path: '/budget-workbooks/:id', name: 'budgets.show', component: () => import('./views/BudgetShowView.vue') },
    { path: '/reports', name: 'reports', component: () => import('./views/ReportsView.vue') },
    { path: '/settings', name: 'settings', component: () => import('./views/SettingsView.vue') },
    { path: '/access-management', name: 'access-management', component: () => import('./views/AccessManagementView.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    if (! auth.ready) {
        await loadSession();
    }

    if (to.meta.guest && auth.user) {
        return { name: 'dashboard' };
    }

    if (! to.meta.guest && ! auth.user) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    document.title = `${to.meta.title ?? routeTitle(to.name)} | ${appState.branding.acronym}`;
});

function routeTitle(name) {
    return {
        login: 'Secure Sign In',
        'password.request': 'Reset Password',
        'password.reset': 'Choose New Password',
        dashboard: 'Executive Overview',
        'staff.index': 'Staff Registry',
        'staff.show': 'Staff Record',
        'staff.edit': 'Edit Staff Record',
        'imports.index': 'Data Imports',
        'imports.show': 'Import Review',
        'imports.rows.show': 'Import Row Review',
        'movement.index': 'Movement Workbooks',
        'movement.show': 'Movement Workbook',
        'budgets.index': 'Budget Workbooks',
        'budgets.show': 'Budget Workbook',
        reports: 'Reports',
        settings: 'Settings',
        'access-management': 'Access Control',
    }[name] ?? 'HMB-eHRMIS';
}

export default router;
