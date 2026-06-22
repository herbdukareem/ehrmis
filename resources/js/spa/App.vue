<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import CivicShell from './components/CivicShell.vue';
import BrandedLoader from './components/BrandedLoader.vue';
import ToastStack from './components/ToastStack.vue';
import { appState } from './stores/app';

const route = useRoute();
const isGuest = computed(() => route.meta.guest);
</script>

<template>
    <RouterView v-if="isGuest" :key="`guest-${route.fullPath}`" />
    <CivicShell v-else>
        <RouterView :key="`authenticated-${route.fullPath}`" />
    </CivicShell>
    <ToastStack />
    <Transition name="civic-loader-fade"><BrandedLoader v-if="appState.pendingRequests > 0" overlay label="Processing secure request..." /></Transition>
</template>
