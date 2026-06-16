<script setup>
import AppIcon from '@/Components/AppIcon.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    homeHref: {
        type: String,
        default: '/dashboard',
    },
    homeLabel: {
        type: String,
        default: 'Dashboard',
    },
});

const trail = computed(() => [
    { label: props.homeLabel, href: props.homeHref, home: true },
    ...props.items.filter((item) => item?.label && item.href !== props.homeHref),
]);
</script>

<template>
    <nav class="min-w-0" aria-label="Breadcrumb">
        <ol class="flex min-w-0 items-center gap-1 text-xs font-medium text-ehrmis-muted">
            <li
                v-for="(item, index) in trail"
                :key="`${item.label}-${index}`"
                class="flex min-w-0 items-center gap-1"
            >
                <AppIcon
                    v-if="index"
                    name="chevronRight"
                    class="h-3.5 w-3.5 shrink-0 text-slate-300"
                />

                <Link
                    v-if="item.href && index !== trail.length - 1"
                    :href="item.href"
                    class="flex max-w-44 items-center gap-1.5 truncate rounded-md px-1.5 py-1 transition hover:bg-ehrmis-primary-50 hover:text-ehrmis-primary-700"
                    :title="item.label"
                >
                    <AppIcon v-if="item.home" name="dashboard" class="h-3.5 w-3.5 shrink-0" />
                    <span class="truncate">{{ item.label }}</span>
                </Link>

                <span
                    v-else
                    class="max-w-56 truncate px-1.5 py-1 text-ehrmis-text"
                    aria-current="page"
                    :title="item.label"
                >
                    {{ item.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
