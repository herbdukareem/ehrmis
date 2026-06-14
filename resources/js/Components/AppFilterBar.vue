<script setup>
defineProps({
    title: {
        type: String,
        default: 'Filters',
    },
    subtitle: {
        type: String,
        default: null,
    },
    columns: {
        type: String,
        default: 'md:grid-cols-2 xl:grid-cols-4',
    },
});
</script>

<template>
    <section class="ehrmis-card p-6">
        <div class="flex flex-col gap-4">
            <div v-if="title || subtitle">
                <div class="text-sm font-semibold text-ehrmis-text">{{ title }}</div>
                <p v-if="subtitle" class="mt-1 text-sm text-ehrmis-muted">{{ subtitle }}</p>
            </div>

            <div class="grid gap-4" :class="columns">
                <slot />
            </div>

            <div v-if="$slots.actions" class="flex flex-wrap gap-3">
                <slot name="actions" />
            </div>
        </div>
    </section>
</template>
