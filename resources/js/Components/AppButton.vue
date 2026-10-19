<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { RouterLink } from 'vue-router';

const props = defineProps({
    variant: {
        type: String,
        default: 'primary', // primary | secondary | outline | ghost | danger | accent
    },
    size: {
        type: String,
        default: 'md', // sm | md | lg
    },
    href: {
        type: String,
        default: null,
    },
    to: { type: [String, Object], default: null },
    external: { type: Boolean, default: false },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const variantClass = computed(() => ({
    primary: 'ehrmis-btn-primary',
    secondary: 'ehrmis-btn-secondary',
    outline: 'ehrmis-btn-outline',
    ghost: 'ehrmis-btn-ghost',
    danger: 'ehrmis-btn-danger',
    accent: 'ehrmis-btn-accent',
}[props.variant] ?? 'ehrmis-btn-primary'));

const sizeClass = computed(() => ({
    sm: 'px-3 py-1.5 text-xs',
    md: 'px-4 py-2 text-sm',
    lg: 'px-5 py-2.5 text-base',
}[props.size] ?? 'px-4 py-2 text-sm'));
</script>

<template>
    <RouterLink v-if="to && !disabled" :to="to" :class="[variantClass, sizeClass]"><slot /></RouterLink>
    <a v-else-if="href && external && !disabled" :href="href" :class="[variantClass, sizeClass]"><slot /></a>
    <Link
        v-else-if="href && !disabled"
        :href="href"
        :class="[variantClass, sizeClass]"
    >
        <slot />
    </Link>
    <button
        v-else
        :type="type"
        :disabled="disabled"
        :class="[variantClass, sizeClass]"
    >
        <slot />
    </button>
</template>
