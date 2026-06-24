<script setup>
import AppCard from '@/Components/AppCard.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
defineProps({ employment: Object });

const formatDate = (value) => {
    if (!value) return 'N/A';

    return new Date(value).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};
</script>

<template>
    <AppCard>
        <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-semibold text-ehrmis-text">Current Employment</div>
            <AppStatusBadge :status="employment?.employment_status ?? 'inactive'" />
        </div>
        <div class="mt-5 grid gap-4 md:grid-cols-2">
            <div><div class="text-xs font-medium text-ehrmis-muted">MDA</div><div class="mt-1 text-ehrmis-text">{{ employment?.mda_name ?? 'N/A' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Department</div><div class="mt-1 text-ehrmis-text">{{ employment?.department_name ?? 'Unassigned' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Station</div><div class="mt-1 text-ehrmis-text">{{ employment?.station_name ?? 'Unassigned' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Cadre</div><div class="mt-1 text-ehrmis-text">{{ employment?.cadre_name ?? 'Unassigned' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Rank</div><div class="mt-1 text-ehrmis-text">{{ employment?.rank_name ?? 'Unassigned' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Location</div><div class="mt-1 text-ehrmis-text">{{ employment?.location_name ?? 'N/A' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">First Appointment</div><div class="mt-1 text-ehrmis-text">{{ formatDate(employment?.date_first_appointment) }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Last Promotion</div><div class="mt-1 text-ehrmis-text">{{ formatDate(employment?.date_last_promotion) }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Retirement Date</div><div class="mt-1 text-ehrmis-text">{{ formatDate(employment?.expected_retirement_date) }}</div></div>
        </div>
    </AppCard>
</template>
