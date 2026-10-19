export const bpBase = '/budget-procurement';
export const words = (value) => String(value ?? '').replaceAll('_', ' ');
export const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Lagos', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
export const dateLabel = (value) => value ? new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Lagos', day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value)) : '-';
export const dateTimeLabel = (value) => value ? new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Lagos', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '-';
export const recordTitle = (row) => row?.title || row?.name || row?.invoice_no || row?.payment_no || row?.receipt_no || row?.description || `#${row?.id ?? ''}`;
export const bpPath = (area, id, mdaId) => ({ path: `${bpBase}/${area}${id ? `/${id}` : ''}`, query: mdaId ? { mda_id: String(mdaId) } : {} });

const col = (key, label, type = 'text') => ({ key, label, type });
const area = (title, singular, group, headers, create = null) => ({ title, singular, group, headers, create, permission: group === 'budget' ? 'view-budget-cycles' : 'view-procurement' });
export const bpAreas = {
    cycles: area('Budget cycles', 'Budget cycle', 'budget', [col('year', 'Year'), col('name', 'Cycle'), col('status', 'Status', 'status')], 'cycle'),
    allocations: area('Allocations', 'Allocation', 'budget', [col('title', 'Allocation'), col('account', 'Account', 'account'), col('approved_amount', 'Approved amount', 'money'), col('status', 'Status', 'status')], 'allocation'),
    accounts: area('Budget accounts', 'Budget account', 'budget', [col('code', 'Code'), col('name', 'Account'), col('account_type', 'Type', 'words'), col('status', 'Status', 'status')], 'account'),
    releases: area('Releases', 'Release', 'budget', [col('budget_allocation_id', 'Allocation', 'allocation'), col('release_date', 'Release date', 'date'), col('amount', 'Amount', 'money'), col('status', 'Status', 'status')], 'release'),
    adjustments: area('Adjustments', 'Adjustment', 'budget', [col('budget_allocation_id', 'Allocation', 'allocation'), col('adjustment_type', 'Type', 'words'), col('amount', 'Amount', 'money'), col('status', 'Status', 'status')], 'adjustment'),
    commitments: area('Commitments', 'Commitment', 'budget', [col('commitment_no', 'Reference'), col('description', 'Commitment'), col('amount', 'Amount', 'money'), col('status', 'Status', 'status')]),
    transactions: area('Budget ledger', 'Transaction', 'budget', [col('transaction_date', 'Date', 'date'), col('transaction_type', 'Transaction', 'words'), col('description', 'Description'), col('signed_amount', 'Amount', 'money')]),
    plans: area('Procurement plans', 'Procurement plan', 'procurement', [col('title', 'Plan'), col('year', 'Year'), col('summary', 'Estimated amount', 'planAmount'), col('status', 'Status', 'status')], 'plan'),
    requisitions: area('Requisitions', 'Requisition', 'procurement', [col('requisition_no', 'Reference'), col('title', 'Requisition'), col('total_amount', 'Amount', 'money'), col('budget_review_status', 'Budget review', 'status'), col('status', 'Status', 'status')], 'requisition'),
    vendors: area('Vendors', 'Vendor', 'procurement', [col('vendor_no', 'Reference'), col('name', 'Vendor'), col('contact_person', 'Contact'), col('email', 'Email'), col('status', 'Status', 'status')], 'vendor'),
    solicitations: area('Sourcing', 'Solicitation', 'procurement', [col('solicitation_no', 'Reference'), col('title', 'Solicitation'), col('procurement_method', 'Method', 'words'), col('closing_at', 'Closing date', 'date'), col('status', 'Status', 'status')], 'solicitation'),
    awards: area('Awards', 'Award', 'procurement', [col('award_no', 'Reference'), col('vendor', 'Vendor', 'vendor'), col('award_amount', 'Award amount', 'money'), col('status', 'Status', 'status')]),
    'purchase-orders': area('Purchase orders', 'Purchase order', 'procurement', [col('po_no', 'Reference'), col('title', 'Order'), col('vendor', 'Vendor', 'vendor'), col('total_amount', 'Amount', 'money'), col('status', 'Status', 'status')]),
    receipts: area('Receipts', 'Receipt', 'procurement', [col('receipt_no', 'Reference'), col('received_date', 'Received', 'date'), col('purchase_order_id', 'Purchase order', 'po'), col('inspection_status', 'Inspection', 'status'), col('status', 'Status', 'status')], 'receipt'),
    bids: { ...area('Bids', 'Bid', 'procurement', []), hidden: true, detailOnly: true },
    'supplier-invoices': { ...area('Supplier invoices', 'Supplier invoice', 'payables', [col('invoice_no', 'Invoice'), col('vendor', 'Supplier', 'vendor'), col('total_amount', 'Total', 'money'), col('paid_amount', 'Paid', 'money'), col('outstanding_amount', 'Outstanding', 'money'), col('due_date', 'Due', 'date'), col('status', 'Approval', 'status'), col('payment_status', 'Payment', 'status')], 'supplier-invoice'), permission: 'view-supplier-payables' },
    'supplier-payments': { ...area('Supplier payments', 'Supplier payment', 'payables', [col('payment_no', 'Payment'), col('invoice', 'Invoice', 'invoice'), col('vendor', 'Supplier', 'vendor'), col('amount', 'Amount', 'money'), col('method', 'Method', 'words'), col('status', 'Status', 'status'), col('payment_reference', 'Reference'), col('paid_date', 'Paid on', 'date')], 'supplier-payment'), permission: 'view-supplier-payables' },
};

export const operationPermissions = {
    cycle: 'create-budget-cycles', account: 'manage-budget-allocations', allocation: 'manage-budget-allocations',
    release: 'manage-budget-releases', adjustment: 'manage-budget-adjustments', plan: 'manage-procurement-plans',
    requisition: 'create-requisitions', vendor: 'manage-vendors', solicitation: 'manage-sourcing',
    'plan-line': 'manage-procurement-plans', 'requisition-line': 'create-requisitions',
    'import-allocations': 'manage-procurement-plans', 'import-workplan': 'manage-procurement-plans',
    bid: 'manage-sourcing', evaluate: 'evaluate-bids', recommend: 'evaluate-bids',
    'purchase-order': 'manage-purchase-orders', receipt: 'record-procurement-receipts', 'receipt-item': 'record-procurement-receipts',
    'supplier-invoice': 'manage-supplier-invoices', 'invoice-item': 'manage-supplier-invoices', 'supplier-payment': 'record-supplier-payments',
};

const field = (key, label, type = 'text', extra = {}) => ({ key, label, type, required: true, ...extra });
const optional = (key, label, type = 'text', extra = {}) => field(key, label, type, { required: false, ...extra });
const choose = (rows, label) => (rows ?? []).map((row) => ({ value: row.id, label: label(row) }));
const enumOptions = (values) => values.map((value) => ({ value, label: words(value) }));
export const procurementMethods = enumOptions(['direct_purchase', 'rfq', 'selective_tender', 'open_tender', 'emergency_procurement']);
export const bpStatuses = enumOptions(['draft', 'submitted', 'approved', 'open', 'published', 'closed', 'completed', 'cancelled', 'recommended', 'awarded', 'issued', 'partially_received', 'received', 'posted', 'paid', 'active', 'partially_liquidated', 'liquidated', 'released', 'partially_released', 'returned', 'rejected', 'locked']);

export function operationForm(key, { record = {}, options = {}, order = null, area: currentArea } = {}) {
    const cycleOptions = choose(options.cycles?.filter((row) => !['closed', 'locked'].includes(row.status)), (row) => `${row.year} - ${row.name || `Cycle #${row.id}`}`);
    const cycle = optional('budget_cycle_id', 'Budget cycle', 'select', { options: cycleOptions });
    const allocation = optional('budget_allocation_id', 'Budget allocation', 'select', {
        options: choose(options.allocations?.filter((row) => !record.budget_cycle_id || row.budget_cycle_id === record.budget_cycle_id), (row) => `${row.title} (#${row.id})`),
    });
    const department = optional('department_id', 'Department', 'select', { options: choose(options.departments, (row) => row.name) });
    const amount = field('amount', 'Amount (NGN)', 'number', { min: 0.01, step: '0.01' });
    const title = field('title', 'Title');
    const description = optional('description', 'Description', 'textarea');
    const notes = optional('notes', 'Notes', 'textarea');
    const year = field('year', 'Budget year', 'number', { min: 2020, max: 2100, default: new Date().getFullYear() });
    const lines = [field('item_description', 'Item description'), field('quantity', 'Quantity', 'number', { min: 0.0001, step: '0.0001', default: 1 }), optional('unit', 'Unit'), field('estimated_amount', 'Estimated amount (NGN)', 'number', { min: 0.01, step: '0.01' }), optional('estimated_unit_cost', 'Estimated unit cost (NGN)', 'number', { min: 0, step: '0.01' }), allocation, department, optional('specification', 'Specification', 'textarea'), notes];
    const forms = {
        'supplier-invoice': { title: 'New supplier invoice', endpoint: 'supplier-invoices', fields: [field('purchase_order_id', 'Purchase order', 'select', { options: choose(options.invoice_orders, (row) => `${row.po_no} - ${row.title}`), default: currentArea === 'purchase-orders' ? record.id : '' }), field('invoice_no', 'Supplier invoice number'), field('invoice_date', 'Invoice date', 'date', { default: today(), max: today() }), optional('due_date', 'Due date', 'date'), field('total_amount', 'Invoice total', 'number', { min: 0.01, step: '0.01' }), notes], destination: 'supplier-invoices' },
        'invoice-item': { title: 'Match receipt item', endpoint: `supplier-invoices/${record.id}/items`, fields: [field('procurement_receipt_item_id', 'Accepted receipt item', 'select', { default: record.selected_receipt_item_id ?? '', options: choose(record.matching_receipts?.filter((row) => Number(row.available_quantity) > 0), (row) => `${row.receipt_no} - ${row.item_description} (${Number(row.available_quantity)} available)`) }), field('quantity', 'Invoiced quantity', 'number', { min: 0.0001, step: '0.0001' }), optional('unit_price', 'Invoice unit price', 'number', { min: 0.01, step: '0.01' })], matchingReceipts: record.matching_receipts, existingItems: record.items },
        'remove-invoice-item': { title: 'Remove invoice item', endpoint: `supplier-invoices/${record.parent_id}/remove-item`, payload: { item_id: record.id }, fields: [] },
        'supplier-payment': { title: 'New supplier payment', endpoint: (form) => `supplier-invoices/${form.supplier_invoice_id}/payments`, payload: { request_key: options.payment_request_key }, fields: [field('supplier_invoice_id', 'Approved invoice', 'select', { options: choose(options.supplier_invoices?.filter((row) => row.status === 'approved' && Number(row.available_for_payment) > 0), (row) => `${row.invoice_no} (${row.currency} ${row.available_for_payment} available)`), default: currentArea === 'supplier-invoices' ? record.id : '' }), field('amount', 'Payment amount', 'number', { min: 0.01, step: '0.01' }), field('method', 'Payment method', 'select', { options: enumOptions(['bank_transfer', 'cash', 'cheque', 'other']), default: 'bank_transfer' }), notes], destination: 'supplier-payments' },
        'mark-payment-paid': { title: 'Mark payment paid', endpoint: `supplier-payments/${record.id}/mark-paid`, fields: [field('payment_reference', 'Payment reference'), field('paid_date', 'Payment date', 'date', { default: today(), max: today() })] },
        cycle: { title: 'New budget cycle', endpoint: 'cycles', fields: [year, optional('name', 'Cycle name')], destination: 'cycles' },
        account: { title: 'New budget account', endpoint: 'accounts', fields: [field('code', 'Account code'), field('name', 'Account name'), field('account_type', 'Account type', 'select', { options: enumOptions(['personnel', 'recurrent', 'overhead', 'capital']) }), optional('parent_id', 'Parent account', 'select', { options: choose(options.accounts, (row) => `${row.code} - ${row.name}`) }), description] },
        allocation: { title: 'New allocation', endpoint: (form) => `cycles/${form.budget_cycle_id}/allocations`, fields: [{ ...cycle, required: true, default: currentArea === 'cycles' ? record.id : '' }, field('budget_account_id', 'Budget account', 'select', { options: choose(options.accounts, (row) => `${row.code} - ${row.name}`) }), title, field('approved_amount', 'Approved amount (NGN)', 'number', { min: 0, step: '0.01' }), department, description], destination: 'allocations' },
        release: { title: 'New release', endpoint: (form) => `allocations/${form.budget_allocation_id}/releases`, fields: [{ ...allocation, required: true, default: currentArea === 'allocations' ? record.id : '' }, amount, field('release_date', 'Release date', 'date', { default: today() }), notes], destination: 'releases' },
        adjustment: { title: 'New adjustment', endpoint: (form) => `allocations/${form.budget_allocation_id}/adjustments`, fields: [{ ...allocation, required: true, default: currentArea === 'allocations' ? record.id : '' }, field('adjustment_type', 'Adjustment type', 'select', { options: enumOptions(['increase', 'decrease']), default: 'increase' }), amount, field('reason', 'Reason', 'textarea')], destination: 'adjustments' },
        plan: { title: 'New procurement plan', endpoint: 'plans', fields: [title, year, cycle, description], destination: 'plans' },
        requisition: { title: 'New requisition', endpoint: 'requisitions', fields: [title, optional('procurement_plan_id', 'Procurement plan', 'select', { options: choose(options.plans?.filter((row) => ['draft', 'approved'].includes(row.status)), (row) => `${row.year} - ${row.title}`), default: currentArea === 'plans' ? record.id : '' }), cycle, department, description, optional('justification', 'Justification', 'textarea')], destination: 'requisitions' },
        vendor: { title: 'New vendor', endpoint: 'vendors', fields: [field('name', 'Vendor name'), field('vendor_type', 'Vendor type', 'select', { options: enumOptions(['supplier', 'contractor', 'consultant', 'service_provider']), default: 'supplier' }), optional('registration_number', 'Registration number'), optional('tax_identification_number', 'Tax identification number'), optional('contact_person', 'Contact person'), optional('email', 'Email', 'email'), optional('phone', 'Phone'), optional('address', 'Address', 'textarea')], destination: 'vendors' },
        solicitation: { title: 'New solicitation', endpoint: (form) => `requisitions/${form.procurement_requisition_id}/solicitations`, fields: [field('procurement_requisition_id', 'Approved requisition', 'select', { options: choose(options.requisitions, (row) => `${row.requisition_no} - ${row.title}`), default: currentArea === 'requisitions' ? record.id : '' }), field('procurement_method', 'Procurement method', 'select', { options: procurementMethods, default: 'rfq' }), field('issue_date', 'Issue date', 'date', { default: today() }), optional('closing_at', 'Closing date and time', 'datetime-local')], destination: 'solicitations' },
        'plan-line': { title: 'Add plan line', endpoint: `plans/${record.id}/lines`, fields: [...lines, optional('procurement_method', 'Procurement method', 'select', { options: procurementMethods }), optional('planned_start_date', 'Planned start', 'date'), optional('planned_end_date', 'Planned end', 'date')] },
        'requisition-line': { title: 'Add requisition line', endpoint: `requisitions/${record.id}/lines`, fields: [optional('procurement_plan_line_id', 'Procurement plan line', 'select', { options: choose(options.planLines, (row) => row.item_description) }), ...lines] },
        'import-allocations': { title: 'Import approved allocations', endpoint: `plans/${record.id}/import-allocations`, fields: [{ ...cycle, default: record.budget_cycle_id ?? '' }] },
        'import-workplan': { title: 'Import approved workplan', endpoint: `plans/${record.id}/import-workplan`, fields: [field('workplan_id', 'Approved workplan', 'select', { options: choose(options.workplans?.filter((row) => row.year === record.year), (row) => `${row.year} - ${row.title}`) })] },
        bid: { title: 'Receive bid', endpoint: `solicitations/${record.id}/bids`, fields: [field('vendor_id', 'Vendor', 'select', { options: choose(options.vendors, (row) => `${row.vendor_no} - ${row.name}`) }), field('quoted_amount', 'Quoted amount (NGN)', 'number', { min: 0.01, step: '0.01' }), optional('valid_until', 'Valid until', 'date'), notes] },
        evaluate: { title: 'Evaluate bid', endpoint: `bids/${record.id}/evaluate`, fields: [optional('technical_score', 'Technical score', 'number', { min: 0, max: 100, step: '0.01', default: record.technical_score ?? '' }), optional('financial_score', 'Financial score', 'number', { min: 0, max: 100, step: '0.01', default: record.financial_score ?? '' }), optional('rank', 'Rank', 'number', { min: 1, step: 1 }), optional('recommendation_notes', 'Evaluation notes', 'textarea')] },
        recommend: { title: 'Recommend award', endpoint: `evaluations/${record.id}/recommend`, fields: [optional('summary', 'Recommendation summary', 'textarea')], destination: 'awards' },
        'purchase-order': { title: 'Generate purchase order', endpoint: `awards/${record.id}/purchase-order`, fields: [field('order_date', 'Order date', 'date', { default: today() }), optional('expected_delivery_date', 'Expected delivery', 'date'), optional('terms', 'Terms', 'textarea')], destination: 'purchase-orders' },
        receipt: { title: 'New receipt', endpoint: (form) => `purchase-orders/${form.purchase_order_id}/receipts`, fields: [field('purchase_order_id', 'Purchase order', 'select', { options: choose(options.purchase_orders, (row) => `${row.po_no} - ${row.title}`), default: currentArea === 'purchase-orders' ? record.id : '' }), field('received_date', 'Received date', 'date', { default: today() }), optional('delivery_note_no', 'Delivery note number'), notes], destination: 'receipts' },
        'receipt-item': { title: 'Record receipt item', endpoint: `receipts/${record.id}/items`, fields: [field('purchase_order_item_id', 'Purchase order item', 'select', { options: choose(order?.items, (row) => `${row.item_description} (${row.quantity} ${row.unit || 'units'} ordered)`) }), field('quantity_received', 'Received quantity', 'number', { min: 0.0001, step: '0.0001' }), field('quantity_accepted', 'Accepted quantity', 'number', { min: 0, step: '0.0001' }), optional('remarks', 'Inspection remarks', 'textarea')] },
    };
    const reason = field('reason', 'Reason', 'textarea');
    const defaults = (fields) => fields.map((item) => ({ ...item, default: item.type === 'date' ? record[item.key]?.slice(0, 10) : record[item.key] ?? item.default }));
    const amendmentLines = lines.filter((item) => !['budget_allocation_id', 'department_id'].includes(item.key));
    const edits = {
        'update-invoice': { title: 'Update supplier invoice', endpoint: `supplier-invoices/${record.id}/update`, fields: defaults([field('invoice_no', 'Supplier invoice number'), field('invoice_date', 'Invoice date', 'date', { max: today() }), field('total_amount', 'Invoice total', 'number', { min: 0.01, step: '0.01' }), optional('due_date', 'Due date', 'date'), notes]) },
        'correct-requisition': { title: 'Correct requisition', endpoint: `requisitions/${record.id}/correct`, fields: defaults([title, description, optional('justification', 'Justification', 'textarea')]) },
        'correct-line': { title: 'Correct requisition line', endpoint: `requisitions/${record.parent_id}/correct`, payload: { line_id: record.id }, fields: defaults(amendmentLines) },
        'remove-line': { title: 'Remove requisition line', endpoint: `requisitions/${record.parent_id}/correct`, payload: { line_id: record.id, remove_line: true }, fields: [] },
        'amend-plan': { title: 'Amend procurement plan', endpoint: `plans/${record.id}/amend`, fields: defaults([title, description]) },
        'amend-plan-line': { title: 'Amend plan line', endpoint: `plans/${record.parent_id}/amend`, payload: { line_id: record.id }, fields: defaults(amendmentLines) },
        'amend-order': { title: 'Amend purchase order', endpoint: `purchase-orders/${record.id}/amend`, fields: defaults([title, optional('expected_delivery_date', 'Expected delivery', 'date'), optional('terms', 'Terms', 'textarea')]) },
        'amend-order-item': { title: 'Amend order item', endpoint: `purchase-orders/${record.parent_id}/amend`, payload: { item_id: record.id }, fields: defaults([field('quantity', 'Quantity', 'number', { min: 0.0001, step: '0.0001' }), field('unit_price', 'Unit price (NGN)', 'number', { min: 0.01, step: '0.01' })]) },
    };
    return edits[key] ? { ...edits[key], edit: true, fields: [...edits[key].fields, reason] } : forms[key];
}

export function workflowActions(areaKey, record, can, userId = null) {
    const actions = [];
    const add = (label, path, permission, allowed, icon = 'checkCircle') => {
        if (allowed && can(permission)) actions.push({ label, path, icon });
    };
    add('Submit cycle', `cycles/${record.id}/submit`, 'create-budget-cycles', areaKey === 'cycles' && record.status === 'draft');
    add('Approve cycle', `cycles/${record.id}/approve`, 'approve-budget-cycles', areaKey === 'cycles' && record.status === 'submitted');
    add('Approve allocation', `allocations/${record.id}/approve`, 'approve-budget-cycles', areaKey === 'allocations' && record.status === 'draft');
    add('Post release', `releases/${record.id}/post`, 'manage-budget-releases', areaKey === 'releases' && record.status === 'draft');
    add('Approve adjustment', `adjustments/${record.id}/approve`, 'approve-budget-cycles', areaKey === 'adjustments' && record.status === 'draft');
    add('Submit requisition', `requisitions/${record.id}/submit`, 'create-requisitions', areaKey === 'requisitions' && ['draft', 'returned'].includes(record.status) && record.lines?.length);
    add('Review budget', `requisitions/${record.id}/review-budget`, 'review-budget-availability', areaKey === 'requisitions' && ['draft', 'returned', 'submitted', 'budget_reviewed'].includes(record.status), 'wallet');
    add('Approve requisition', `requisitions/${record.id}/approve`, 'approve-requisitions', areaKey === 'requisitions' && ['submitted', 'budget_reviewed'].includes(record.status));
    add('Publish solicitation', `solicitations/${record.id}/publish`, 'manage-sourcing', areaKey === 'solicitations' && record.status === 'draft');
    add('Close solicitation', `solicitations/${record.id}/close`, 'manage-sourcing', areaKey === 'solicitations' && record.status === 'published', 'lockClosed');
    add('Approve award', `awards/${record.id}/approve`, 'approve-awards', areaKey === 'awards' && record.status === 'recommended');
    add('Post receipt', `receipts/${record.id}/post`, 'record-procurement-receipts', areaKey === 'receipts' && record.status === 'draft' && record.items?.length);
    const reasonAction = (label, action, permission, allowed, icon = 'close') => {
        if (allowed && can(permission)) actions.push({ label, path: `${areaKey}/${record.id}/${action}`, icon, reason: true });
    };
    reasonAction('Return for correction', 'return', 'approve-requisitions', areaKey === 'requisitions' && ['submitted', 'budget_reviewed'].includes(record.status), 'chevronLeft');
    reasonAction('Reject requisition', 'reject', 'approve-requisitions', areaKey === 'requisitions' && ['submitted', 'budget_reviewed'].includes(record.status));
    reasonAction('Cancel requisition', 'cancel', 'approve-requisitions', areaKey === 'requisitions' && ['draft', 'returned', 'submitted', 'budget_reviewed', 'approved'].includes(record.status));
    reasonAction('Close requisition', 'close', 'approve-requisitions', areaKey === 'requisitions' && record.status === 'approved', 'lockClosed');
    reasonAction('Reject award', 'reject', 'approve-awards', areaKey === 'awards' && record.status === 'recommended');
    reasonAction('Cancel order', 'cancel', 'manage-purchase-orders', areaKey === 'purchase-orders' && ['issued', 'partially_received'].includes(record.status));
    reasonAction('Close order', 'close', 'manage-purchase-orders', areaKey === 'purchase-orders' && record.status === 'received', 'lockClosed');
    reasonAction('Close plan', 'close', 'manage-procurement-plans', areaKey === 'plans' && ['draft', 'approved'].includes(record.status), 'lockClosed');
    reasonAction('Cancel receipt', 'cancel', 'record-procurement-receipts', areaKey === 'receipts' && record.status === 'draft');
    add('Submit invoice', `supplier-invoices/${record.id}/submit`, 'manage-supplier-invoices', areaKey === 'supplier-invoices' && ['draft', 'returned'].includes(record.status) && record.items?.length);
    add('Approve invoice', `supplier-invoices/${record.id}/approve`, 'approve-supplier-invoices', areaKey === 'supplier-invoices' && record.status === 'submitted');
    reasonAction('Return invoice', 'return', 'approve-supplier-invoices', areaKey === 'supplier-invoices' && record.status === 'submitted', 'chevronLeft');
    reasonAction('Reject invoice', 'reject', 'approve-supplier-invoices', areaKey === 'supplier-invoices' && record.status === 'submitted');
    reasonAction('Cancel invoice', 'cancel', 'approve-supplier-invoices', areaKey === 'supplier-invoices' && ['draft', 'returned', 'submitted', 'approved'].includes(record.status) && Number(record.paid_amount) === 0);
    add('Submit payment', `supplier-payments/${record.id}/submit`, 'record-supplier-payments', areaKey === 'supplier-payments' && record.status === 'draft');
    add('Approve payment', `supplier-payments/${record.id}/approve`, 'approve-supplier-payments', areaKey === 'supplier-payments' && record.status === 'submitted' && Number(record.prepared_by) !== Number(userId));
    reasonAction('Reject payment', 'reject', 'approve-supplier-payments', areaKey === 'supplier-payments' && record.status === 'submitted');
    reasonAction('Cancel payment', 'cancel', 'record-supplier-payments', areaKey === 'supplier-payments' && ['draft', 'submitted', 'approved'].includes(record.status));
    return actions;
}
