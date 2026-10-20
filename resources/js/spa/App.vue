<script setup>
import { computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppButton from '../Components/AppButton.vue';
import AppCard from '../Components/AppCard.vue';
import AppIcon from '../Components/AppIcon.vue';
import CivicShell from './components/CivicShell.vue';
import BrandedLoader from './components/BrandedLoader.vue';
import ToastStack from './components/ToastStack.vue';
import { appState, clearPageError } from './stores/app';
import { defaultAuthenticatedPath } from './stores/auth';

const route = useRoute();
const isGuest = computed(() => route.meta.guest || route.meta.public);
const workspacePath = computed(() => defaultAuthenticatedPath());

watch(() => route.fullPath, () => clearPageError());
</script>

<template>
    <RouterView v-if="isGuest" :key="`guest-${route.fullPath}`" />
    <CivicShell v-else>
        <AppCard v-if="appState.navigationError" class="mb-6" role="alert" title="Page unavailable" :subtitle="appState.navigationError.message">
            <div class="flex flex-wrap gap-3">
                <AppButton :href="appState.navigationError.path" external>
                    <AppIcon name="refresh" class="h-4 w-4" />Reload page
                </AppButton>
                <AppButton :to="workspacePath" variant="outline">
                    <AppIcon name="dashboard" class="h-4 w-4" />Return to workspace
                </AppButton>
            </div>
        </AppCard>
        <section v-if="appState.pageError" class="civic-workspace civic-prose">
            <div class="civic-eyebrow">Authorization</div>
            <h2>Access unavailable</h2>
            <p>{{ appState.pageError }}</p>
            <RouterLink class="civic-button civic-button-primary" :to="workspacePath" @click="clearPageError">Return to workspace</RouterLink>
        </section>
        <RouterView v-else :key="`authenticated-${route.fullPath}`" />
    </CivicShell>
    <ToastStack />
    <Transition name="civic-loader-fade"><BrandedLoader v-if="appState.pendingRequests > 0" overlay label="Processing secure request..." /></Transition>
</template>
