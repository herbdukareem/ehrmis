<script setup>
import AppTable from '../../../Components/AppTable.vue';
import AppStatusBadge from '../../../Components/AppStatusBadge.vue';
import MoneyDisplay from '../../../Components/MoneyDisplay.vue';
import { bpPath, dateLabel, words } from '../../lib/budgetProcurement';

const props = defineProps({ rows: { type: Array, default: () => [] }, columns: Array, detailArea: String, mdaId: [Number, String], options: Object });
const labelFor = (key, value) => {
    const row = props.options?.[key]?.find((item) => String(item.id) === String(value));
    return row?.title || row?.name || row?.po_no || `#${value}`;
};
</script>

<template>
    <AppTable :headers="columns" :rows="rows">
        <template #cell="{ row, value, column }">
            <slot :name="column.key" :row="row" :value="value">
                <RouterLink v-if="detailArea && column.key === columns[0].key" class="bp-link" :to="bpPath(detailArea, row.id, mdaId)">{{ value || `#${row.id}` }}</RouterLink>
                <MoneyDisplay v-else-if="column.type === 'money'" class="whitespace-nowrap" :amount="value ?? 0" :currency="row.currency || 'NGN'" />
                <RouterLink v-else-if="column.type === 'invoice' && value" class="bp-link" :to="bpPath('supplier-invoices', value.id, mdaId)">{{ value.invoice_no }}</RouterLink>
                <AppStatusBadge v-else-if="column.type === 'status'" :status="value || 'pending'" />
                <span v-else-if="column.type === 'date'">{{ dateLabel(value) }}</span>
                <span v-else-if="column.type === 'words'">{{ words(value) || '-' }}</span>
                <span v-else-if="column.type === 'vendor'">{{ value?.name || '-' }}</span>
                <span v-else-if="column.type === 'account'">{{ value?.code }}{{ value?.name ? ` - ${value.name}` : '' }}</span>
                <span v-else-if="column.type === 'allocation'">{{ labelFor('allocations', value) }}</span>
                <span v-else-if="column.type === 'po'">{{ labelFor('purchase_orders', value) }}</span>
                <MoneyDisplay v-else-if="column.type === 'planAmount'" :amount="value?.estimated_amount ?? 0" />
                <span v-else :class="{ 'whitespace-nowrap': column.key === 'unit' || typeof value === 'number' }">{{ value ?? '-' }}</span>
            </slot>
        </template>
    </AppTable>
</template>
