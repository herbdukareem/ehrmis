<script setup>
import AppCard from '@/Components/AppCard.vue';

defineProps({
    batch: {
        type: Object,
        default: () => ({}),
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
});
</script>

<template>
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
        <AppCard>
            <div class="text-sm text-ehrmis-muted">Rows Staged</div>
            <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ summary.rows_staged ?? batch.rows_staged ?? 0 }}</div>
        </AppCard>

        <AppCard>
            <div class="text-sm text-ehrmis-muted">Rows Published</div>
            <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ summary.rows_published ?? batch.rows_published ?? 0 }}</div>
        </AppCard>

        <AppCard>
            <div class="text-sm text-ehrmis-muted">Publishable</div>
            <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ summary.rows_publishable ?? 0 }}</div>
        </AppCard>

        <AppCard>
            <div class="text-sm text-ehrmis-muted">Warnings</div>
            <div class="mt-2 text-3xl font-semibold text-amber-600">{{ summary.warnings_count ?? batch.warnings_count ?? 0 }}</div>
        </AppCard>

        <AppCard>
            <div class="text-sm text-ehrmis-muted">Blocking Errors</div>
            <div class="mt-2 text-3xl font-semibold text-rose-600">{{ summary.errors_count ?? batch.errors_count ?? 0 }}</div>
        </AppCard>

        <AppCard>
            <div class="text-sm text-ehrmis-muted">Unresolved Call Warnings</div>
            <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ summary.unresolved_call_allowance_count ?? batch.unresolved_call_allowance_count ?? 0 }}</div>
        </AppCard>
    </div>
</template>
