<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import ImportBatchStatusBadge from '@/Pages/LegacyStaffImports/Components/ImportBatchStatusBadge.vue';

defineProps({
    batchId: {
        type: Number,
        required: true,
    },
    rows: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div v-if="rows.length" class="ehrmis-table-wrap">
        <div class="overflow-x-auto">
            <table class="ehrmis-table">
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th>MDA</th>
                        <th>Placement</th>
                        <th>Status</th>
                        <th>Issues</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <div class="font-medium text-ehrmis-text">{{ row.full_name ?? 'Unknown Staff' }}</div>
                            <div class="mt-1 text-xs text-ehrmis-muted">
                                {{ row.staff_number ?? row.dedupe_key }}
                            </div>
                            <div class="mt-1 text-xs text-ehrmis-muted">
                                CNO: {{ row.legacy_cno ?? 'N/A' }} | PSN: {{ row.legacy_psn ?? 'N/A' }}
                            </div>
                        </td>
                        <td>
                            <div>{{ row.mda?.name ?? 'Unresolved' }}</div>
                            <div class="mt-1 text-xs text-ehrmis-muted">{{ row.department?.name ?? 'No department' }}</div>
                            <div class="mt-1 text-xs text-ehrmis-muted">{{ row.station?.name ?? 'No station' }}</div>
                        </td>
                        <td>
                            <div>{{ row.salary_scale?.code ?? 'N/A' }} L{{ row.level ?? 'N/A' }} S{{ row.step ?? 'N/A' }}</div>
                            <div class="mt-1 text-xs text-ehrmis-muted">{{ row.cadre?.name ?? 'No cadre' }}</div>
                            <div class="mt-1 text-xs text-ehrmis-muted">{{ row.rank?.name ?? 'No rank' }}</div>
                        </td>
                        <td>
                            <ImportBatchStatusBadge :status="row.status" />
                        </td>
                        <td>
                            <div class="text-amber-600">{{ row.issue_summary?.warnings_count ?? row.warnings?.length ?? 0 }} warning(s)</div>
                            <div class="mt-1 text-rose-600">{{ row.issue_summary?.errors_count ?? row.errors?.length ?? 0 }} error(s)</div>
                        </td>
                        <td>
                            <AppButton :href="route('legacy-staff-imports.rows.show', [batchId, row.id])" size="sm" variant="outline">
                                Review Row
                            </AppButton>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <AppEmptyState
        v-else
        title="No staged rows matched"
        message="Try broadening the filters or review another import batch."
    />
</template>
