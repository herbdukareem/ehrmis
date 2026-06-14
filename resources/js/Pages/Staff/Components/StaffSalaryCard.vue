<script setup>
import AppCard from '@/Components/AppCard.vue';
import MoneyDisplay from '@/Components/MoneyDisplay.vue';
defineProps({ placement: Object, salarySummary: Object });
</script>

<template>
    <AppCard>
        <div class="text-sm font-semibold text-ehrmis-text">Current Salary Placement</div>
        <div class="mt-5 grid gap-4 md:grid-cols-2">
            <div><div class="text-xs font-medium text-ehrmis-muted">Scale</div><div class="mt-1 text-ehrmis-text">{{ placement?.salary_scale_code ?? 'N/A' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Level / Step</div><div class="mt-1 text-ehrmis-text">{{ placement?.level ?? '-' }} / {{ placement?.step ?? '-' }}</div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Basic Salary</div><div class="mt-1"><MoneyDisplay :amount="salarySummary?.basic_salary ?? placement?.basic_salary_snapshot ?? placement?.basic_salary" /></div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Calculated Gross</div><div class="mt-1"><MoneyDisplay :amount="salarySummary?.calculated_gross_salary ?? placement?.calculated_gross_salary_snapshot ?? placement?.gross_salary" /></div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Legacy Gross</div><div class="mt-1"><MoneyDisplay :amount="salarySummary?.legacy_gross_salary ?? placement?.legacy_gross_salary_snapshot" /></div></div>
            <div><div class="text-xs font-medium text-ehrmis-muted">Difference</div><div class="mt-1"><MoneyDisplay :amount="salarySummary?.gross_difference ?? placement?.gross_difference_snapshot" /></div></div>
        </div>
    </AppCard>
</template>
