<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div
        v-if="links.length > 3"
        class="flex flex-wrap items-center gap-2"
    >
        <Link
            v-for="(link, index) in links"
            :key="index"
            :href="link.url ?? '#'"
            class="inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-sm font-medium transition"
            :class="link.active
                ? 'bg-ehrmis-primary-700 text-white shadow-ehrmis-card'
                : link.url
                    ? 'border border-ehrmis-border bg-white text-ehrmis-text hover:bg-slate-50'
                    : 'cursor-not-allowed border border-ehrmis-border bg-slate-50 text-slate-300'"
            v-html="link.label"
        />
    </div>
</template>
