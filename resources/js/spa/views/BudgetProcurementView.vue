<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppButton from '../../Components/AppButton.vue';
import AppCard from '../../Components/AppCard.vue';
import AppEmptyState from '../../Components/AppEmptyState.vue';
import AppIcon from '../../Components/AppIcon.vue';
import AppModal from '../../Components/AppModal.vue';
import AppPageHeader from '../../Components/AppPageHeader.vue';
import AppSelect from '../../Components/AppSelect.vue';
import AppSkeleton from '../../Components/AppSkeleton.vue';
import AppStatusBadge from '../../Components/AppStatusBadge.vue';
import AppTabs from '../../Components/AppTabs.vue';
import AppTextInput from '../../Components/AppTextInput.vue';
import MoneyDisplay from '../../Components/MoneyDisplay.vue';
import ProcurementFormDialog from '../components/budget-procurement/ProcurementFormDialog.vue';
import ProcurementRecordDetail from '../components/budget-procurement/ProcurementRecordDetail.vue';
import ProcurementTable from '../components/budget-procurement/ProcurementTable.vue';
import { api, apiMessage } from '../lib/api';
import { bpAreas, bpBase, bpPath, bpStatuses, operationForm, operationPermissions, recordTitle, workflowActions, words } from '../lib/budgetProcurement';
import { auth, can } from '../stores/auth';
import { pushToast } from '../stores/app';

const route = useRoute();
const router = useRouter();
const area = computed(() => route.meta.area || 'cycles');
const config = computed(() => bpAreas[area.value] || bpAreas.cycles);
const isDetail = computed(() => Boolean(route.params.id));
const detailAreas = ['cycles', 'allocations', 'plans', 'requisitions', 'vendors', 'solicitations', 'awards', 'purchase-orders', 'receipts', 'bids', 'supplier-invoices', 'supplier-payments'];
const options = ref({});
const mdaId = ref('');
const rows = ref([]);
const record = ref(null);
const order = ref(null);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const busy = ref(true);
const preparing = ref(false);
const saving = ref(false);
const feedback = ref('');
const dialog = ref(false);
const schema = ref(null);
const errors = ref({});
const confirmation = ref(null);
const actionReason = ref('');
const filters = reactive({ search: '', status: '', year: '', budget_cycle_id: '', budget_allocation_id: '', procurement_plan_id: '', purchase_order_id: '', supplier_invoice_id: '', vendor_id: '', payment_status: '', overdue: '' });
let loadVersion = 0;

const tabs = computed(() => Object.entries(bpAreas).filter(([, item]) => item.group === config.value.group && !item.hidden && can(item.permission))
    .map(([key, item]) => ({ label: item.title, to: bpPath(key, null, mdaId.value), active: key === area.value })));
const mdaOptions = computed(() => (options.value.mdas ?? []).map((row) => ({ value: row.id, label: `${row.code} - ${row.name}` })));
const selectedMda = computed(() => options.value.mdas?.find((row) => row.id === Number(mdaId.value)));
const headerTitle = computed(() => isDetail.value && record.value ? recordTitle(record.value) : config.value.title);
const canCreate = computed(() => !isDetail.value && config.value.create && can(operationPermissions[config.value.create]));
const actions = computed(() => record.value ? workflowActions(area.value, record.value, can, auth.user?.id) : []);
const columns = computed(() => [...config.value.headers, ...(detailAreas.includes(area.value) || ['releases', 'adjustments'].includes(area.value) ? [{ key: 'actions', label: '' }] : [])]);
const activeFilterKeys = computed(() => ({
    cycles: ['year'], plans: ['year'], allocations: ['budget_cycle_id'],
    releases: ['budget_allocation_id'], adjustments: ['budget_allocation_id'], commitments: ['budget_allocation_id'],
    transactions: ['budget_cycle_id', 'budget_allocation_id'], requisitions: ['procurement_plan_id'], receipts: ['purchase_order_id'],
    'supplier-invoices': ['vendor_id', 'purchase_order_id', 'payment_status', 'overdue'], 'supplier-payments': ['supplier_invoice_id', 'vendor_id'],
}[area.value] ?? []));
const lookupFilters = computed(() => activeFilterKeys.value.filter((key) => key !== 'year').map((key) => ({
    key, label: { budget_cycle_id: 'Budget cycle', budget_allocation_id: 'Allocation', procurement_plan_id: 'Plan', purchase_order_id: 'Purchase order', vendor_id: 'Supplier', supplier_invoice_id: 'Invoice', payment_status: 'Payment status', overdue: 'Due status' }[key],
    options: key === 'payment_status' ? ['unpaid', 'partially_paid', 'paid'].map((value) => ({ value, label: words(value) })) : key === 'overdue' ? [{ value: '1', label: 'Overdue' }] : (options.value[{ budget_cycle_id: 'cycles', budget_allocation_id: 'allocations', procurement_plan_id: 'plans', purchase_order_id: config.value.group === 'payables' ? 'invoice_orders' : 'purchase_orders', vendor_id: 'invoice_vendors', supplier_invoice_id: 'supplier_invoices' }[key]] ?? []).map((row) => ({ value: row.id, label: row.invoice_no || row.title || row.name || row.po_no || String(row.year) })),
})));
const backLink = computed(() => area.value === 'bids' && record.value?.procurement_solicitation_id ? bpPath('solicitations', record.value.procurement_solicitation_id, mdaId.value) : bpPath(area.value, null, mdaId.value));

async function load() {
    const version = ++loadVersion;
    busy.value = true;
    feedback.value = '';
    rows.value = [];
    record.value = null;
    order.value = null;
    for (const key of Object.keys(filters)) filters[key] = route.query[key] || '';
    const targetArea = area.value;
    const targetId = route.params.id;
    try {
        if (targetId) {
            const response = await api.get(`${bpBase}/${targetArea}/${targetId}`);
            if (version !== loadVersion) return;
            record.value = response.data.data;
        }
        const requestedMda = record.value?.mda_id || route.query.mda_id;
        const lookups = await api.get(`${bpBase}/options`, { params: requestedMda ? { mda_id: requestedMda } : {} });
        if (version !== loadVersion) return;
        options.value = lookups.data.data;
        mdaId.value = options.value.mda_id;
        if (targetId && targetArea === 'receipts') {
            const response = await api.get(`${bpBase}/purchase-orders/${record.value.purchase_order_id}`);
            if (version !== loadVersion) return;
            order.value = response.data.data;
        } else if (!targetId) {
            const params = { mda_id: mdaId.value, page: route.query.page || 1, per_page: 25 };
            for (const key of ['search', ...(targetArea !== 'transactions' ? ['status'] : []), ...activeFilterKeys.value]) if (filters[key]) params[key] = filters[key];
            const response = await api.get(`${bpBase}/${targetArea}`, { params });
            if (version !== loadVersion) return;
            rows.value = response.data.data;
            pagination.value = response.data;
        }
    } catch (error) {
        if (version !== loadVersion) return;
        feedback.value = error.response?.status === 404 ? 'This record is not available.' : apiMessage(error);
    } finally { if (version === loadVersion) busy.value = false; }
}

function applyFilters() {
    const query = { mda_id: mdaId.value };
    for (const key of ['search', ...(area.value !== 'transactions' ? ['status'] : []), ...activeFilterKeys.value]) if (filters[key]) query[key] = filters[key];
    router.push({ path: route.path, query });
}
function changeMda(id) { router.push(bpPath(area.value, null, id)); }
function changePage(page) { router.push({ path: route.path, query: { ...route.query, mda_id: mdaId.value, page } }); }
function clearFilters() { router.push(bpPath(area.value, null, mdaId.value)); }

async function openForm({ key, record: target = record.value || {}, area: contextArea = area.value }) {
    if (preparing.value || saving.value) return;
    preparing.value = true;
    feedback.value = '';
    errors.value = {};
    try {
        if (key === 'requisition-line' && target.procurement_plan_id) {
            const response = await api.get(`${bpBase}/plans/${target.procurement_plan_id}`);
            options.value = { ...options.value, planLines: response.data.data.lines };
        }
        schema.value = operationForm(key, { record: target, options: options.value, order: order.value, area: contextArea });
        dialog.value = true;
    } catch (error) { feedback.value = apiMessage(error); }
    finally { preparing.value = false; }
}

function formErrors(error) {
    const fieldErrors = error.response?.data?.errors ?? {};
    return { ...fieldErrors, _general: [Object.values(fieldErrors).flat().join(' ') || apiMessage(error)] };
}

async function saveForm(values) {
    saving.value = true;
    errors.value = {};
    try {
        const endpoint = typeof schema.value.endpoint === 'function' ? schema.value.endpoint(values) : schema.value.endpoint;
        const response = await api.post(`${bpBase}/${endpoint}`, { ...values, ...schema.value.payload, mda_id: mdaId.value });
        const destination = schema.value.destination;
        const result = response.data.data;
        dialog.value = false;
        pushToast(schema.value.title.startsWith('Import') ? `${result.lines_synced ?? 0} lines imported.` : 'Record saved.');
        if (destination && detailAreas.includes(destination) && result.id) {
            await router.push(bpPath(destination, result.id, mdaId.value));
        } else if (destination && ['releases', 'adjustments'].includes(destination)) {
            await router.push({ ...bpPath(destination, null, mdaId.value), query: { mda_id: mdaId.value, budget_allocation_id: values.budget_allocation_id } });
        } else { await load(); }
    } catch (error) { errors.value = formErrors(error); }
    finally { saving.value = false; }
}

function confirmAction(action, target = record.value) {
    errors.value = {};
    actionReason.value = '';
    confirmation.value = { ...action, record: target };
}
async function performAction() {
    if (saving.value) return;
    if (confirmation.value.reason && !actionReason.value.trim()) {
        errors.value = { _general: ['A reason is required.'] };
        return;
    }
    saving.value = true;
    errors.value = {};
    try {
        const response = await api.post(`${bpBase}/${confirmation.value.path}`, { mda_id: mdaId.value, ...(confirmation.value.reason ? { reason: actionReason.value.trim() } : {}) });
        pushToast(confirmation.value.path.endsWith('review-budget') ? `Budget review: ${words(response.data.data.status)}.` : 'Workflow updated.');
        confirmation.value = null;
        await load();
    } catch (error) { errors.value = formErrors(error); }
    finally { saving.value = false; }
}

watch(() => route.fullPath, () => { dialog.value = false; confirmation.value = null; load(); }, { immediate: true });
onBeforeUnmount(() => { loadVersion++; });
</script>

<template>
    <div class="bp-workspace">
        <AppButton v-if="isDetail" :to="backLink" variant="ghost" size="sm" class="bp-back"><AppIcon name="chevronLeft" class="h-4 w-4" />{{ area === 'bids' ? 'Solicitation' : config.title }}</AppButton>
        <AppPageHeader :title="headerTitle" eyebrow="Budget & procurement" :subtitle="selectedMda ? `${selectedMda.code} / ${selectedMda.name}` : ''" :show-breadcrumbs="false" :show-back-link="false">
            <template #actions>
                <AppStatusBadge v-if="record?.status" :status="record.status" />
                <AppButton v-for="action in actions" :key="action.path" :disabled="busy || saving || preparing" @click="confirmAction(action)"><AppIcon :name="action.icon" class="h-4 w-4" />{{ action.label }}</AppButton>
                <AppButton v-if="canCreate" :disabled="busy || preparing || saving" @click="openForm({ key: config.create })"><AppIcon name="plus" class="h-4 w-4" />New {{ config.singular.toLowerCase() }}</AppButton>
                <AppButton variant="outline" :disabled="busy || saving" title="Refresh" aria-label="Refresh" @click="load"><AppIcon name="refresh" class="h-4 w-4" /></AppButton>
            </template>
        </AppPageHeader>
        <AppCard class="bp-panel" :aria-label="config.title">
        <AppTabs :tabs="tabs" />
        <p v-if="feedback" class="bp-error" role="alert">{{ feedback }}</p>
        <AppSkeleton v-if="busy" :rows="isDetail ? 5 : 7" />
        <template v-else-if="!feedback">
            <template v-if="!isDetail">
                <div>
                    <form class="bp-filters" @submit.prevent="applyFilters">
                        <AppSelect :model-value="mdaId" label="MDA" :options="mdaOptions" @update:model-value="changeMda" />
                        <AppTextInput v-model="filters.search" type="search" label="Search" placeholder="Reference or name" />
                        <AppSelect v-if="area !== 'transactions'" v-model="filters.status" label="Status" :options="[{ value: '', label: 'All statuses' }, ...bpStatuses]" :searchable="false" />
                        <AppTextInput v-if="activeFilterKeys.includes('year')" v-model="filters.year" type="number" min="2020" max="2100" label="Year" />
                        <AppSelect v-for="filter in lookupFilters" :key="filter.key" v-model="filters[filter.key]" :label="filter.label" :options="[{ value: '', label: 'All' }, ...filter.options]" />
                        <div class="bp-filter-actions"><AppButton type="submit" variant="outline"><AppIcon name="filter" class="h-4 w-4" />Apply</AppButton><AppButton variant="ghost" title="Clear filters" aria-label="Clear filters" @click="clearFilters"><AppIcon name="close" class="h-4 w-4" /></AppButton></div>
                    </form>
                    <ProcurementTable v-if="rows.length" :columns="columns" :rows="rows" :detail-area="detailAreas.includes(area) ? area : null" :mda-id="mdaId" :options="options">
                        <template #actions="{ row }">
                            <div class="bp-row-actions">
                                <AppButton v-if="detailAreas.includes(area)" variant="ghost" size="sm" :to="bpPath(area, row.id, mdaId)" :title="`Open ${config.singular.toLowerCase()}`" :aria-label="`Open ${config.singular.toLowerCase()}`"><AppIcon name="chevronRight" class="h-4 w-4" /></AppButton>
                                <AppButton v-for="action in ['releases', 'adjustments'].includes(area) ? workflowActions(area, row, can) : []" :key="action.path" variant="outline" size="sm" :disabled="saving" @click="confirmAction(action, row)"><AppIcon :name="action.icon" class="h-4 w-4" />{{ action.label }}</AppButton>
                            </div>
                        </template>
                    </ProcurementTable>
                    <AppEmptyState v-else :title="`No ${config.title.toLowerCase()}`" message="No records match the selected MDA and filters." icon="document" />
                    <div v-if="pagination.total" class="bp-pagination">
                        <span>{{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}</span>
                        <div class="bp-row-actions"><AppButton variant="outline" size="sm" title="Previous page" aria-label="Previous page" :disabled="pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)"><AppIcon name="chevronLeft" class="h-4 w-4" /></AppButton><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><AppButton variant="outline" size="sm" title="Next page" aria-label="Next page" :disabled="pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)"><AppIcon name="chevronRight" class="h-4 w-4" /></AppButton></div>
                    </div>
                </div>
            </template>
            <ProcurementRecordDetail v-else-if="record" :area="area" :record="record" :order="order" :options="options" :mda-id="mdaId" :busy="saving || preparing" @form="openForm" @refresh="load" />
        </template>
        </AppCard>
        <ProcurementFormDialog :show="dialog" :schema="schema" :busy="saving" :errors="errors" :options="options" @close="dialog = false" @submit="saveForm" />
        <AppModal :show="Boolean(confirmation)" :title="confirmation?.label" :closeable="!saving" @close="confirmation = null">
            <p class="bp-confirm-title">{{ recordTitle(confirmation?.record) }}</p>
            <MoneyDisplay v-if="confirmation?.record?.approved_amount ?? confirmation?.record?.amount ?? confirmation?.record?.total_amount ?? confirmation?.record?.award_amount" :amount="confirmation.record.approved_amount ?? confirmation.record.amount ?? confirmation.record.total_amount ?? confirmation.record.award_amount" :currency="confirmation.record.currency || 'NGN'" />
            <label v-if="confirmation?.reason" class="bp-textarea-label mt-4"><span class="ehrmis-label">Reason *</span><textarea v-model="actionReason" class="ehrmis-input" rows="3" required maxlength="5000" /></label>
            <p v-if="errors._general" class="bp-error mt-4" role="alert">{{ errors._general[0] }}</p>
            <template #footer><AppButton variant="outline" :disabled="saving" @click="confirmation = null">Cancel</AppButton><AppButton :disabled="saving" @click="performAction"><AppIcon name="checkCircle" class="h-4 w-4" />{{ saving ? 'Processing...' : confirmation?.label }}</AppButton></template>
        </AppModal>
    </div>
</template>
