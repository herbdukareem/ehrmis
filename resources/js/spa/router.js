import { createRouter, createWebHistory } from 'vue-router';
import { auth, defaultAuthenticatedPath, hasAnyAccess, loadSession } from './stores/auth';
import { appState, clearPageError } from './stores/app';

const routes = [
    { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { guest: true } },
    { path: '/promotion/apply', name: 'promotion.apply', component: () => import('./views/PublicPromotionApplicationView.vue'), meta: { public: true, title: 'Promotion APA Application' } },
    { path: '/forgot-password', name: 'password.request', component: () => import('./views/PasswordAccessView.vue'), props: { mode: 'forgot' }, meta: { guest: true } },
    { path: '/reset-password/:token', name: 'password.reset', component: () => import('./views/PasswordAccessView.vue'), props: { mode: 'reset' }, meta: { guest: true } },
    { path: '/', redirect: '/dashboard' },
    { path: '/dashboard', name: 'dashboard', component: () => import('./views/DashboardView.vue'), meta: { module: 'dashboards_analytics', permissionAny: ['view-reports'] } },
    { path: '/executive-dashboard', name: 'executive-dashboard', component: () => import('./views/ExecutiveDashboardView.vue'), meta: { module: 'dashboards_analytics', permissionAny: ['view-reports'] } },
    { path: '/staff', name: 'staff.index', component: () => import('./views/StaffIndexView.vue'), meta: { module: 'staff_registry', permissionAny: ['view-staff'] } },
    { path: '/staff/:id', name: 'staff.show', component: () => import('./views/StaffShowView.vue') },
    { path: '/staff/:id/edit', name: 'staff.edit', component: () => import('./views/StaffEditView.vue') },
    { path: '/legacy-staff-imports', name: 'imports.index', component: () => import('./views/ImportIndexView.vue'), meta: { module: 'legacy_import', permissionAny: ['view-staff-imports', 'import-staff', 'review-staff-imports', 'resolve-staff-import-issues', 'approve-staff-imports', 'publish-staff-imports', 'publish-own-mda-staff-imports'] } },
    { path: '/legacy-staff-imports/:id', name: 'imports.show', component: () => import('./views/ImportShowView.vue') },
    { path: '/legacy-staff-imports/:batchId/rows/:rowId', name: 'imports.rows.show', component: () => import('./views/ImportRowView.vue') },
    { path: '/movement-workbooks', name: 'movement.index', component: () => import('./views/MovementIndexView.vue'), meta: { module: 'movement_budget', permissionAny: ['view-movement-sheets', 'create-movement-sheets', 'approve-movement-sheets'] } },
    { path: '/movement-workbooks/:id', name: 'movement.show', component: () => import('./views/MovementShowView.vue') },
    { path: '/promotion-cycles', name: 'promotions.index', component: () => import('./views/PromotionIndexView.vue') },
    { path: '/promotion-cycles/:id', name: 'promotions.show', component: () => import('./views/PromotionShowView.vue') },
    { path: '/posting-requests', name: 'postings.index', component: () => import('./views/PostingIndexView.vue') },
    { path: '/posting-requests/:id', name: 'postings.show', component: () => import('./views/PostingShowView.vue') },
    { path: '/budget-workbooks', name: 'budgets.index', component: () => import('./views/BudgetIndexView.vue'), meta: { module: 'movement_budget', permissionAny: ['view-budgets', 'create-budgets', 'approve-budgets'] } },
    { path: '/budget-workbooks/:id', name: 'budgets.show', component: () => import('./views/BudgetShowView.vue') },
    { path: '/service-reports', name: 'service-reports', component: () => import('./views/ServiceReportsView.vue'), meta: { module: 'service_reporting', permissionAny: ['view-service-reports'] } },
    { path: '/service-reports/templates', name: 'service-reports.templates', component: () => import('./views/ServiceReportsView.vue'), meta: { module: 'service_reporting', permissionAny: ['view-service-reports'] } },
    { path: '/service-reports/templates/:id', name: 'service-reports.templates.show', component: () => import('./views/ServiceReportsView.vue'), meta: { module: 'service_reporting', permissionAny: ['view-service-reports'] } },
    { path: '/service-reports/submissions', name: 'service-reports.submissions', component: () => import('./views/ServiceReportsView.vue'), meta: { module: 'service_reporting', permissionAny: ['view-service-reports'] } },
    { path: '/service-reports/submissions/:id', name: 'service-reports.submissions.show', component: () => import('./views/ServiceReportsView.vue'), meta: { module: 'service_reporting', permissionAny: ['view-service-reports'] } },
    { path: '/service-reports/submit', name: 'service-reports.submit', component: () => import('./views/ServiceReportsView.vue'), meta: { module: 'service_reporting', permissionAny: ['create-service-reports'] } },
    { path: '/service-reports/analytics', name: 'service-reports.analytics', component: () => import('./views/ServiceReportsView.vue'), meta: { module: 'service_reporting', permissionAny: ['view-service-reports'] } },
    { path: '/reports', name: 'reports', component: () => import('./views/ReportsView.vue'), meta: { module: 'dashboards_analytics', permissionAny: ['view-reports', 'export-reports'] } },
    { path: '/settings', name: 'settings', component: () => import('./views/SettingsView.vue'), meta: { module: 'settings', permissionAny: ['manage-platform-settings', 'manage-mda-settings'] } },
    { path: '/setup-management', name: 'setup-management', component: () => import('./views/SetupManagementView.vue') },
    { path: '/access-management', name: 'access-management', component: () => import('./views/AccessManagementView.vue'), meta: { module: 'access_management', permissionAny: ['manage-users', 'manage-roles'] } },
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
        return defaultAuthenticatedPath();
    }

    if (! to.meta.guest && ! to.meta.public && ! auth.user) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.module && !hasAnyAccess(to.meta.module, to.meta.permissionAny ?? [])) {
        const fallback = defaultAuthenticatedPath();
        if (fallback !== to.path) {
            return fallback;
        }
    }

    clearPageError();
    document.title = `${to.meta.title ?? routeTitle(to.name)} | ${appState.branding.acronym}`;
});

function routeTitle(name) {
    return {
        login: 'Secure Sign In',
        'password.request': 'Reset Password',
        'password.reset': 'Choose New Password',
        dashboard: 'Executive Overview',
        'executive-dashboard': 'Executive Intelligence',
        'staff.index': 'Staff Registry',
        'staff.show': 'Staff Record',
        'staff.edit': 'Edit Staff Record',
        'imports.index': 'Data Imports',
        'imports.show': 'Import Review',
        'imports.rows.show': 'Import Row Review',
        'movement.index': 'Movement Workbooks',
        'movement.show': 'Movement Workbook',
        'promotions.index': 'Promotion Cycles',
        'promotions.show': 'Promotion Cycle',
        'postings.index': 'Staff Posting',
        'postings.show': 'Posting Request',
        'budgets.index': 'Budget Workbooks',
        'budgets.show': 'Budget Workbook',
        'service-reports': 'Service Reports',
        'service-reports.templates': 'Service Report Templates',
        'service-reports.templates.show': 'Report Template',
        'service-reports.submissions': 'Report Submissions',
        'service-reports.submissions.show': 'Report Submission',
        'service-reports.submit': 'Submit Service Report',
        'service-reports.analytics': 'Service Report Analytics',
        reports: 'Reports',
        settings: 'Settings',
        'setup-management': 'Setup Management',
        'access-management': 'Access Control',
    }[name] ?? 'HMB-eHRMIS';
}

export default router;
