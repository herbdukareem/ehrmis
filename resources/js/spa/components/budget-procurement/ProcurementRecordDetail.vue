<script setup>
import { computed } from 'vue';
import AppButton from '../../../Components/AppButton.vue';
import AppIcon from '../../../Components/AppIcon.vue';
import AppStatusBadge from '../../../Components/AppStatusBadge.vue';
import MoneyDisplay from '../../../Components/MoneyDisplay.vue';
import ProcurementTable from './ProcurementTable.vue';
import ProcurementDocuments from './ProcurementDocuments.vue';
import { bpPath, dateLabel, dateTimeLabel, words } from '../../lib/budgetProcurement';
import { can } from '../../stores/auth';

const props = defineProps({ area: String, record: Object, mdaId: [String, Number], options: Object, order: Object, busy: Boolean });
const emit = defineEmits(['form', 'refresh']);
const editable = computed(() => ['draft', 'returned'].includes(props.record.status));
const planEditable = computed(() => ['draft', 'approved'].includes(props.record.status) && can('manage-procurement-plans') && cycleWritable.value);
const orderEditable = computed(() => ['issued', 'partially_received'].includes(props.record.status) && can('manage-purchase-orders') && cycleWritable.value);
const cycleWritable = computed(() => {
    const cycle = props.record.cycle || props.record.budget_cycle || props.options.cycles?.find((row) => row.id === props.record.budget_cycle_id);
    return !cycle || !['closed', 'locked'].includes(cycle.status);
});
const sourcingOpen = computed(() => ['published', 'closed'].includes(props.area === 'bids' ? props.record.solicitation?.status : props.record.status));
const launch = (key, record = props.record) => emit('form', { key, record, area: props.area });
const launchLine = (key, row) => launch(key, { ...row, parent_id: props.record.id });
const link = (area, id) => bpPath(area, id, props.mdaId);
const filteredLink = (area, key, id) => ({ ...link(area), query: { mda_id: props.mdaId, [key]: id } });

const facts = computed(() => {
    const row = props.record;
    const entries = [];
    const add = (label, value, type = 'text', to = null) => { if (value !== null && value !== undefined && value !== '') entries.push({ label, value, type, to }); };
    add('Reference', row.invoice_no || row.payment_no || row.requisition_no || row.vendor_no || row.solicitation_no || row.bid_no || row.award_no || row.po_no || row.receipt_no);
    add('Year', row.year);
    if (props.area === 'cycles') {
        add('Allocations', row.allocations_count ?? 0); add('Releases', row.releases_count ?? 0); add('Commitments', row.commitments_count ?? 0);
    }
    if (props.area === 'allocations') {
        add('Account', row.account ? `${row.account.code} - ${row.account.name}` : null);
        add('Cycle', row.cycle ? `${row.cycle.year} - ${row.cycle.name || 'Budget cycle'}` : null, 'text', link('cycles', row.cycle?.id));
        add('Department', row.department?.name);
    }
    if (props.area === 'plans') add('Estimated amount', row.summary?.estimated_amount ?? 0, 'money');
    add('Approved amount', row.approved_amount, 'money'); add('Total amount', row.total_amount, 'money');
    add('Award amount', row.award_amount, 'money'); add('Quoted amount', row.quoted_amount, 'money');
    add('Estimated amount', row.estimated_amount, 'money');
    add('Vendor', row.vendor?.name, 'text', row.vendor && can('view-procurement') ? link('vendors', row.vendor.id) : null);
    add('Budget review', row.budget_review_status, 'status'); add('Inspection', row.inspection_status, 'status');
    add('Procurement method', row.procurement_method ? words(row.procurement_method) : null);
    add('Issue date', row.issue_date ? dateLabel(row.issue_date) : null);
    add('Closing date', row.closing_at ? dateLabel(row.closing_at) : null);
    add('Order date', row.order_date ? dateLabel(row.order_date) : null);
    add('Expected delivery', row.expected_delivery_date ? dateLabel(row.expected_delivery_date) : null);
    add('Received date', row.received_date ? dateLabel(row.received_date) : null);
    add('Delivery note', row.delivery_note_no);
    add('Rejection reason', row.rejection_reason);
    add('Purchase order', row.purchase_order?.po_no, 'text', row.purchase_order && can('view-procurement') ? link('purchase-orders', row.purchase_order.id) : null);
    add('Invoice', row.invoice?.invoice_no, 'text', row.invoice ? link('supplier-invoices', row.invoice.id) : null);
    add('Invoice date', row.invoice_date ? dateLabel(row.invoice_date) : null);
    add('Due date', row.due_date ? dateLabel(row.due_date) : null);
    add('Matched amount', row.matched_amount, 'money'); add('Paid amount', row.paid_amount, 'money');
    add('Outstanding', row.outstanding_amount, 'money'); add('Pending payments', row.reserved_payment_amount, 'money');
    add('Available for payment', row.available_for_payment, 'money'); add('Payment status', row.payment_status, 'status');
    add('Amount', row.amount, 'money'); add('Currency', row.currency);
    add('Payment method', row.method ? words(row.method) : null); add('Payment reference', row.payment_reference);
    add('Payment date', row.paid_date ? dateLabel(row.paid_date) : null);
    add('Overdue', row.overdue ? 'Overdue' : null);
    add('Cancellation reason', row.cancellation_reason);
    add('Solicitation', row.solicitation?.solicitation_no, 'text', row.solicitation ? link('solicitations', row.solicitation.id) : null);
    add('Requisition', row.requisition?.requisition_no, 'text', row.requisition ? link('requisitions', row.requisition.id) : null);
    for (const [label, key] of [['Contact person', 'contact_person'], ['Email', 'email'], ['Phone', 'phone'], ['Address', 'address'], ['Registration', 'registration_number'], ['Tax ID', 'tax_identification_number'], ['Description', 'description'], ['Justification', 'justification'], ['Recommendation', 'recommendation_summary'], ['Terms', 'terms'], ['Notes', 'notes']]) add(label, row[key]);
    return entries;
});
const lineColumns = computed(() => [
    { key: 'item_description', label: 'Item' }, { key: 'quantity', label: 'Quantity' }, { key: 'unit', label: 'Unit' },
    { key: 'estimated_amount', label: 'Estimated amount', type: 'money' },
    { key: 'budget_allocation_id', label: 'Allocation', type: 'allocation' },
    ...(props.area === 'requisitions' ? [{ key: 'budget_check_status', label: 'Budget', type: 'status' }, { key: 'budget_check_summary', label: 'Available', type: 'money' }] : []),
    { key: 'actions', label: '' },
]);
const bidColumns = [{ key: 'bid_no', label: 'Bid' }, { key: 'vendor', label: 'Vendor', type: 'vendor' }, { key: 'quoted_amount', label: 'Quoted', type: 'money' }, { key: 'total_score', label: 'Score' }, { key: 'status', label: 'Status', type: 'status' }, { key: 'actions', label: '' }];
const orderColumns = [{ key: 'item_description', label: 'Item' }, { key: 'quantity', label: 'Ordered' }, { key: 'unit', label: 'Unit' }, { key: 'unit_price', label: 'Unit price', type: 'money' }, { key: 'total_amount', label: 'Amount', type: 'money' }, { key: 'status', label: 'Status', type: 'status' }];
const receiptColumns = [{ key: 'item_description', label: 'Item' }, { key: 'quantity_received', label: 'Received' }, { key: 'quantity_accepted', label: 'Accepted' }, { key: 'quantity_rejected', label: 'Rejected' }, { key: 'accepted_amount', label: 'Accepted amount', type: 'money' }, { key: 'status', label: 'Inspection', type: 'status' }];
const receiptHistoryColumns = [{ key: 'receipt_no', label: 'Receipt' }, { key: 'received_date', label: 'Received', type: 'date' }, { key: 'inspection_status', label: 'Inspection', type: 'status' }, { key: 'status', label: 'Status', type: 'status' }];
const balanceLabels = { allocated_amount: 'Allocated', released_amount: 'Released', committed_amount: 'Committed', actual_amount: 'Expenditure', available_amount: 'Available' };
const historyColumns = [{ key: 'occurred_at', label: 'Date', type: 'date' }, { key: 'actor', label: 'By' }, { key: 'action', label: 'Action' }, { key: 'reason', label: 'Reason' }, { key: 'changes', label: '' }];
const invoiceItemColumns = [{ key: 'item_description', label: 'Item' }, { key: 'receipt_item', label: 'Receipt' }, { key: 'quantity', label: 'Invoiced' }, { key: 'unit_price', label: 'Unit price', type: 'money' }, { key: 'total_amount', label: 'Amount', type: 'money' }, { key: 'actions', label: '' }];
const paymentColumns = [{ key: 'payment_no', label: 'Payment' }, { key: 'amount', label: 'Amount', type: 'money' }, { key: 'method', label: 'Method', type: 'words' }, { key: 'status', label: 'Status', type: 'status' }, { key: 'payment_reference', label: 'Reference' }, { key: 'paid_date', label: 'Paid on', type: 'date' }];
function changes(entry) {
    const result = [];
    const compare = (before = {}, after = {}, prefix = '') => {
        for (const key of new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})])) {
            const oldValue = before?.[key] ?? null;
            const newValue = after?.[key] ?? null;
            if (['updated_at', 'created_at', 'id', 'mda_id'].includes(key) || typeof oldValue === 'object' && oldValue !== null || typeof newValue === 'object' && newValue !== null) continue;
            if (String(oldValue ?? '') !== String(newValue ?? '')) result.push({ field: `${prefix}${words(key)}`, before: oldValue ?? '-', after: newValue ?? '-' });
        }
    };
    compare(entry.before, entry.after);
    for (const key of ['lines', 'items']) {
        const before = entry.before?.[key] ?? [];
        const after = entry.after?.[key] ?? [];
        for (const id of new Set([...before, ...after].map((row) => row.id))) {
            const oldRow = before.find((row) => row.id === id);
            const newRow = after.find((row) => row.id === id);
            compare(oldRow, newRow, `${newRow?.item_description || oldRow?.item_description || `#${id}`}: `);
        }
    }
    return result;
}
</script>

<template>
    <div>
        <div v-if="record.balances" class="bp-balances">
            <div v-for="(label, key) in balanceLabels" :key="key"><span>{{ label }}</span><MoneyDisplay :amount="record.balances[key]" /></div>
        </div>
        <dl class="bp-facts">
            <div v-for="fact in facts" :key="fact.label">
                <dt>{{ fact.label }}</dt><dd>
                    <MoneyDisplay v-if="fact.type === 'money'" :amount="fact.value" :currency="record.currency || 'NGN'" />
                    <AppStatusBadge v-else-if="fact.type === 'status'" :status="fact.value" />
                    <RouterLink v-else-if="fact.to" class="bp-link" :to="fact.to">{{ fact.value }}</RouterLink>
                    <span v-else>{{ fact.value }}</span>
                </dd>
            </div>
        </dl>
        <div class="bp-detail-actions">
            <AppButton v-if="area === 'cycles'" variant="outline" :to="filteredLink('allocations', 'budget_cycle_id', record.id)"><AppIcon name="eye" class="h-4 w-4" />Allocations</AppButton>
            <AppButton v-if="area === 'cycles' && !['closed', 'locked'].includes(record.status) && can('manage-budget-allocations')" @click="launch('allocation')"><AppIcon name="plus" class="h-4 w-4" />New allocation</AppButton>
            <template v-if="area === 'allocations' && record.status === 'approved' && !['closed', 'locked'].includes(record.cycle?.status)">
                <AppButton v-if="can('manage-budget-releases')" @click="launch('release')"><AppIcon name="plus" class="h-4 w-4" />New release</AppButton>
                <AppButton v-if="can('manage-budget-adjustments')" variant="outline" @click="launch('adjustment')"><AppIcon name="plus" class="h-4 w-4" />New adjustment</AppButton>
                <AppButton variant="outline" :to="filteredLink('transactions', 'budget_allocation_id', record.id)"><AppIcon name="document" class="h-4 w-4" />Ledger</AppButton>
            </template>
            <AppButton v-if="area === 'plans' && ['draft', 'approved'].includes(record.status) && cycleWritable && can('create-requisitions')" variant="outline" @click="launch('requisition')"><AppIcon name="plus" class="h-4 w-4" />New requisition</AppButton>
            <AppButton v-if="area === 'plans' && planEditable" variant="outline" @click="launch('amend-plan')"><AppIcon name="pencil" class="h-4 w-4" />Amend plan</AppButton>
            <AppButton v-if="area === 'requisitions' && editable && cycleWritable && can('create-requisitions')" variant="outline" @click="launch('correct-requisition')"><AppIcon name="pencil" class="h-4 w-4" />Correct details</AppButton>
            <AppButton v-if="area === 'purchase-orders' && orderEditable" variant="outline" @click="launch('amend-order')"><AppIcon name="pencil" class="h-4 w-4" />Amend order</AppButton>
            <AppButton v-if="area === 'requisitions' && record.status === 'approved' && can('manage-sourcing')" @click="launch('solicitation')"><AppIcon name="plus" class="h-4 w-4" />Start sourcing</AppButton>
            <AppButton v-if="area === 'awards' && record.status === 'approved' && !record.purchase_order && can('manage-purchase-orders')" @click="launch('purchase-order')"><AppIcon name="document" class="h-4 w-4" />Generate purchase order</AppButton>
            <AppButton v-if="area === 'awards' && record.purchase_order" variant="outline" :to="link('purchase-orders', record.purchase_order.id)"><AppIcon name="eye" class="h-4 w-4" />{{ record.purchase_order.po_no }}</AppButton>
            <AppButton v-if="area === 'purchase-orders' && ['issued', 'partially_received'].includes(record.status) && can('record-procurement-receipts')" @click="launch('receipt')"><AppIcon name="plus" class="h-4 w-4" />Record delivery</AppButton>
            <template v-if="area === 'purchase-orders' && can('view-supplier-payables')">
                <AppButton v-if="can('manage-supplier-invoices')" variant="outline" @click="launch('supplier-invoice')"><AppIcon name="plus" class="h-4 w-4" />New invoice</AppButton>
                <AppButton variant="outline" :to="filteredLink('supplier-invoices', 'purchase_order_id', record.id)"><AppIcon name="document" class="h-4 w-4" />Supplier invoices</AppButton>
            </template>
            <template v-if="area === 'supplier-invoices'">
                <AppButton v-if="editable && can('manage-supplier-invoices')" variant="outline" @click="launch('update-invoice')"><AppIcon name="pencil" class="h-4 w-4" />Update invoice</AppButton>
                <AppButton v-if="record.status === 'approved' && Number(record.available_for_payment) > 0 && can('record-supplier-payments')" @click="launch('supplier-payment')"><AppIcon name="plus" class="h-4 w-4" />New payment</AppButton>
            </template>
            <AppButton v-if="area === 'supplier-payments' && record.status === 'approved' && can('record-supplier-payments')" @click="launch('mark-payment-paid')"><AppIcon name="checkCircle" class="h-4 w-4" />Mark paid</AppButton>
        </div>
    </div>

    <section v-if="['plans', 'requisitions'].includes(area)" class="bp-detail-section">
        <div class="bp-section-heading">
            <h2>Line items <span class="bp-muted">({{ record.lines?.length ?? 0 }})</span></h2>
            <div class="bp-row-actions">
                <template v-if="area === 'plans' && editable && cycleWritable && can('manage-procurement-plans')">
                    <AppButton v-if="can('view-budget-cycles')" variant="outline" size="sm" @click="launch('import-allocations')"><AppIcon name="document" class="h-4 w-4" />Import allocations</AppButton>
                    <AppButton v-if="can('view-workplans')" variant="outline" size="sm" @click="launch('import-workplan')"><AppIcon name="document" class="h-4 w-4" />Import workplan</AppButton>
                    <AppButton size="sm" @click="launch('plan-line')"><AppIcon name="plus" class="h-4 w-4" />Add line</AppButton>
                </template>
                <AppButton v-if="area === 'requisitions' && editable && cycleWritable && can('create-requisitions')" size="sm" @click="launch('requisition-line')"><AppIcon name="plus" class="h-4 w-4" />Add line</AppButton>
            </div>
        </div>
        <ProcurementTable v-if="record.lines?.length" :columns="lineColumns" :rows="record.lines" :options="options">
            <template #budget_check_summary="{ value }"><MoneyDisplay v-if="value" :amount="value.available_amount" /><span v-else>-</span></template>
            <template #actions="{ row }"><div class="bp-row-actions">
                <AppButton v-if="area === 'plans' && planEditable" variant="ghost" size="sm" title="Amend line" aria-label="Amend line" :disabled="busy" @click="launchLine('amend-plan-line', row)"><AppIcon name="pencil" class="h-4 w-4" /></AppButton>
                <template v-if="area === 'requisitions' && editable && cycleWritable && can('create-requisitions')">
                    <AppButton variant="ghost" size="sm" title="Correct line" aria-label="Correct line" :disabled="busy" @click="launchLine('correct-line', row)"><AppIcon name="pencil" class="h-4 w-4" /></AppButton>
                    <AppButton variant="ghost" size="sm" title="Remove line" aria-label="Remove line" :disabled="busy" @click="launchLine('remove-line', row)"><AppIcon name="trash" class="h-4 w-4" /></AppButton>
                </template>
            </div></template>
        </ProcurementTable>
        <p v-else class="bp-muted">No line items.</p>
    </section>

    <section v-if="area === 'solicitations'" class="bp-detail-section">
        <div class="bp-section-heading"><h2>Bids and quotations</h2><AppButton v-if="sourcingOpen && can('manage-sourcing')" size="sm" @click="launch('bid')"><AppIcon name="plus" class="h-4 w-4" />Receive bid</AppButton></div>
        <ProcurementTable v-if="record.bids?.length" :columns="bidColumns" :rows="record.bids" detail-area="bids" :mda-id="mdaId">
            <template #actions="{ row }"><AppButton v-if="sourcingOpen && can('evaluate-bids')" size="sm" variant="outline" @click="launch('evaluate', row)"><AppIcon name="pencil" class="h-4 w-4" />Evaluate</AppButton></template>
        </ProcurementTable>
        <p v-else class="bp-muted">No bids received.</p>
        <template v-if="record.evaluations?.length">
            <div class="bp-section-heading bp-subheading"><h2>Evaluation</h2></div>
            <ProcurementTable :columns="[{ key: 'vendor_id', label: 'Vendor' }, { key: 'technical_score', label: 'Technical' }, { key: 'financial_score', label: 'Financial' }, { key: 'total_score', label: 'Total' }, { key: 'recommended', label: 'Recommendation' }, { key: 'actions', label: '' }]" :rows="record.evaluations">
                <template #vendor_id="{ row }">{{ record.bids?.find((bid) => bid.vendor_id === row.vendor_id)?.vendor?.name || `#${row.vendor_id}` }}</template>
                <template #recommended="{ value }">{{ value ? 'Recommended' : '-' }}</template>
                <template #actions="{ row }"><AppButton v-if="sourcingOpen && can('evaluate-bids')" variant="outline" size="sm" @click="launch('recommend', row)"><AppIcon name="checkCircle" class="h-4 w-4" />Recommend</AppButton></template>
            </ProcurementTable>
        </template>
        <AppButton v-if="record.award" class="mt-4" variant="outline" :to="link('awards', record.award.id)"><AppIcon name="eye" class="h-4 w-4" />{{ record.award.award_no }}</AppButton>
    </section>

    <section v-if="area === 'bids'" class="bp-detail-section">
        <div class="bp-section-heading"><h2>Evaluation</h2><AppButton v-if="sourcingOpen && can('evaluate-bids')" size="sm" @click="launch('evaluate')"><AppIcon name="pencil" class="h-4 w-4" />Evaluate</AppButton></div>
        <dl v-if="record.evaluation" class="bp-facts">
            <div><dt>Technical</dt><dd>{{ record.evaluation.technical_score ?? '-' }}</dd></div><div><dt>Financial</dt><dd>{{ record.evaluation.financial_score ?? '-' }}</dd></div><div><dt>Total</dt><dd>{{ record.evaluation.total_score ?? '-' }}</dd></div><div><dt>Notes</dt><dd>{{ record.evaluation.recommendation_notes || '-' }}</dd></div>
        </dl>
        <p v-else class="bp-muted">Not evaluated.</p>
        <AppButton v-if="record.evaluation && sourcingOpen && can('evaluate-bids')" variant="outline" size="sm" @click="launch('recommend', record.evaluation)"><AppIcon name="checkCircle" class="h-4 w-4" />Recommend award</AppButton>
    </section>

    <section v-if="area === 'purchase-orders'" class="bp-detail-section"><div class="bp-section-heading"><h2>Order items</h2></div><ProcurementTable :columns="[...orderColumns, { key: 'actions', label: '' }]" :rows="record.items"><template #actions="{ row }"><AppButton v-if="orderEditable" variant="ghost" size="sm" title="Amend item" aria-label="Amend item" :disabled="busy" @click="launchLine('amend-order-item', row)"><AppIcon name="pencil" class="h-4 w-4" /></AppButton></template></ProcurementTable></section>
    <section v-if="area === 'purchase-orders'" class="bp-detail-section">
        <div class="bp-section-heading"><h2>Delivery receipts</h2></div>
        <ProcurementTable v-if="record.receipts?.length" :columns="receiptHistoryColumns" :rows="record.receipts" detail-area="receipts" :mda-id="mdaId" />
        <p v-else class="bp-muted">No deliveries recorded.</p>
    </section>
    <section v-if="area === 'receipts'" class="bp-detail-section">
        <div class="bp-section-heading"><h2>Inspection and acceptance</h2><AppButton v-if="record.status === 'draft' && can('record-procurement-receipts')" size="sm" @click="launch('receipt-item')"><AppIcon name="plus" class="h-4 w-4" />Record item</AppButton></div>
        <ProcurementTable v-if="record.items?.length" :columns="receiptColumns" :rows="record.items" />
        <p v-else class="bp-muted">No receipt items.</p>
    </section>
    <ProcurementDocuments v-if="['vendors', 'solicitations', 'bids'].includes(area)" :record="record" :area="area" @refresh="emit('refresh')" />
    <section v-if="area === 'supplier-invoices'" class="bp-detail-section">
        <div class="bp-section-heading"><h2>Invoice matching</h2><AppButton v-if="editable && can('manage-supplier-invoices')" :disabled="busy" size="sm" @click="launch('invoice-item')"><AppIcon name="plus" class="h-4 w-4" />Match receipt item</AppButton></div>
        <ProcurementTable v-if="record.items?.length" :columns="invoiceItemColumns" :rows="record.items.map((row) => ({ ...row, currency: record.currency }))">
            <template #receipt_item="{ value }">{{ value?.receipt?.receipt_no || '-' }}</template>
            <template #actions="{ row }"><div v-if="editable && can('manage-supplier-invoices')" class="bp-row-actions">
                <AppButton variant="ghost" size="sm" title="Edit invoice item" aria-label="Edit invoice item" :disabled="busy" @click="launch('invoice-item', { ...record, selected_receipt_item_id: row.procurement_receipt_item_id })"><AppIcon name="pencil" class="h-4 w-4" /></AppButton>
                <AppButton variant="ghost" size="sm" title="Remove invoice item" aria-label="Remove invoice item" :disabled="busy" @click="launchLine('remove-invoice-item', row)"><AppIcon name="trash" class="h-4 w-4" /></AppButton>
            </div></template>
        </ProcurementTable>
        <p v-else class="bp-muted">No matched receipt items.</p>
    </section>
    <section v-if="area === 'supplier-invoices'" class="bp-detail-section">
        <div class="bp-section-heading"><h2>Payments</h2></div>
        <ProcurementTable v-if="record.payments?.length" :columns="paymentColumns" :rows="record.payments" detail-area="supplier-payments" :mda-id="mdaId" />
        <p v-else class="bp-muted">No payments recorded.</p>
    </section>
    <section v-if="record.history?.length" class="bp-detail-section">
        <div class="bp-section-heading"><h2>Workflow history</h2></div>
        <ProcurementTable :columns="historyColumns" :rows="record.history">
            <template #occurred_at="{ value }">{{ dateTimeLabel(value) }}</template>
            <template #action="{ value }">{{ words(value.split('.').pop()) }}</template>
            <template #changes="{ row }"><details v-if="changes(row).length"><summary class="bp-link">Changes</summary><dl class="bp-audit-changes"><div v-for="(change, index) in changes(row)" :key="index"><dt>{{ change.field }}</dt><dd><del>{{ change.before }}</del> <span aria-hidden="true">&rarr;</span> {{ change.after }}</dd></div></dl></details></template>
        </ProcurementTable>
    </section>
</template>
