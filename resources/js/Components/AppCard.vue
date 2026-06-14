<script setup>
defineProps({
    title: {
        type: String,
        default: null,
    },
    subtitle: {
        type: String,
        default: null,
    },
    padding: {
        type: String,
        default: 'p-6', // allow override, e.g. 'p-0' for tables
    },
});
</script>

<template>
    <section class="ehrmis-card" :class="padding">
        <div v-if="title || $slots.actions" class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h2 v-if="title" class="text-base font-semibold text-ehrmis-text">{{ title }}</h2>
                <p v-if="subtitle" class="mt-1 text-sm text-ehrmis-muted">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <slot />
    </section>
</template>
