<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppIcon from '../../Components/AppIcon.vue';
import { auth, hasAnyAccess, hasAnyPermission, signOut } from '../stores/auth';
import { appState } from '../stores/app';

const route = useRoute();
const router = useRouter();
const mobileOpen = ref(false);
const openStorageKey = 'ehrmis.civic-nav.open-groups';

const navBlueprint = [
    {
        id: 'operations',
        label: 'Operations',
        items: [
            {
                id: 'dashboards',
                label: 'Dashboards',
                icon: 'dashboard',
                children: [
                    { label: 'Overview', to: '/dashboard', module: 'dashboards_analytics', permissionAny: ['view-reports'], hideForFacilityUsers: true },
                    { label: 'Facility dashboard', to: '/facility-dashboard', facilityOnly: true },
                    { label: 'Executive dashboard', to: '/executive-dashboard', module: 'dashboards_analytics', permissionAny: ['view-reports'] },
                ],
            },
            {
                id: 'hr-operations',
                label: 'HR operations',
                icon: 'users',
                children: [
                    { label: 'Staff registry', to: '/staff', module: 'staff_registry', permissionAny: ['view-staff'] },
                    { label: 'Data imports', to: '/legacy-staff-imports', module: 'legacy_import', permissionAny: ['view-staff-imports', 'import-staff', 'review-staff-imports', 'resolve-staff-import-issues', 'approve-staff-imports', 'publish-staff-imports', 'publish-own-mda-staff-imports'] },
                    { label: 'Promotions', to: '/promotion-cycles', permissionAny: ['view-promotions'] },
                    { label: 'Postings', to: '/posting-requests', permissionAny: ['view-postings'] },
                ],
            },
        ],
    },
    {
        id: 'planning',
        label: 'Planning',
        items: [
            {
                id: 'workplan-performance',
                label: 'Workplan',
                icon: 'checkCircle',
                children: [
                    { label: 'Annual workplans', to: '/workplans', module: 'workplan_performance', permissionAny: ['view-workplans'] },
                    { label: 'Progress reporting', to: '/workplan-progress', module: 'workplan_performance', permissionAny: ['update-workplan-progress', 'verify-workplan-progress'] },
                    { label: 'Performance views', to: '/workplan-performance', module: 'workplan_performance', permissionAny: ['view-workplan-performance'] },
                    { label: 'State performance overview', to: '/state-performance', module: 'workplan_performance', permissionAny: ['view-workplan-performance'], requiresGlobalAccess: true },
                    { label: 'State performance ranking', to: '/state-performance/rankings', module: 'workplan_performance', permissionAny: ['view-workplan-performance'], requiresGlobalAccess: true },
                    { label: 'State performance trends', to: '/state-performance/trends', module: 'workplan_performance', permissionAny: ['view-workplan-performance'], requiresGlobalAccess: true },
                    { label: 'Executive performance', to: '/state-performance/executive', module: 'workplan_performance', permissionAny: ['view-workplan-performance'], requiresGlobalAccess: true },
                ],
            },
            {
                id: 'movement-recurrent-budget',
                label: 'Movement & budget',
                icon: 'wallet',
                children: [
                    { label: 'Movement workbooks', to: '/movement-workbooks', module: 'movement_budget', permissionAny: ['view-movement-sheets', 'create-movement-sheets', 'approve-movement-sheets'] },
                    { label: 'Recurrent budget', to: '/budget-workbooks', module: 'movement_budget', permissionAny: ['view-budgets', 'create-budgets', 'approve-budgets'] },
                ],
            },
        ],
    },
    {
        id: 'reporting',
        label: 'Reporting',
        items: [
            {
                id: 'reports-returns',
                label: 'Reports & returns',
                icon: 'document',
                children: [
                    { label: 'Service reports', to: '/service-reports', module: 'service_reporting', permissionAny: ['view-service-reports'] },
                    { label: 'Staff reports', to: '/reports', module: 'dashboards_analytics', permissionAny: ['view-reports', 'export-reports'] },
                ],
            },
        ],
    },
    {
        id: 'administration',
        label: 'Administration',
        items: [
            {
                id: 'platform-setup',
                label: 'Platform setup',
                icon: 'building',
                children: [
                    { label: 'Settings', to: '/settings', module: 'settings', permissionAny: ['manage-platform-settings', 'manage-mda-settings'] },
                    {
                        label: 'Setup',
                        to: '/setup-management',
                        permissionAny: [
                            'manage-departments',
                            'manage-stations',
                            'manage-cadres',
                            'manage-ranks',
                            'manage-allowance-types',
                            'manage-salary-scales',
                            'manage-qualification-types',
                            'manage-salary-structure',
                        ],
                    },
                    { label: 'Access control', to: '/access-management', module: 'access_management', permissionAny: ['manage-users', 'manage-roles'] },
                ],
            },
        ],
    },
];

const readOpenGroups = () => {
    try {
        return JSON.parse(window.localStorage.getItem(openStorageKey) ?? '{}');
    } catch {
        return {};
    }
};

const openGroups = ref(readOpenGroups());

const itemIsVisible = (item) => (!item.facilityOnly || auth.user?.assigned_station)
    && (!item.hideForFacilityUsers || !auth.user?.assigned_station)
    && (!item.requiresGlobalAccess || auth.user?.has_global_access)
    && (item.module
        ? hasAnyAccess(item.module, item.permissionAny)
        : hasAnyPermission(item.permissionAny));

const navSections = computed(() => {
    let mark = 1;

    return navBlueprint
        .map((section) => {
            const items = section.items
                .map((group) => {
                    const children = (group.children ?? [])
                        .filter(itemIsVisible)
                        .map((item) => ({
                            ...item,
                            mark: String(mark++).padStart(2, '0'),
                        }));

                    return {
                        ...group,
                        children,
                    };
                })
                .filter((group) => group.children.length > 0);

            return {
                ...section,
                items,
            };
        })
        .filter((section) => section.items.length > 0);
});

const navItems = computed(() => navSections.value.flatMap((section) => section.items.flatMap((group) => group.children)));
const currentItem = computed(() => navItems.value.find((item) => route.path === item.to || route.path.startsWith(`${item.to}/`)) ?? null);
const currentSection = computed(() => currentItem.value?.label ?? 'Workspace');
const currentGroup = computed(() => navSections.value
    .flatMap((section) => section.items)
    .find((group) => group.children.some((item) => item === currentItem.value)) ?? null);

const isGroupOpen = (group) => openGroups.value[group.id] ?? group.id === currentGroup.value?.id;
const isGroupActive = (group) => group.id === currentGroup.value?.id;
const isActiveItem = (item) => route.path === item.to || route.path.startsWith(`${item.to}/`);

const toggleGroup = (group) => {
    openGroups.value = {
        ...openGroups.value,
        [group.id]: !isGroupOpen(group),
    };
};

watch(currentGroup, (group) => {
    if (!group || isGroupOpen(group)) {
        return;
    }

    openGroups.value = {
        ...openGroups.value,
        [group.id]: true,
    };
}, { immediate: true });

watch(openGroups, (value) => {
    try {
        window.localStorage.setItem(openStorageKey, JSON.stringify(value));
    } catch {
        // Navigation state persistence is a convenience; the menu still works without it.
    }
}, { deep: true });

const logout = async () => {
    await signOut();
    await router.push('/login');
};
</script>

<template>
    <div class="civic-app">
        <div class="civic-ministry-bar">
            <span>Government of {{ appState.branding.state_name }}</span>
            <span class="hidden sm:inline">{{ appState.branding.name }}</span>
            <span>Official HR Information System</span>
        </div>

        <aside class="civic-rail" :class="{ 'civic-rail-open': mobileOpen }">
            <div class="civic-brand">
                <img class="civic-brand-logo" :src="appState.branding.logo_url" :alt="`${appState.branding.name} logo`">
                <div>
                    <div class="civic-brand-title">{{ appState.branding.acronym }}</div>
                    <div class="civic-brand-subtitle">{{ appState.branding.name }}</div>
                </div>
            </div>

            <nav class="civic-nav">
                <section v-for="section in navSections" :key="section.id" class="civic-nav-section">
                    <div v-for="group in section.items" :key="group.id" class="civic-nav-group" :class="{ 'civic-nav-group-active': isGroupActive(group) }">
                        <button
                            type="button"
                            class="civic-nav-parent"
                            :aria-expanded="isGroupOpen(group)"
                            @click="toggleGroup(group)"
                        >
                            <AppIcon :name="group.icon" class="civic-nav-icon" />
                            <span>{{ group.label }}</span>
                            <AppIcon name="chevronDown" class="civic-nav-chevron" :class="{ 'civic-nav-chevron-open': isGroupOpen(group) }" />
                        </button>
                        <div v-if="isGroupOpen(group)" class="civic-nav-children">
                            <RouterLink
                                v-for="item in group.children"
                                :key="item.to"
                                :to="item.to"
                                class="civic-nav-link"
                                :class="{ 'civic-nav-link-active': isActiveItem(item) }"
                                @click="mobileOpen = false"
                            >
                                <span class="civic-nav-mark">{{ item.mark }}</span>
                                <span>{{ item.label }}</span>
                            </RouterLink>
                        </div>
                    </div>
                </section>
            </nav>

            <div class="civic-identity">
                <div class="civic-identity-name">{{ auth.user?.name }}</div>
                <div class="civic-identity-meta">{{ auth.user?.assigned_mda?.code ?? 'Government-wide access' }}</div>
                <button class="civic-text-action" type="button" @click="logout">Sign out</button>
            </div>
        </aside>

        <main class="civic-main">
            <header class="civic-topbar">
                <button class="civic-menu-button" type="button" @click="mobileOpen = !mobileOpen">Menu</button>
                <div>
                    <div class="civic-eyebrow">{{ currentSection }}</div>
                    <div class="civic-topbar-title">{{ appState.branding.acronym }} Operations Console</div>
                </div>
                <div class="civic-user-chip">{{ auth.user?.roles?.[0] ?? auth.user?.user_type }}</div>
            </header>

            <div class="civic-page">
                <slot />
            </div>
        </main>
    </div>
</template>
