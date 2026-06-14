<script setup>
import AppCard from '@/Components/AppCard.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
defineProps({ histories: Array });
</script>

<template>
    <AppCard>
        <div class="text-sm font-semibold text-ehrmis-text">Status History</div>
        <div class="mt-4 space-y-3">
            <div
                v-for="history in histories ?? []"
                :key="history.id"
                class="rounded-ehrmis border border-ehrmis-border px-4 py-3"
            >
                <div class="flex items-center justify-between gap-3">
                    <AppStatusBadge :status="history.status" />
                    <div class="text-sm text-ehrmis-muted">{{ history.effective_from ?? 'No effective date' }}</div>
                </div>
                <div class="mt-2 text-sm text-ehrmis-text">{{ history.reason ?? 'No reason provided' }}</div>
            </div>
            <div
                v-if="!(histories?.length)"
                class="text-sm text-ehrmis-muted"
            >
                No status history yet.
            </div>
        </div>
    </AppCard>
</template>
