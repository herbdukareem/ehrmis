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
    available: 'ehrmis-badge-success',
    accepted: 'ehrmis-badge-success',
    posted: 'ehrmis-badge-success',
    paid: 'ehrmis-badge-success',
    unpaid: 'ehrmis-badge-neutral',
    partially_paid: 'ehrmis-badge-warning',
    received: 'ehrmis-badge-success',
    liquidated: 'ehrmis-badge-success',
    issued: 'ehrmis-badge-info',
    evaluated: 'ehrmis-badge-info',
    recommended: 'ehrmis-badge-info',
    committed: 'ehrmis-badge-info',
    budget_reviewed: 'ehrmis-badge-info',
    open: 'ehrmis-badge-info',
    partially_received: 'ehrmis-badge-warning',
    partially_liquidated: 'ehrmis-badge-warning',
    returned: 'ehrmis-badge-warning',
    partial: 'ehrmis-badge-warning',
    insufficient: 'ehrmis-badge-danger',
    unmapped: 'ehrmis-badge-warning',
    pending: 'ehrmis-badge-neutral',
    closed: 'ehrmis-badge-neutral',
    completed: 'ehrmis-badge-success',
    cancelled: 'ehrmis-badge-danger',
    released: 'ehrmis-badge-neutral',
    partially_released: 'ehrmis-badge-warning',
    awarded: 'ehrmis-badge-success',
};
</script>

<template>
    <span :class="tones[status] ?? tones.inactive">
        {{ (status ?? 'inactive').replaceAll('_', ' ') }}
    </span>
</template>
