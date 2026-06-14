<script setup>
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    placeholder: {
        type: String,
        default: 'Search...',
    },
});

const model = defineModel({ default: '' });
</script>

<template>
    <div class="relative">
        <AppIcon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ehrmis-muted" />

        <input
            v-model="model"
            type="text"
            :placeholder="placeholder"
            class="ehrmis-input pl-9"
            v-bind="$attrs"
        >
    </div>
</template>
