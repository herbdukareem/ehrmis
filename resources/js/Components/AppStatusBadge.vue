<script setup>
defineProps({
    status: {
        type: String,
        default: 'inactive',
    },
});

const tones = {
    active: 'ehrmis-badge-success',
    approved: 'ehrmis-badge-success',
    due: 'ehrmis-badge-success',
    reviewed: 'ehrmis-badge-info',
    submitted: 'ehrmis-badge-info',
    under_review: 'ehrmis-badge-warning',
    rejected: 'ehrmis-badge-danger',
    partially_published: 'ehrmis-badge-warning',
    published: 'ehrmis-badge-success',
    draft: 'ehrmis-badge-neutral',
    reopened: 'ehrmis-badge-info',
    locked: 'ehrmis-badge-danger',
    retiring: 'ehrmis-badge-warning',
    retired: 'ehrmis-badge-neutral',
    blocked_by_policy: 'ehrmis-badge-danger',
    included: 'ehrmis-badge-info',
    excluded: 'ehrmis-badge-neutral',
    duplicate: 'ehrmis-badge-warning',
    inactive: 'ehrmis-badge-neutral',
    global: 'ehrmis-badge-warning',
    mda: 'ehrmis-badge-info',
    live: 'ehrmis-badge-success',
    planned: 'ehrmis-badge-neutral',
};
</script>

<template>
    <span :class="tones[status] ?? tones.inactive">
        {{ status }}
    </span>
</template>
