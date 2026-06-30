<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { auth, signOut } from '../stores/auth';
import { appState } from '../stores/app';

const route = useRoute();
const router = useRouter();
const mobileOpen = ref(false);

const hasAnyPermission = (permissions = []) => permissions.some((permission) => auth.user?.permissions?.includes(permission));

const navBlueprint = [
    {
        id: 'operations',
        label: 'Operations',
        items: [
            { label: 'Overview', to: '/dashboard', permissionAny: [] },
            { label: 'Staff registry', to: '/staff', permissionAny: [] },
            { label: 'Data imports', to: '/legacy-staff-imports', permissionAny: [] },
            { label: 'Movement', to: '/movement-workbooks', permissionAny: [] },
            { label: 'Promotions', to: '/promotion-cycles', permissionAny: ['view-promotions'] },
            { label: 'Postings', to: '/posting-requests', permissionAny: ['view-postings'] },
            { label: 'Budget', to: '/budget-workbooks', permissionAny: [] },
            { label: 'Reports', to: '/reports', permissionAny: [] },
        ],
    },
    {
        id: 'administration',
        label: 'Administration',
        items: [
            { label: 'Settings', to: '/settings', permissionAny: ['manage-platform-settings', 'manage-mda-settings'] },
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
            { label: 'Access control', to: '/access-management', permissionAny: ['manage-users', 'manage-roles'] },
        ],
    },
];

const navSections = computed(() => {
    let mark = 1;

    return navBlueprint
        .map((section) => {
            const items = section.items
                .filter((item) => item.permissionAny.length === 0 || hasAnyPermission(item.permissionAny))
                .map((item) => ({
                    ...item,
                    mark: String(mark++).padStart(2, '0'),
                }));

            return {
                ...section,
                items,
            };
        })
        .filter((section) => section.items.length > 0);
});

const navItems = computed(() => navSections.value.flatMap((section) => section.items));
const currentItem = computed(() => navItems.value.find((item) => route.path === item.to || route.path.startsWith(`${item.to}/`)) ?? null);
const currentSection = computed(() => currentItem.value?.label ?? 'Workspace');

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
                    <div class="civic-nav-section-title">{{ section.label }}</div>
                    <RouterLink
                        v-for="item in section.items"
                        :key="item.to"
                        :to="item.to"
                        class="civic-nav-link"
                        @click="mobileOpen = false"
                    >
                        <span class="civic-nav-mark">{{ item.mark }}</span>
                        <span>{{ item.label }}</span>
                    </RouterLink>
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
