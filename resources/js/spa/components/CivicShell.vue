<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { auth, signOut } from '../stores/auth';
import { appState } from '../stores/app';

const route = useRoute();
const router = useRouter();
const mobileOpen = ref(false);

const nav = computed(() => [
    { label: 'Overview', to: '/dashboard', mark: '01' },
    { label: 'Staff registry', to: '/staff', mark: '02' },
    { label: 'Data imports', to: '/legacy-staff-imports', mark: '03' },
    { label: 'Movement', to: '/movement-workbooks', mark: '04' },
    { label: 'Budget', to: '/budget-workbooks', mark: '05' },
    { label: 'Reports', to: '/reports', mark: '06' },
    ...(auth.user?.permissions?.some((permission) => ['manage-platform-settings', 'manage-mda-settings'].includes(permission)) ? [{ label: 'Settings', to: '/settings', mark: '07' }] : []),
    ...(auth.user?.permissions?.some((permission) => ['manage-users', 'manage-roles'].includes(permission)) ? [{ label: 'Access control', to: '/access-management', mark: '08' }] : []),
]);

const currentSection = computed(() => nav.find((item) => route.path.startsWith(item.to))?.label ?? 'Workspace');

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
                <RouterLink
                    v-for="item in nav"
                    :key="item.to"
                    :to="item.to"
                    class="civic-nav-link"
                    @click="mobileOpen = false"
                >
                    <span class="civic-nav-mark">{{ item.mark }}</span>
                    <span>{{ item.label }}</span>
                </RouterLink>
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
