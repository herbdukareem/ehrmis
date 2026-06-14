<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        default: null,
    },
    type: {
        type: String,
        default: 'button',
    },
    label: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <Link
        v-if="href"
        :href="href"
        :title="label"
        :aria-label="label"
        class="ehrmis-icon-btn"
    >
        <slot />
    </Link>
    <button
        v-else
        :type="type"
        :title="label"
        :aria-label="label"
        class="ehrmis-icon-btn"
    >
        <slot />
    </button>
</template>
