import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdir, readFile } from 'node:fs/promises';
import { extname, resolve, sep } from 'node:path';
import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';

const require = createRequire(import.meta.url);
const { chromium } = require(process.argv[2] || 'playwright');
const manifest = JSON.parse(await readFile(resolve('public/build/manifest.json'), 'utf8'));
const entry = manifest['resources/js/app.js'];
const productionShell = `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="app-environment" content="testing"><title>Budget and procurement</title>${entry.css.map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('')}<script type="module" src="/build/${entry.file}"></script></head><body><div id="app"></div></body></html>`;
const publicRoot = resolve('public');
const mimeTypes = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.woff2': 'font/woff2' };
// Serve built assets independently of local Laravel/Vite server restarts.
const assetServer = createServer(async (request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname.startsWith('/budget-procurement/')) {
        response.writeHead(200, { 'Content-Type': 'text/html' }).end(productionShell);
        return;
    }
    if (pathname === '/sanctum/csrf-cookie') {
        response.writeHead(204).end();
        return;
    }
    const path = resolve(publicRoot, decodeURIComponent(pathname).replace(/^\/+/, ''));
    if (!path.startsWith(`${publicRoot}${sep}`)) {
        response.writeHead(403).end();
        return;
    }
    try {
        const content = await readFile(path);
        response.writeHead(200, { 'Content-Type': mimeTypes[extname(path)] || 'application/octet-stream' }).end(content);
    } catch { response.writeHead(404).end(); }
});
await new Promise((done) => assetServer.listen(0, '127.0.0.1', done));
const base = process.env.BP_BROWSER_URL || `http://127.0.0.1:${assetServer.address().port}`;
const output = resolve('storage/app/private/ui-checks/budget-procurement');
await mkdir(output, { recursive: true });
const permissions = ['view-budget-cycles', 'create-budget-cycles', 'approve-budget-cycles', 'manage-budget-allocations', 'manage-budget-releases', 'manage-budget-adjustments', 'view-procurement', 'manage-procurement-plans', 'create-requisitions', 'approve-requisitions', 'review-budget-availability', 'manage-sourcing', 'evaluate-bids', 'approve-awards', 'manage-purchase-orders', 'record-procurement-receipts', 'manage-vendors', 'view-workplans'];
permissions.push('view-supplier-payables', 'manage-supplier-invoices', 'approve-supplier-invoices', 'record-supplier-payments', 'approve-supplier-payments');
permissions.push('export-procurement-reports');
let currentPermissions = [...permissions];
let currentUserId = 1;
let emptyAllocationAccounts = false;
const mda = { id: 1, code: 'MOH', name: 'Ministry of Health' };
const anotherMda = { id: 2, code: 'HMB', name: 'Hospital Management Board' };
const row = (data) => ({ id: 1, mda_id: 1, ...data });
const item = row({ item_description: 'Examination equipment', quantity: 4, unit: 'units', estimated_amount: 400000, estimated_unit_cost: 100000, unit_price: 100000, total_amount: 400000, budget_allocation_id: 1, budget_check_status: 'available', budget_check_summary: { available_amount: 1000000 }, status: 'ordered' });
const data = {
    cycles: [row({ year: 2026, name: 'Annual health budget', status: 'draft', allocations_count: 1, releases_count: 1, commitments_count: 1 })],
    accounts: [row({ code: '22001', name: 'Medical equipment', account_type: 'capital', status: 'active' })],
    allocations: [row({ title: 'Facility equipment programme', budget_cycle_id: 1, approved_amount: 1000000, status: 'approved' })],
    releases: [row({ budget_allocation_id: 1, amount: 600000, release_date: '2026-10-15', status: 'draft' })],
    adjustments: [row({ budget_allocation_id: 1, amount: 50000, adjustment_type: 'increase', reason: 'Equipment costs', status: 'draft' })],
    commitments: [row({ commitment_no: 'COM-2026-001', description: 'Examination equipment', amount: 400000, status: 'active' })],
    transactions: [row({ transaction_date: '2026-10-15', transaction_type: 'allocation', description: 'Approved equipment budget', signed_amount: 1000000 })],
    plans: [row({ title: 'Facility procurement plan', year: 2026, budget_cycle_id: 1, summary: { estimated_amount: 400000 }, status: 'draft', lines: [structuredClone(item)] })],
    requisitions: [row({ title: 'Equipment request', requisition_no: 'REQ-2026-001', procurement_plan_id: 1, budget_cycle_id: 1, total_amount: 400000, status: 'submitted', budget_review_status: 'available', lines: [structuredClone(item)] })],
    vendors: Array.from({ length: 26 }, (_, index) => row({ id: index + 1, name: `Health Supplies ${index + 1}`, vendor_no: `VEN-${index + 1}`, contact_person: 'Amina Musa', email: 'supply@example.com', status: 'active', documents: [] })),
    solicitations: [row({ title: 'Supply examination equipment', solicitation_no: 'RFQ-2026-001', procurement_requisition_id: 1, procurement_method: 'rfq', estimated_amount: 400000, closing_at: '2026-11-01T12:00:00Z', status: 'published', documents: [], evaluations: [] })],
    bids: [row({ bid_no: 'BID-2026-001', procurement_solicitation_id: 1, vendor_id: 1, quoted_amount: 400000, status: 'submitted', documents: [], total_score: null })],
    awards: [row({ award_no: 'AWD-2026-001', award_amount: 400000, vendor_id: 1, status: 'recommended' })],
    'purchase-orders': [row({ po_no: 'PO-2026-001', title: 'Facility equipment order', total_amount: 400000, vendor_id: 1, status: 'issued', items: [item], receipts: [] })],
    receipts: [row({ receipt_no: 'GRN-2026-001', purchase_order_id: 1, vendor_id: 1, received_date: '2026-10-15', inspection_status: 'pending', status: 'draft', items: [] })],
    'supplier-invoices': [row({ invoice_no: 'INV-2026-001', purchase_order_id: 1, vendor_id: 1, invoice_date: '2026-10-15', due_date: '2026-10-30', currency: 'NGN', total_amount: 200000, matched_amount: 200000, paid_amount: 0, reserved_payment_amount: 0, outstanding_amount: 200000, available_for_payment: 200000, payment_status: 'unpaid', status: 'approved', items: [], payments: [], matching_receipts: [] })],
    'supplier-payments': [row({ payment_no: 'PAY-2026-001', supplier_invoice_id: 1, vendor_id: 1, amount: 100000, currency: 'NGN', method: 'bank_transfer', status: 'submitted', prepared_by: 2 })],
};
function enrich(area, record) {
    if (area === 'allocations') return { ...record, cycle: data.cycles[0], account: data.accounts[0], balances: { allocated_amount: 1000000, released_amount: 600000, committed_amount: 400000, actual_amount: 0, available_amount: 600000 } };
    if (area === 'plans') return { ...record, budget_cycle: data.cycles[0] };
    if (area === 'solicitations') return { ...record, requisition: data.requisitions[0], bids: data.bids.map((bid) => ({ ...bid, vendor: data.vendors[0] })) };
    if (area === 'bids') return { ...record, vendor: data.vendors[0], solicitation: data.solicitations[0] };
    if (area === 'awards') return { ...record, vendor: data.vendors[0], solicitation: data.solicitations[0] };
    if (area === 'purchase-orders') return { ...record, vendor: data.vendors[0] };
    if (area === 'receipts') return { ...record, purchase_order: data['purchase-orders'][0], vendor: data.vendors[0] };
    if (area === 'supplier-invoices') return { ...record, purchase_order: data['purchase-orders'][0], vendor: data.vendors[0], payments: data['supplier-payments'].filter((payment) => payment.supplier_invoice_id === record.id), matching_receipts: [{ id: 1, receipt_no: 'GRN-2026-001', item_description: item.item_description, available_quantity: 2, unit_price: 100000 }] };
    if (area === 'supplier-payments') return { ...record, vendor: data.vendors[0], invoice: data['supplier-invoices'].find((invoice) => invoice.id === record.supplier_invoice_id) };
    return record;
}
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1050 } });
page.setDefaultTimeout(20000);
const errors = [];
const writes = [];
const reportRequests = [];
const notificationWrites = [];
const notificationReads = new Set();
let largeNotificationFixture = false;
const notificationCategories = { approvals: 'Pending approvals', corrections: 'Returns & rejections', invoices: 'Overdue invoices', deliveries: 'Incomplete deliveries', budget: 'Low budget' };
function notificationFixture(mdaId) {
    if (mdaId === 2) return [];
    const specifications = [
        ['invoices', 'supplier-invoices', 'INV-2026-001', 'Supplier invoice overdue', 'Health Supplies 1 / due 2026-10-15', 'danger', 'view-supplier-payables', null, '124999.75'],
        ['corrections', 'requisitions', 'REQ-2026-001', 'Requisition returned', 'Please correct the equipment quantities and attach the revised specification.', 'warning', 'view-procurement'],
        ['corrections', 'awards', 'AWD-2026-001', 'Award rejected', 'The recommended price exceeds the evaluated quotation.', 'danger', 'view-procurement'],
        ['deliveries', 'purchase-orders', 'PO-2026-001', 'Delivery overdue', 'Health Supplies 1 / 1 incomplete order line / due 2026-10-15', 'warning', 'view-procurement'],
        ['budget', 'allocations', 'Equipment programme', 'Low available budget', '7.50% available / Medical equipment', 'warning', 'view-budget-cycles', null, '75000.00'],
        ['approvals', 'requisitions', 'REQ-2026-001', 'Requisition awaiting approval', 'Approval required for REQ-2026-001.', 'info', 'view-procurement', 'approve-requisitions'],
        ['approvals', 'awards', 'AWD-2026-001', 'Award awaiting approval', 'Approval required for AWD-2026-001.', 'info', 'view-procurement', 'approve-awards'],
        ['approvals', 'supplier-invoices', 'INV-2026-002', 'Invoice awaiting approval', 'Approval required for INV-2026-002.', 'info', 'view-supplier-payables', 'approve-supplier-invoices'],
        ['approvals', 'supplier-payments', 'PAY-2026-001', 'Payment awaiting approval', 'Approval required for PAY-2026-001.', 'info', 'view-supplier-payables', 'approve-supplier-payments'],
    ];
    if (largeNotificationFixture) for (let index = 0; index < 26; index++) specifications.push(['approvals', 'requisitions', `REQ-EXTRA-${index + 1}`, 'Requisition awaiting approval', 'Awaiting approval.', 'info', 'view-procurement', 'approve-requisitions']);
    return specifications.map(([category, area, reference, title, message, severity, read, approve, amount], index) => ({ id: String(index + 1).padStart(64, '0'), category, area, reference, title, message, severity, amount, currency: 'NGN', read, approve, record_id: 1, mda_id: 1, url: `/budget-procurement/${area}/1?mda_id=1`, occurred_at: '2026-10-18T12:00:00Z', is_reminder: false }))
        .filter((item) => currentPermissions.includes(item.read) && (!item.approve || currentPermissions.includes(item.approve)))
        .map((item) => ({ ...item, unread: !notificationReads.has(`${currentUserId}:${item.id}`) }));
}
let failReportExport = false;
const reportDefinitions = {
    'budget-utilization': { title: 'Budget utilization', permission: 'view-budget-cycles' },
    'procurement-tracking': { title: 'Procurement tracking', permission: 'view-procurement' },
    'supplier-balances': { title: 'Supplier balances', permission: 'view-supplier-payables' },
    'payment-history': { title: 'Payment history', permission: 'view-supplier-payables' },
};
function reportFixture(type, url, sample = false) {
    const filters = Object.fromEntries(['mda_id', 'vendor_id', 'year', 'date_from', 'date_to'].filter((key) => url.searchParams.get(key)).map((key) => [key, url.searchParams.get(key)]));
    filters.mda_id ||= '1'; filters.date_from ||= '2026-01-01'; filters.date_to ||= '2026-10-31';
    const columns = ({
        'budget-utilization': [['allocation', 'Allocation', 'text'], ...['allocated', 'released', 'committed', 'spent', 'available'].map((key) => [key, key, 'money'])],
        'procurement-tracking': [['reference', 'Requisition', 'link'], ['title', 'Title', 'text'], ['status', 'Current status', 'status'], ['sourcing', 'Sourcing', 'number'], ['awards', 'Awards', 'number'], ['orders', 'Orders', 'number'], ['delivery_progress', 'Delivery', 'percent']],
        'supplier-balances': [['supplier', 'Supplier', 'text'], ['reference', 'Invoice', 'link'], ['due_date', 'Due', 'date'], ['currency', 'Currency', 'text'], ['invoiced', 'Approved amount', 'money'], ['paid', 'Paid to cutoff', 'money'], ['outstanding', 'Outstanding', 'money'], ['overdue', 'Overdue', 'money'], ['aging', 'Age', 'text']],
        'payment-history': [['reference', 'Payment', 'link'], ['supplier', 'Supplier', 'text'], ['invoice_reference', 'Invoice', 'text'], ['currency', 'Currency', 'text'], ['amount', 'Amount', 'money'], ['status', 'Current status', 'status'], ['approved_date', 'Approved', 'date'], ['approved_by', 'Approver', 'text'], ['paid_date', 'Settled', 'date'], ['payment_reference', 'Settlement reference', 'text']],
    })[type].map(([key, label, cellType]) => ({ key, label, type: cellType }));
    const totals = { allocated: '1000000.00', released: '600000.00', committed: '200000.00', spent: '200000.00', available: '600000.00', opening_available: '400000.00', period_spent: '200000.00', invoiced: '200000.00', paid: '75000.25', outstanding: '124999.75', overdue: '124999.75', period_approved: '200000.00', period_paid: '75000.25', days_1_30: '124999.75', settled: '75000.25', approved_pending: '0.00' };
    const count = filters.mda_id === '2' ? 0 : type === 'supplier-balances' ? 26 : 1;
    const allRows = Array.from({ length: count }, (_, index) => ({ id: index + 1, area: ({ 'budget-utilization': 'allocations', 'procurement-tracking': 'requisitions', 'supplier-balances': 'supplier-invoices', 'payment-history': 'supplier-payments' })[type], allocation: 'Facility equipment programme', reference: `${type === 'payment-history' ? 'PAY' : type === 'procurement-tracking' ? 'REQ' : 'INV'}-2026-${index + 1}`, title: 'Facility equipment procurement', supplier: 'Health Supplies 1', currency: 'NGN', ...totals, status: type === 'procurement-tracking' ? 'approved' : 'paid', sourcing: 1, awards: 1, orders: 1, delivery_progress: 50, due_date: '2026-10-20', aging: '11 days overdue', amount: '75000.25', invoice_reference: 'INV-2026-001', approved_date: '2026-10-19', approved_by: 'Amina Musa', paid_date: '2026-10-20', payment_reference: 'BANK-2026-001' }));
    const current = Number(url.searchParams.get('page') || 1);
    const limit = sample ? 5 : 25;
    return { type, title: reportDefinitions[type].title, columns, rows: allRows.slice((current - 1) * limit, current * limit), summary: { count, currencies: count && type !== 'procurement-tracking' ? { NGN: totals } : {}, counts: { sourcing: 1, awards: 1, orders: 1, open_orders: 1 }, delivery_progress: 50, statuses: count && type === 'procurement-tracking' ? { approved: 1 } : {} }, meta: { filters, total: count, current_page: current, per_page: limit, last_page: Math.max(1, Math.ceil(count / limit)), from: count ? (current - 1) * limit + 1 : null, to: Math.min(current * limit, count) } };
}
await page.route('https://fonts.bunny.net/**', (route) => route.fulfill({ contentType: 'text/css', body: '' }));
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => {
    if (failReportExport && message.text().includes('status of 422')) return;
    if (message.type() === 'error' || message.text().includes('[Vue warn]')) errors.push(message.text());
});
await page.route('**/api/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const send = (body, status = 200) => route.fulfill({ status, json: body });
    if (url.pathname === '/api/me') return send({ data: { id: currentUserId, name: 'Budget Officer', roles: ['Budget Officer'], permissions: currentPermissions, enabled_modules: ['budget_procurement', 'workplan_performance'], assigned_mda: mda, accessible_mdas: [mda, anotherMda], branding: { acronym: 'MIS', name: 'Niger State MIS' } } });
    if (url.pathname === '/api/public-context') return send({ data: { acronym: 'MIS', name: 'Niger State MIS' } });
    if (!url.pathname.startsWith('/api/budget-procurement/')) return send({ data: [] });
    const path = url.pathname.replace('/api/budget-procurement/', '');
    if (path.startsWith('notifications')) {
        const action = path.split('/')[1];
        const body = request.method() === 'POST' ? request.postDataJSON() : {};
        const mdaId = Number(body.mda_id || url.searchParams.get('mda_id') || 1);
        const all = notificationFixture(mdaId);
        const category = body.category || url.searchParams.get('category');
        const unread = body.unread || url.searchParams.get('unread');
        const filtered = all.filter((item) => !category || item.category === category).filter((item) => unread !== '1' || item.unread);
        if (request.method() === 'POST') {
            const keys = action === 'read-all' ? filtered.filter((item) => item.unread).map((item) => item.id) : body.keys;
            for (const key of keys) {
                if (action === 'read-all' || body.read) notificationReads.add(`${currentUserId}:${key}`);
                else notificationReads.delete(`${currentUserId}:${key}`);
            }
            notificationWrites.push({ action, body });
            return send({ data: { updated: keys.length } });
        }
        const current = Number(url.searchParams.get('page') || 1);
        const summary = { mda_id: mdaId, unread_count: all.filter((item) => item.unread).length, total_count: all.length };
        if (action === 'summary') return send({ data: summary });
        return send({ data: { ...summary, mdas: [mda, anotherMda], categories: notificationCategories, counts: Object.fromEntries(Object.keys(notificationCategories).map((key) => [key, all.filter((item) => item.category === key).length])), filtered_unread_count: filtered.filter((item) => item.unread).length, rows: filtered.slice((current - 1) * 25, current * 25), meta: { current_page: current, last_page: Math.max(1, Math.ceil(filtered.length / 25)), total: filtered.length } } });
    }
    if (path.startsWith('reports/')) {
        reportRequests.push(url);
        const allowed = Object.fromEntries(Object.entries(reportDefinitions).filter(([, item]) => currentPermissions.includes(item.permission)));
        const [, type, action, format] = path.split('/');
        if (type === 'options') return send({ data: { mda_id: Number(url.searchParams.get('mda_id') || 1), mdas: [mda, anotherMda], vendors: data.vendors, years: [2026], reports: allowed } });
        if (action === 'export') {
            if (failReportExport) return send({ message: 'Narrow the filters before exporting.' }, 422);
            return route.fulfill({ status: 200, contentType: format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', body: 'Test download' });
        }
        if (type === 'dashboard') {
            const reports = Object.fromEntries(Object.keys(allowed).filter((key) => key !== 'budget-utilization' || !url.searchParams.get('vendor_id')).map((key) => [key, reportFixture(key, url, true)]));
            return send({ data: { reports, filters: Object.values(reports)[0].meta.filters } });
        }
        return send({ data: reportFixture(type, url) });
    }
    if (path === 'options') return send({ data: {
        mda_id: Number(url.searchParams.get('mda_id') || 1), mdas: [mda, anotherMda], departments: [{ id: 1, name: 'Administration' }],
        cycles: data.cycles, accounts: emptyAllocationAccounts ? [] : data.accounts, allocations: data.allocations, plans: data.plans,
        requisitions: data.requisitions, vendors: data.vendors, purchase_orders: data['purchase-orders'], workplans: [{ id: 1, year: 2026, title: 'Annual workplan' }],
        invoice_orders: data['purchase-orders'], supplier_invoices: data['supplier-invoices'], invoice_vendors: data.vendors, payment_request_key: randomUUID(),
    } });
    const [area, id, action] = path.split('/');
    if (request.method() === 'GET') {
        if (id) return send({ data: enrich(area, data[area]?.find((item) => String(item.id) === id)) });
        let items = (data[area] || []).map((record) => enrich(area, record));
        items = items.filter((record) => record.mda_id === Number(url.searchParams.get('mda_id') || 1));
        if (url.searchParams.get('search')) items = items.filter((record) => JSON.stringify(record).toLowerCase().includes(url.searchParams.get('search').toLowerCase()));
        if (url.searchParams.get('status')) items = items.filter((record) => record.status === url.searchParams.get('status'));
        const current = Number(url.searchParams.get('page') || 1);
        const total = items.length;
        return send({ data: items.slice((current - 1) * 25, current * 25), current_page: current, last_page: Math.max(1, Math.ceil(total / 25)), total, from: total ? (current - 1) * 25 + 1 : null, to: Math.min(current * 25, total), per_page: 25 });
    }
    let body = {};
    if (request.headers()['content-type']?.includes('application/json')) body = request.postDataJSON();
    writes.push({ path, body });
    if (area === 'supplier-invoices' && !id) {
        const created = row({ id: 2, ...body, total_amount: Number(body.total_amount), status: 'draft', matched_amount: 0, paid_amount: 0, outstanding_amount: Number(body.total_amount), available_for_payment: 0, currency: 'NGN', payment_status: 'unpaid', items: [] });
        data['supplier-invoices'].push(created);
        return send({ data: created }, 201);
    }
    if (area === 'cycles' && !id) {
        const created = row({ id: 2, ...body, year: Number(body.year), status: 'draft' });
        data.cycles.push(created);
        return send({ data: created }, 201);
    }
    const record = data[area]?.find((item) => String(item.id) === id);
    if (area === 'supplier-invoices' && action === 'items') {
        const line = row({ item_description: item.item_description, procurement_receipt_item_id: 1, receipt_item: { receipt: { receipt_no: 'GRN-2026-001' } }, quantity: Number(body.quantity), unit_price: Number(body.unit_price), total_amount: Number(body.quantity) * Number(body.unit_price) });
        record.items = [line]; record.matched_amount = line.total_amount;
        return send({ data: line }, 201);
    }
    if (area === 'supplier-invoices' && action === 'update') {
        Object.assign(record, body, { total_amount: Number(body.total_amount) });
        return send({ data: record });
    }
    if (area === 'supplier-invoices' && action === 'payments') {
        const payment = row({ id: 2, payment_no: 'PAY-2026-002', ...body, amount: Number(body.amount), supplier_invoice_id: record.id, vendor_id: 1, currency: 'NGN', status: 'draft', prepared_by: currentUserId });
        data['supplier-payments'].push(payment);
        return send({ data: payment }, 201);
    }
    if (['supplier-invoices', 'supplier-payments'].includes(area) && ['submit', 'approve', 'mark-paid'].includes(action)) {
        record.status = ({ submit: 'submitted', approve: 'approved', 'mark-paid': 'paid' })[action];
        if (action === 'mark-paid') {
            Object.assign(record, body);
            const invoice = data['supplier-invoices'].find((invoice) => invoice.id === record.supplier_invoice_id);
            invoice.paid_amount += Number(record.amount); invoice.outstanding_amount = invoice.total_amount - invoice.paid_amount;
            invoice.payment_status = invoice.outstanding_amount ? 'partially_paid' : 'paid';
            invoice.available_for_payment = invoice.outstanding_amount;
        } else if (area === 'supplier-invoices' && action === 'approve') record.available_for_payment = record.total_amount;
        return send({ data: record });
    }
    if (['return', 'reject', 'cancel', 'close', 'correct', 'amend'].includes(action) && record) {
        const before = structuredClone(record);
        if (['correct', 'amend'].includes(action)) {
            const target = body.line_id ? record.lines.find((line) => line.id === body.line_id) : body.item_id ? record.items.find((line) => line.id === body.item_id) : record;
            for (const [key, value] of Object.entries(body)) if (key in target && !['id', 'mda_id', 'status'].includes(key)) target[key] = value;
        } else record.status = ({ return: 'returned', reject: 'rejected', cancel: 'cancelled', close: 'closed' })[action];
        record.history = [{ id: writes.length, action, reason: body.reason, actor: 'Budget Officer', occurred_at: '2026-10-19T12:00:00Z', before, after: structuredClone(record) }, ...(record.history ?? [])];
        return send({ data: record });
    }
    if (area === 'requisitions' && action === 'submit') record.status = 'submitted';
    if (area === 'cycles' && action === 'submit') record.status = 'submitted';
    if (area === 'cycles' && action === 'approve') record.status = 'approved';
    if (area === 'plans' && action === 'lines') { record.lines.push(row({ id: 2, ...body })); return send({ data: record.lines.at(-1) }, 201); }
    if (area === 'bids' && action === 'evaluate') {
        const evaluation = row({ ...body, vendor_id: 1, technical_score: Number(body.technical_score), financial_score: Number(body.financial_score), total_score: Number(body.technical_score || 0) + Number(body.financial_score || 0), status: 'evaluated' });
        record.evaluation = evaluation; record.total_score = evaluation.total_score; record.status = 'evaluated';
        data.solicitations[0].evaluations = [evaluation];
        return send({ data: evaluation });
    }
    if (area === 'receipts' && action === 'items') {
        record.items = [row({ ...body, item_description: item.item_description, accepted_amount: Number(body.quantity_accepted) * 100000, quantity_rejected: Number(body.quantity_received) - Number(body.quantity_accepted), status: 'accepted' })];
        return send({ data: record.items[0] }, 201);
    }
    if (area === 'receipts' && action === 'post') record.status = 'posted';
    return send({ data: record || row(body) });
});
async function visit(path) {
    await page.goto(`${base}/budget-procurement/${path}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.locator('.bp-workspace').waitFor();
    await page.getByRole('status', { name: 'Loading', exact: true }).waitFor({ state: 'detached' });
    assert.equal(await page.locator('.bp-error').count(), 0, `Unexpected page error on ${path}`);
    assert.equal(await page.locator('.bp-workspace > .bp-panel.ehrmis-card').count(), 1, `Missing QDS workspace panel on ${path}`);
    assert.equal(await page.locator('.bp-panel .ehrmis-card').count(), 0, `Nested QDS cards on ${path}`);
    assert.equal(await page.locator('.civic-nav-parent[aria-expanded="true"]').count(), 0, `Sidebar group opened by default on ${path}`);
}
async function choose(label, value) {
    const field = page.locator('dialog[open] form > div').filter({ has: page.locator('label', { hasText: label }) });
    await field.locator('button[data-combobox-trigger]').click();
    await field.getByRole('button', { name: value, exact: true }).click();
}
try {
    await visit('cycles');
    await page.evaluate(() => localStorage.setItem('ehrmis.civic-nav.open-groups', JSON.stringify({ 'budget-procurement': true, 'workplan-performance': true })));
    await visit('cycles');
    const budgetMenu = page.getByRole('button', { name: 'Budget & procurement', exact: true });
    await budgetMenu.click();
    assert.equal(await budgetMenu.getAttribute('aria-expanded'), 'true');
    await page.getByRole('link', { name: 'Budget management', exact: false }).click();
    await budgetMenu.click();
    await page.getByRole('link', { name: 'Allocations', exact: true }).click();
    await page.waitForURL('**/budget-procurement/allocations?*');
    assert.equal(await budgetMenu.getAttribute('aria-expanded'), 'false', 'Navigation reopened a manually collapsed group');
    await page.screenshot({ path: `${output}/allocations-qds-panel-desktop.png`, fullPage: true, animations: 'disabled' });
    emptyAllocationAccounts = true;
    await visit('cycles/1');
    await page.getByRole('button', { name: 'New allocation', exact: true }).click();
    await page.locator('dialog[open]').waitFor();
    await page.getByLabel('Budget account *', { exact: true }).click();
    await page.getByText('No matches found.', { exact: true }).waitFor({ timeout: 5000 });
    await page.screenshot({ path: `${output}/allocation-empty-account-desktop.png`, fullPage: false, animations: 'disabled' });
    await page.getByLabel('Title *', { exact: true }).fill('Unblocked allocation form', { timeout: 5000 });
    await page.getByLabel('Approved amount (NGN) *', { exact: true }).fill('50000', { timeout: 5000 });
    await page.getByRole('button', { name: 'Cancel', exact: true }).click({ timeout: 5000 });
    await page.locator('dialog[open]').waitFor({ state: 'hidden', timeout: 5000 });
    await page.getByRole('button', { name: 'New allocation', exact: true }).click();
    await page.getByLabel('Budget account *', { exact: true }).click();
    await page.getByText('No matches found.', { exact: true }).waitFor({ timeout: 5000 });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByLabel('Title *', { exact: true }).fill('Responsive allocation form', { timeout: 5000 });
    await page.getByRole('button', { name: 'Cancel', exact: true }).click({ timeout: 5000 });
    await page.locator('dialog[open]').waitFor({ state: 'hidden', timeout: 5000 });
    await page.setViewportSize({ width: 1440, height: 1050 });
    emptyAllocationAccounts = false;
    await visit('cycles/1');
    await page.getByRole('button', { name: 'New allocation', exact: true }).click();
    await choose('Budget account *', '22001 - Medical equipment');
    await page.getByLabel('Title *', { exact: true }).fill('Selectable allocation form');
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    await visit('notifications');
    await page.getByRole('link', { name: 'Notifications: 9 unread', exact: true }).waitFor();
    await page.getByText('Please correct the equipment quantities and attach the revised specification.', { exact: true }).waitFor();
    await page.screenshot({ path: `${output}/notifications-desktop.png`, fullPage: true, animations: 'disabled' });
    await page.getByRole('button', { name: 'Mark read', exact: true }).first().click();
    await page.getByRole('link', { name: 'Notifications: 8 unread', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Mark unread', exact: true }).first().click();
    await page.getByRole('link', { name: 'Notifications: 9 unread', exact: true }).waitFor();
    await page.getByRole('link', { name: 'Unread (9)', exact: true }).click();
    await page.getByRole('button', { name: 'Mark all read', exact: true }).click();
    await page.getByRole('heading', { name: 'No notifications', exact: true }).waitFor();
    await page.getByRole('link', { name: 'All (9)', exact: true }).click();
    await page.locator('.bp-notification-filters [data-combobox-trigger]').nth(1).click();
    await page.getByRole('button', { name: 'Returns & rejections (2)', exact: true }).click();
    await page.waitForURL('**/*category=corrections*');
    await page.getByText('Award rejected', { exact: true }).waitFor();
    assert.equal(await page.getByText('Low available budget', { exact: true }).count(), 0);
    largeNotificationFixture = true;
    await visit('notifications');
    await page.getByRole('button', { name: 'Next page', exact: true }).click();
    await page.getByRole('link', { name: 'REQ-EXTRA-26', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Previous page', exact: true }).click();
    await page.getByText('Supplier invoice overdue', { exact: true }).waitFor();
    largeNotificationFixture = false;
    await visit('notifications');
    await page.locator('.bp-notification-filters [data-combobox-trigger]').first().click();
    await page.getByRole('button', { name: 'Hospital Management Board', exact: true }).click();
    await page.getByRole('heading', { name: 'No notifications', exact: true }).waitFor();
    notificationReads.clear();
    await page.setViewportSize({ width: 390, height: 844 });
    await visit('notifications');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Notifications overflow on mobile');
    await page.screenshot({ path: `${output}/notifications-mobile.png`, fullPage: true, animations: 'disabled' });
    await page.getByRole('link', { name: 'Supplier invoice overdue', exact: true }).click();
    await page.waitForURL('**/supplier-invoices/1?mda_id=1');
    assert.equal(notificationWrites.at(-1).action, 'read');
    await page.setViewportSize({ width: 1440, height: 1050 });
    await visit('reports');
    assert.equal(await page.locator('.bp-report-section').count(), 4);
    await page.screenshot({ path: `${output}/reports-dashboard-desktop.png`, fullPage: true, animations: 'disabled' });
    for (const type of Object.keys(reportDefinitions)) await visit(`reports/${type}`);
    await visit('reports/supplier-balances');
    await page.getByRole('button', { name: 'Next page', exact: true }).click();
    await page.getByRole('link', { name: 'INV-2026-26', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Previous page', exact: true }).click();
    await page.getByRole('link', { name: 'INV-2026-1', exact: true }).waitFor();
    await page.screenshot({ path: `${output}/supplier-balances-desktop.png`, fullPage: true, animations: 'disabled' });
    await page.getByLabel('Financial year', { exact: true }).fill('2025');
    assert.equal(await page.getByRole('button', { name: 'Excel', exact: true }).isDisabled(), true);
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await page.waitForURL('**/*year=2025*');
    await page.getByRole('button', { name: 'Excel', exact: true }).waitFor();
    for (const format of ['Excel', 'PDF']) {
        const pending = page.waitForEvent('download');
        await page.getByRole('button', { name: format, exact: true }).click();
        const download = await pending;
        assert.match(download.suggestedFilename(), /supplier-balances-2026-10-31\.(xlsx|pdf)$/);
        assert.equal(reportRequests.at(-1).searchParams.get('year'), '2025');
        assert.equal(reportRequests.at(-1).searchParams.has('page'), false);
    }
    failReportExport = true;
    await page.getByRole('button', { name: 'PDF', exact: true }).click();
    await page.getByRole('alert').filter({ hasText: 'Narrow the filters' }).waitFor();
    failReportExport = false;
    await visit('reports/supplier-balances?mda_id=1&vendor_id=1');
    await page.locator('.bp-report-filters [data-combobox-trigger]').first().click();
    await page.getByRole('button', { name: 'Hospital Management Board', exact: true }).click();
    await page.getByRole('heading', { name: 'No matching records', exact: true }).waitFor();
    assert.equal(new URL(page.url()).searchParams.has('vendor_id'), false);
    await page.setViewportSize({ width: 390, height: 844 });
    for (const type of ['dashboard', ...Object.keys(reportDefinitions)]) {
        await visit(type === 'dashboard' ? 'reports' : `reports/${type}`);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Report ${type} overflows on mobile`);
        if (['dashboard', 'supplier-balances', 'payment-history'].includes(type)) await page.screenshot({ path: `${output}/report-${type}-mobile.png`, fullPage: true, animations: 'disabled' });
    }
    await page.setViewportSize({ width: 1440, height: 1050 });
    for (const area of ['cycles', 'accounts', 'allocations', 'releases', 'adjustments', 'commitments', 'transactions', 'plans', 'requisitions', 'vendors', 'solicitations', 'awards', 'purchase-orders', 'receipts', 'supplier-invoices', 'supplier-payments']) await visit(area);
    for (const area of ['cycles', 'allocations', 'plans', 'requisitions', 'vendors', 'solicitations', 'bids', 'awards', 'purchase-orders', 'receipts', 'supplier-invoices', 'supplier-payments']) await visit(`${area}/1`);
    await visit('allocations/1');
    await page.screenshot({ path: `${output}/allocation-desktop.png`, fullPage: true, animations: 'disabled' });
    await visit('cycles');
    await page.getByRole('button', { name: 'New budget cycle', exact: true }).click();
    await page.getByLabel('Budget year *', { exact: true }).fill('2027');
    await page.getByLabel('Cycle name', { exact: true }).fill('Annual procurement budget');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL('**/cycles/2?*');
    await page.getByRole('button', { name: 'Submit cycle', exact: true }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Submit cycle', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    await visit('requisitions/1');
    await page.getByRole('button', { name: 'Return for correction', exact: true }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Return for correction', exact: true }).click();
    await page.getByRole('alert').filter({ hasText: 'A reason is required.' }).waitFor();
    await page.getByLabel('Reason *', { exact: true }).fill('Please correct the quantity');
    await page.locator('dialog[open]').getByRole('button', { name: 'Return for correction', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    await page.getByRole('heading', { name: 'Workflow history', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Correct line', exact: true }).click();
    await page.getByLabel('Quantity *', { exact: true }).fill('3');
    await page.getByLabel('Reason *', { exact: true }).fill('Corrected equipment count');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(writes.find((write) => write.path === 'requisitions/1/correct').body.line_id, 1);
    await page.getByText('Changes', { exact: true }).first().click();
    await page.screenshot({ path: `${output}/requisition-history-desktop.png`, fullPage: true, animations: 'disabled' });
    await page.getByRole('button', { name: 'Submit requisition', exact: true }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Submit requisition', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    await visit('awards/1');
    await page.getByRole('button', { name: 'Reject award', exact: true }).click();
    await page.getByLabel('Reason *', { exact: true }).fill('Review the recommended price');
    await page.locator('dialog[open]').getByRole('button', { name: 'Reject award', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(await page.getByRole('button', { name: 'Approve award', exact: true }).count(), 0);
    await visit('purchase-orders/1');
    await page.getByRole('button', { name: 'Amend order', exact: true }).click();
    await page.getByLabel('Terms', { exact: true }).fill('Delivery to the central store');
    await page.getByLabel('Reason *', { exact: true }).fill('Updated delivery location');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(writes.find((write) => write.path === 'purchase-orders/1/amend').body.terms, 'Delivery to the central store');
    await page.getByRole('button', { name: 'Cancel order', exact: true }).click();
    await page.getByLabel('Reason *', { exact: true }).fill('Supplier cannot complete delivery');
    await page.locator('dialog[open]').getByRole('button', { name: 'Cancel order', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(await page.getByRole('button', { name: 'Record delivery', exact: true }).count(), 0);
    assert.equal(await page.getByRole('button', { name: 'Amend order', exact: true }).count(), 0);
    await visit('cycles/2');
    await page.getByRole('button', { name: 'Approve cycle', exact: true }).waitFor();
    assert.equal(writes.find((write) => write.path === 'cycles').body.mda_id, 1);
    await visit('plans/1');
    await page.getByRole('button', { name: 'Add line', exact: true }).click();
    await page.getByLabel('Item description *', { exact: true }).fill('Diagnostic examination equipment');
    await page.getByLabel('Quantity *', { exact: true }).fill('2');
    await page.getByLabel('Estimated amount (NGN) *', { exact: true }).fill('250000');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.getByRole('cell', { name: 'Diagnostic examination equipment', exact: true }).waitFor();
    await visit('solicitations/1');
    await page.getByRole('button', { name: 'Evaluate', exact: true }).click();
    await page.getByLabel('Technical score', { exact: true }).fill('45');
    await page.getByLabel('Financial score', { exact: true }).fill('40');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.getByRole('button', { name: 'Recommend', exact: true }).waitFor();
    await page.screenshot({ path: `${output}/sourcing-desktop.png`, fullPage: true, animations: 'disabled' });
    data['purchase-orders'][0].status = 'issued';
    await visit('receipts/1');
    await page.getByRole('button', { name: 'Record item', exact: true }).click();
    await choose('Purchase order item *', 'Examination equipment (4 units ordered)');
    await page.getByLabel('Received quantity *', { exact: true }).fill('2');
    await page.getByLabel('Accepted quantity *', { exact: true }).fill('2');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.getByRole('cell', { name: 'Examination equipment', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Post receipt', exact: true }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Post receipt', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    data['purchase-orders'][0].status = 'received';
    await visit('purchase-orders/1');
    await page.getByRole('button', { name: 'Close order', exact: true }).click();
    await page.getByLabel('Reason *', { exact: true }).fill('All deliveries accepted');
    await page.locator('dialog[open]').getByRole('button', { name: 'Close order', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(await page.getByRole('button', { name: 'Close order', exact: true }).count(), 0);
    await visit('supplier-invoices');
    await page.getByRole('button', { name: 'New supplier invoice', exact: true }).click();
    await choose('Purchase order *', 'PO-2026-001 - Facility equipment order');
    await page.getByLabel('Supplier invoice number *', { exact: true }).fill('INV-2026-002');
    await page.getByLabel('Invoice total *', { exact: true }).fill('200000');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL('**/supplier-invoices/2?*');
    await page.getByRole('button', { name: 'Update invoice', exact: true }).click();
    await page.getByLabel('Supplier invoice number *', { exact: true }).fill('INV-2026-002A');
    await page.getByLabel('Reason *', { exact: true }).fill('Corrected supplier invoice reference');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    await page.getByRole('heading', { name: 'INV-2026-002A', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Match receipt item', exact: true }).click();
    await choose('Accepted receipt item *', 'GRN-2026-001 - Examination equipment (2 available)');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(writes.find((write) => write.path === 'supplier-invoices/2/items').body.quantity, 2);
    await page.getByRole('button', { name: 'Edit invoice item', exact: true }).click();
    assert.equal(await page.getByLabel('Invoiced quantity *', { exact: true }).inputValue(), '2');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    for (const name of ['Submit invoice', 'Approve invoice']) {
        await page.getByRole('button', { name, exact: true }).click();
        await page.locator('dialog[open]').getByRole('button', { name, exact: true }).click();
        await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    }
    await page.getByRole('button', { name: 'New payment', exact: true }).click();
    await page.getByLabel('Payment amount *', { exact: true }).fill('75000.25');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL('**/supplier-payments/2?*');
    assert.match(writes.find((write) => write.path === 'supplier-invoices/2/payments').body.request_key, /^[a-f0-9-]{36}$/);
    await page.getByRole('button', { name: 'Submit payment', exact: true }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Submit payment', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(await page.getByRole('button', { name: 'Approve payment', exact: true }).count(), 0, 'Maker must not approve own payment');
    currentUserId = 2;
    await visit('supplier-payments/2');
    await page.getByRole('button', { name: 'Approve payment', exact: true }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Approve payment', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    currentUserId = 1;
    await visit('supplier-payments/2');
    await page.getByRole('button', { name: 'Mark paid', exact: true }).click();
    await page.getByLabel('Payment reference *', { exact: true }).fill('BANK-2026-001');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(await page.getByRole('button', { name: 'Mark paid', exact: true }).count(), 0);
    await visit('supplier-invoices/2');
    await page.getByText('partially paid', { exact: true }).waitFor();
    await page.screenshot({ path: `${output}/supplier-invoice-desktop.png`, fullPage: true, animations: 'disabled' });
    await visit('vendors');
    await page.getByRole('button', { name: 'Next page', exact: true }).click();
    await page.getByRole('link', { name: 'VEN-26', exact: true }).waitFor();
    await page.locator('.bp-filters').getByLabel('Search', { exact: true }).fill('Supplies 1');
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await page.waitForURL('**/*search=*');
    await page.getByRole('button', { name: 'Clear filters', exact: true }).click();
    await page.locator('.bp-filters button[data-combobox-trigger]').first().click();
    await page.getByRole('button', { name: 'HMB - Hospital Management Board', exact: true }).click();
    await page.getByRole('heading', { name: 'No vendors', exact: true }).waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    await visit('supplier-invoices/2');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Invoice screen overflows on mobile');
    await page.screenshot({ path: `${output}/supplier-invoice-mobile.png`, fullPage: true, animations: 'disabled' });
    await visit('supplier-payments/2');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Payment screen overflows on mobile');
    await page.screenshot({ path: `${output}/supplier-payment-mobile.png`, fullPage: true, animations: 'disabled' });
    await visit('purchase-orders/1');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Mobile document overflows horizontally');
    await page.screenshot({ path: `${output}/purchase-order-mobile.png`, fullPage: true, animations: 'disabled' });
    await visit('requisitions/1');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Workflow actions overflow on mobile');
    await page.getByRole('button', { name: 'Reject requisition', exact: true }).click();
    await page.getByLabel('Reason *', { exact: true }).fill('This request is not required');
    await page.screenshot({ path: `${output}/requisition-reject-mobile.png`, fullPage: false, animations: 'disabled' });
    await page.locator('dialog[open]').getByRole('button', { name: 'Reject requisition', exact: true }).click();
    await page.locator('dialog[open]').waitFor({ state: 'hidden' });
    assert.equal(await page.getByRole('button', { name: 'Submit requisition', exact: true }).count(), 0);
    await visit('vendors');
    await page.getByRole('button', { name: 'New vendor', exact: true }).click();
    await page.locator('dialog[open]').waitFor();
    await page.screenshot({ path: `${output}/vendor-form-mobile.png`, fullPage: false, animations: 'disabled' });
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    currentPermissions = ['view-procurement'];
    await visit('reports');
    assert.equal(await page.locator('.bp-report-section').count(), 1);
    assert.equal(await page.getByRole('link', { name: 'Supplier balances', exact: true }).count(), 0);
    await visit('reports/procurement-tracking');
    assert.equal(await page.getByRole('button', { name: 'Excel', exact: true }).count(), 0);
    await visit('vendors');
    assert.equal(await page.getByRole('button', { name: 'New vendor', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Menu', exact: true }).click();
    await page.getByRole('button', { name: 'Budget & procurement', exact: true }).click();
    assert.equal(await page.getByRole('link', { name: 'Budget management', exact: true }).count(), 0);
    await visit('receipts/1');
    assert.equal(await page.getByRole('button', { name: 'Post receipt', exact: true }).count(), 0);
    assert.equal(await page.getByRole('button', { name: 'Record item', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Menu', exact: true }).click();
    await page.getByRole('button', { name: 'Budget & procurement', exact: true }).click();
    assert.equal(await page.getByRole('link', { name: 'Supplier invoices', exact: true }).count(), 0);
    currentPermissions = ['view-supplier-payables'];
    await visit('reports');
    assert.equal(await page.locator('.bp-report-section').count(), 2);
    assert.equal(await page.getByRole('link', { name: 'Budget utilization', exact: true }).count(), 0);
    await visit('supplier-invoices/2');
    assert.equal(await page.getByRole('button', { name: 'New payment', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Menu', exact: true }).click();
    await page.getByRole('button', { name: 'Budget & procurement', exact: true }).click();
    assert.equal(await page.getByRole('link', { name: 'Budget management', exact: true }).count(), 0);
    assert.deepEqual(errors, [], 'Browser errors or Vue warnings');
    console.log(JSON.stringify({ result: 'passed', listViews: 16, detailViews: 12, reportViews: 5, reportDownloads: 2, notificationActions: notificationWrites.length, mutations: writes.length, screenshots: output }));
} catch (error) {
    await page.screenshot({ path: `${output}/failure.png`, fullPage: true, timeout: 5000 }).catch(() => {});
    console.error(JSON.stringify({ error: error.message, browserErrors: errors }));
    throw error;
} finally {
    await browser.close();
    await new Promise((done) => assetServer.close(done));
}
