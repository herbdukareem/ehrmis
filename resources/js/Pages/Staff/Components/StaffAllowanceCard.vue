<script setup>
import AppCard from '@/Components/AppCard.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
defineProps({ allowances: Array, importMetadata: Object });
</script>

<template>
    <AppCard>
        <div class="text-sm font-semibold text-ehrmis-text">Allowance Eligibility</div>
        <div
            v-if="importMetadata?.needs_call_allowance_clarification"
            class="mt-4 rounded-ehrmis border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            Needs clarification: legacy call allowance eligibility exists for this staff record, but the exact modern call allowance type was not force-mapped.
        </div>
        <div class="mt-4 space-y-3">
            <div
                v-for="allowance in allowances ?? []"
                :key="allowance.id"
                class="flex items-center justify-between rounded-ehrmis border border-ehrmis-border px-4 py-3"
            >
                <div>
                    <div class="font-semibold text-ehrmis-text">{{ allowance.allowance_name }}</div>
                    <div class="text-sm text-ehrmis-muted">{{ allowance.allowance_code }}</div>
                </div>
                <AppStatusBadge :status="allowance.is_eligible ? 'active' : 'inactive'" />
            </div>
            <div
                v-if="!(allowances?.length)"
                class="text-sm text-ehrmis-muted"
            >
                No allowance assignments recorded yet.
            </div>
        </div>
    </AppCard>
</template>
