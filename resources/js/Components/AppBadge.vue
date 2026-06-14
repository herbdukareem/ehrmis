<script setup>
defineProps({
    tone: {
        type: String,
        default: 'neutral', // neutral | success | warning | danger | info
    },
});

const toneClass = {
    neutral: 'ehrmis-badge-neutral',
    success: 'ehrmis-badge-success',
    warning: 'ehrmis-badge-warning',
    danger: 'ehrmis-badge-danger',
    info: 'ehrmis-badge-info',
};
</script>

<template>
    <span :class="toneClass[tone] ?? toneClass.neutral">
        <slot />
    </span>
</template>
