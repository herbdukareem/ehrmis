<script setup>
import AppBreadcrumbs from '@/Components/AppBreadcrumbs.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    title: String,
    subtitle: String,
    eyebrow: {
        type: String,
        default: '',
    },
    breadcrumbs: {
        type: Array,
        default: () => [],
    },
    homeHref: {
        type: String,
        default: '/dashboard',
    },
    showBackLink: {
        type: Boolean,
        default: true,
    },
});

const breadcrumbItems = computed(() => {
    if (props.breadcrumbs.length) {
        return props.breadcrumbs;
    }

    return [{ label: props.title }];
});

const backItem = computed(() => {
    if (!props.showBackLink || !props.breadcrumbs.length) {
        return null;
    }

    return [...props.breadcrumbs].reverse().find((item) => item?.href) ?? null;
});
</script>

<template>
    <header class="border-b border-ehrmis-border pb-4">
        <div class="flex min-w-0 items-center justify-between gap-4">
            <AppBreadcrumbs :items="breadcrumbItems" :home-href="homeHref" />

            <Link
                v-if="backItem"
                :href="backItem.href"
                class="hidden shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-ehrmis-muted transition hover:bg-white hover:text-ehrmis-primary-700 sm:flex"
            >
                <AppIcon name="chevronLeft" class="h-3.5 w-3.5" />
                Back to {{ backItem.label }}
            </Link>
        </div>

        <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <p v-if="eyebrow" class="mb-1 text-xs font-semibold uppercase tracking-wider text-ehrmis-primary-700">
                    {{ eyebrow }}
                </p>
                <h1 class="truncate text-xl font-semibold leading-tight tracking-tight text-ehrmis-text md:text-[1.375rem]" :title="title">
                    {{ title }}
                </h1>
                <p v-if="subtitle" class="mt-1 max-w-3xl text-sm leading-5 text-ehrmis-muted">{{ subtitle }}</p>
            </div>

            <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
