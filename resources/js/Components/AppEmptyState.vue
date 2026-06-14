<script setup>
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    title: String,
    message: String,
    icon: {
        type: String,
        default: 'document',
    },
});
</script>

<template>
    <div class="rounded-ehrmis border border-dashed border-ehrmis-border bg-white px-6 py-12 text-center">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-ehrmis-primary-50 text-ehrmis-primary-600">
            <AppIcon :name="icon" class="h-6 w-6" />
        </div>
        <h3 class="mt-4 text-lg font-semibold text-ehrmis-text">{{ title }}</h3>
        <p class="mt-2 text-sm text-ehrmis-muted">{{ message }}</p>
        <div v-if="$slots.actions" class="mt-4 flex justify-center gap-3">
            <slot name="actions" />
        </div>
    </div>
</template>
