<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    tabs: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="flex flex-wrap gap-1 rounded-xl border border-ehrmis-border bg-white p-1.5 shadow-ehrmis-card">
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href"
            class="rounded-lg px-4 py-2 text-sm font-medium transition"
            :class="tab.active
                ? 'bg-ehrmis-primary-700 text-white shadow-ehrmis-card'
                : 'text-ehrmis-muted hover:bg-slate-100 hover:text-ehrmis-text'"
        >
            {{ tab.label }}
        </Link>
    </div>
</template>
