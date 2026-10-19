<script setup>
import AppTable from '@/Components/AppTable.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import MoneyDisplay from '@/Components/MoneyDisplay.vue';
import { bpPath, words } from '../../lib/budgetProcurement';

defineProps({ report: { type: Object, required: true }, mdaId: [String, Number] });
const metrics = {
    'budget-utilization': { allocated: 'Allocated', released: 'Released', committed: 'Committed', spent: 'Expenditure', available: 'Available', opening_available: 'Opening available', period_spent: 'Period expenditure' },
    'supplier-balances': { invoiced: 'Approved invoices', paid: 'Paid to cutoff', outstanding: 'Outstanding', overdue: 'Overdue', period_approved: 'Approved in period', period_paid: 'Paid in period' },
    'payment-history': { settled: 'Settled in period', approved_pending: 'Selected approvals pending at cutoff' },
};
const aging = { not_due: 'Not due / undated', days_1_30: '1-30 days', days_31_60: '31-60 days', days_61_90: '61-90 days', days_90_plus: 'Over 90 days' };
</script>

<template>
    <div class="bp-report-panel">
        <template v-for="(totals, currency) in report.summary.currencies" :key="currency">
            <dl v-if="metrics[report.type]" class="bp-report-metrics">
                <div v-for="(label, key) in metrics[report.type]" :key="key">
                    <dt>{{ label }} <span class="bp-muted">{{ currency }}</span></dt>
                    <dd :class="{ 'bp-report-danger': key === 'overdue' && Number(totals[key]) > 0 }"><MoneyDisplay :amount="totals[key] ?? 0" :currency="currency" /></dd>
                </div>
            </dl>
            <div v-if="report.type === 'supplier-balances'" class="bp-report-aging" :aria-label="`Outstanding aging ${currency}`">
                <div v-for="(label, key) in aging" :key="key"><span>{{ label }}</span><progress :value="Number(totals[key] || 0)" :max="Math.max(1, Number(totals.outstanding))" :aria-label="`${label} ${currency}`" /><MoneyDisplay :amount="totals[key] ?? 0" :currency="currency" /></div>
            </div>
        </template>
        <dl v-if="report.type === 'procurement-tracking'" class="bp-report-metrics">
            <div><dt>Requisitions</dt><dd>{{ report.summary.count }}</dd></div>
            <div v-for="key in ['sourcing', 'awards', 'orders', 'open_orders']" :key="key"><dt>{{ words(key) }}</dt><dd>{{ report.summary.counts[key] ?? 0 }}</dd></div>
            <div><dt>Mean line delivery</dt><dd>{{ report.summary.delivery_progress }}%</dd></div>
        </dl>
        <div v-if="Object.keys(report.summary.statuses).length" class="bp-report-statuses"><span v-for="(count, status) in report.summary.statuses" :key="status"><AppStatusBadge :status="status" /> <strong>{{ count }}</strong></span></div>
        <AppTable v-if="report.rows.length" :headers="report.columns" :rows="report.rows">
            <template #cell="{ row, column, value }">
                <MoneyDisplay v-if="column.type === 'money'" :amount="value" :currency="row.currency || 'NGN'" />
                <AppStatusBadge v-else-if="column.type === 'status'" :status="value" />
                <RouterLink v-else-if="column.type === 'link'" :to="bpPath(row.area, row.id, mdaId)" class="bp-link">{{ value }}</RouterLink>
                <span v-else-if="column.type === 'percent'" class="bp-report-delivery"><progress :value="value" max="100" aria-label="Accepted delivery" />{{ value }}%</span>
                <span v-else>{{ value || (value === 0 ? '0' : '-') }}</span>
            </template>
        </AppTable>
        <AppEmptyState v-else title="No matching records" />
    </div>
</template>
