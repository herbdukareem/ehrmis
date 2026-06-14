<script setup>
import AppCard from '@/Components/AppCard.vue';
defineProps({ staff: Object });
</script>

<template>
    <AppCard>
        <div class="text-sm font-semibold text-ehrmis-text">Personal Information</div>
        <div class="mt-5 grid gap-4 md:grid-cols-2">
            <div><div class="text-xs font-medium text-ehrmis-muted">Staff No</div><div class="mt-1 font-semibold text-ehrmis-text">{{ staff.staff_number }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Full Name</div><div class="mt-1 font-semibold text-ehrmis-text">{{ staff.full_name }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Sex</div><div class="mt-1 text-ehrmis-text">{{ staff.sex ?? 'N/A' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Date of Birth</div><div class="mt-1 text-ehrmis-text">{{ staff.date_of_birth ?? 'N/A' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Phone</div><div class="mt-1 text-ehrmis-text">{{ staff.personal_detail?.phone ?? 'N/A' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Email</div><div class="mt-1 text-ehrmis-text">{{ staff.personal_detail?.email ?? 'N/A' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">LGA</div><div class="mt-1 text-ehrmis-text">{{ staff.personal_detail?.lga ?? 'N/A' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">State</div><div class="mt-1 text-ehrmis-text">{{ staff.personal_detail?.state_of_origin ?? 'N/A' }}</div></div>
        </div>
    </AppCard>
</template>
