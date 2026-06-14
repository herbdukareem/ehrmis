<script setup>
import AppCard from '@/Components/AppCard.vue';

defineProps({
    summary: {
        type: Object,
        default: null,
    },
});
</script>

<template>
    <AppCard v-if="summary" title="Latest Publication">
        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <div class="text-xs font-medium text-ehrmis-muted">Rows Published</div>
                <div class="mt-1 text-2xl font-semibold text-ehrmis-text">{{ summary.rows_published ?? 0 }}</div>
            </div>
            <div>
                <div class="text-xs font-medium text-ehrmis-muted">Created</div>
                <div class="mt-1 text-2xl font-semibold text-ehrmis-text">{{ summary.published_created ?? 0 }}</div>
            </div>
            <div>
                <div class="text-xs font-medium text-ehrmis-muted">Updated</div>
                <div class="mt-1 text-2xl font-semibold text-ehrmis-text">{{ summary.published_updated ?? 0 }}</div>
            </div>
        </div>
        <div class="mt-4 grid gap-3 text-sm text-ehrmis-muted md:grid-cols-2">
            <div class="flex items-center justify-between">
                <span>Skipped blocking errors</span>
                <span class="font-semibold text-ehrmis-text">{{ summary.skipped_blocking_errors ?? 0 }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span>Skipped already published</span>
                <span class="font-semibold text-ehrmis-text">{{ summary.skipped_already_published ?? 0 }}</span>
            </div>
        </div>
    </AppCard>
</template>
