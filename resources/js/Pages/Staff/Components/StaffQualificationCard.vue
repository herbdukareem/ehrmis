<script setup>
import AppCard from '@/Components/AppCard.vue';
defineProps({ qualifications: Array });
</script>

<template>
    <AppCard>
        <div class="text-sm font-semibold text-ehrmis-text">Qualifications</div>
        <div class="mt-4 space-y-4">
            <div
                v-for="qualification in qualifications ?? []"
                :key="qualification.id"
                class="rounded-ehrmis border border-ehrmis-border px-4 py-3"
            >
                <div class="font-semibold text-ehrmis-text">{{ qualification.highest_qualification_name ?? qualification.qualification_name ?? 'Unnamed Qualification' }}</div>
                <div class="mt-1 text-sm text-ehrmis-muted">{{ qualification.qualification_type_name ?? qualification.qualification_type_code ?? 'Unmapped Type' }}</div>
                <div class="mt-1 text-sm text-ehrmis-muted">{{ qualification.specialization ?? 'No specialization recorded' }}</div>
            </div>
            <div
                v-if="!(qualifications?.length)"
                class="text-sm text-ehrmis-muted"
            >
                No qualification records yet.
            </div>
        </div>
    </AppCard>
</template>
