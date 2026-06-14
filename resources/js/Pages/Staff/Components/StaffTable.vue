<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';

defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="ehrmis-table-wrap">
        <div class="overflow-x-auto">
            <table class="ehrmis-table">
                <thead>
                    <tr>
                        <th>Staff No</th>
                        <th>Name</th>
                        <th>Sex</th>
                        <th>MDA</th>
                        <th>Department</th>
                        <th>Station</th>
                        <th>Cadre</th>
                        <th>Rank</th>
                        <th>Salary</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td class="font-medium">{{ row.staff_number }}</td>
                        <td>{{ row.full_name }}</td>
                        <td>{{ row.sex ?? 'N/A' }}</td>
                        <td>{{ row.mda?.code ?? 'N/A' }}</td>
                        <td>{{ row.department ?? 'Unassigned' }}</td>
                        <td>{{ row.station ?? 'Unassigned' }}</td>
                        <td>{{ row.cadre ?? 'Unassigned' }}</td>
                        <td>{{ row.rank ?? 'Unassigned' }}</td>
                        <td>{{ row.salary_display ?? 'N/A' }}</td>
                        <td><AppStatusBadge :status="row.status" /></td>
                        <td>
                            <AppButton :href="route('staff.show', row.id)" size="sm" variant="outline">
                                View Detail
                            </AppButton>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
