<script setup>
import AppIcon from '@/Components/AppIcon.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <nav v-if="items.length" class="flex items-center gap-1 text-sm text-ehrmis-muted" aria-label="Breadcrumb">
        <template v-for="(item, index) in items" :key="index">
            <Link
                v-if="item.href && index !== items.length - 1"
                :href="item.href"
                class="rounded px-1 py-0.5 transition hover:text-ehrmis-primary-700 hover:underline"
            >
                {{ item.label }}
            </Link>
            <span
                v-else
                class="px-1 py-0.5 font-medium text-ehrmis-text"
            >
                {{ item.label }}
            </span>

            <AppIcon
                v-if="index !== items.length - 1"
                name="chevronRight"
                class="h-4 w-4 text-slate-300"
            />
        </template>
    </nav>
</template>
