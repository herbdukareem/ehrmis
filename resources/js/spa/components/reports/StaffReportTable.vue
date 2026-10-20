<script setup>
import AppTable from '@/Components/AppTable.vue';
import MoneyDisplay from '@/Components/MoneyDisplay.vue';
import { reportDate } from '../../lib/reportFormatting';

defineProps({ headers: { type: Array, required: true }, rows: { type: Array, required: true } });
const moneyColumns = ['basic_salary', 'allowance_total', 'gross_salary'];
const dateColumns = ['date_of_birth', 'date_first_appointment', 'date_last_promotion', 'next_promotion_date', 'expected_retirement_date'];
const isMoney = key => moneyColumns.includes(key) || /^allowance_\d+$/.test(key);
const text = value => value === null || value === undefined || value === '' ? '—' : value;
</script>

<template>
    <AppTable :headers="headers" :rows="rows" class="staff-report-table">
        <template #cell="{ row, value, column }">
            <template v-if="isMoney(column.key)">
                <MoneyDisplay v-if="typeof value === 'number'" :amount="value" />
                <span v-else>{{ value ?? 'Rate unavailable' }}</span>
                <span
                    v-if="typeof value === 'number' && ['allowance_total', 'gross_salary'].includes(column.key) && row.unpriced_allowances?.length"
                    class="mt-1 block text-xs text-ehrmis-muted"
                    :title="`Unpriced allowances: ${row.unpriced_allowances.join(', ')}`"
                >
                    Excludes {{ row.unpriced_allowances.length }} unpriced allowance{{ row.unpriced_allowances.length === 1 ? '' : 's' }}
                </span>
            </template>
            <span v-else-if="dateColumns.includes(column.key)">{{ reportDate(value) }}</span>
            <strong v-else-if="column.key === 'full_name'">{{ text(value) }}</strong>
            <span v-else>{{ text(value) }}</span>
        </template>
    </AppTable>
</template>

<style scoped>
.staff-report-table :deep(td), .staff-report-table :deep(th) { white-space: nowrap; }
.staff-report-table :deep(th) { position: sticky; top: 0; z-index: 1; }
</style>
