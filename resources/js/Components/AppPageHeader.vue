<script setup>
import AppBreadcrumbs from '@/Components/AppBreadcrumbs.vue';

defineProps({
    title: String,
    subtitle: String,
    breadcrumbs: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="space-y-3">
        <AppBreadcrumbs :items="breadcrumbs" />

        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-ehrmis-text">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 text-sm text-ehrmis-muted">{{ subtitle }}</p>
            </div>

            <div class="flex items-center gap-3">
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>
