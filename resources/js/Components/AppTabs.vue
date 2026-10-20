<script setup>
import { Link } from '@inertiajs/vue3';
import { RouterLink } from 'vue-router';

defineProps({
    tabs: {
        type: Array,
        default: () => [],
    },
    modelValue: { type: String, default: null },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex flex-wrap gap-1 rounded-md border border-ehrmis-border bg-white p-1.5" :role="modelValue !== null ? 'tablist' : 'navigation'" aria-label="Views">
        <component
            :is="tab.to ? RouterLink : (tab.href ? Link : 'button')"
            v-for="tab in tabs"
            :key="tab.key ?? tab.label"
            v-bind="tab.to ? { to: tab.to } : (tab.href ? { href: tab.href } : { type: 'button' })"
            :role="modelValue !== null ? 'tab' : undefined"
            :aria-selected="modelValue !== null ? modelValue === tab.key : undefined"
            :aria-current="tab.active && modelValue === null ? 'page' : undefined"
            class="rounded px-3 py-2 text-sm font-medium transition"
            :class="(tab.active || modelValue === tab.key)
                ? 'bg-ehrmis-primary-700 text-white shadow-ehrmis-card'
                : 'text-ehrmis-muted hover:bg-slate-100 hover:text-ehrmis-text'"
            @click="!tab.to && !tab.href && $emit('update:modelValue', tab.key)"
        >
            {{ tab.label }}
        </component>
    </div>
</template>
