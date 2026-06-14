<script setup>
import { appState } from '../stores/app';

defineProps({
    overlay: { type: Boolean, default: false },
    label: { type: String, default: 'Loading official records...' },
});
</script>

<template>
    <div class="civic-brand-loader" :class="{ 'civic-brand-loader-overlay': overlay }" role="status">
        <div class="civic-loader-orbit">
            <img :src="appState.branding.logo_url" :alt="`${appState.branding.name} logo`">
        </div>
        <span>{{ label }}</span>
    </div>
</template>
