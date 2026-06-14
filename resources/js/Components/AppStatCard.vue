<script setup>
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    label: String,
    value: [String, Number],
    description: {
        type: String,
        default: null,
    },
    icon: {
        type: String,
        default: null,
    },
    tone: {
        type: String,
        default: 'primary', // primary | navy | accent | neutral
    },
});

const toneClass = {
    primary: 'bg-ehrmis-primary-50 text-ehrmis-primary-700',
    navy: 'bg-ehrmis-navy-50 text-ehrmis-navy-700',
    accent: 'bg-ehrmis-accent-50 text-ehrmis-accent-700',
    neutral: 'bg-slate-100 text-slate-700',
};
</script>

<template>
    <section class="ehrmis-card p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-sm text-ehrmis-muted">{{ label }}</div>
                <div class="mt-2 text-2xl font-semibold text-ehrmis-text">{{ value }}</div>
                <div v-if="description" class="mt-2 text-sm text-ehrmis-muted">{{ description }}</div>
            </div>

            <div
                v-if="icon"
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl"
                :class="toneClass[tone] ?? toneClass.primary"
            >
                <AppIcon :name="icon" class="h-6 w-6" />
            </div>
        </div>
    </section>
</template>
