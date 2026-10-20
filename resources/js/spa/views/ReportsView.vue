<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import StaffReportTable from '../components/reports/StaffReportTable.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppLoadingState from '@/Components/AppLoadingState.vue';
import AppAlert from '@/Components/AppAlert.vue';
import { api, apiMessage } from '../lib/api';
import { hasAnyPermission } from '../stores/auth';
import { setPageError } from '../stores/app';
import { reportDateTime } from '../lib/reportFormatting';

const canAccessReports = computed(() => hasAnyPermission(['view-reports', 'export-reports']));
const canExport = computed(() => hasAnyPermission(['export-reports']));
const defaults = () => ({ search: '', mda_id: '', department_id: '', station_ids: [], cadre_ids: [], salary_scale_id: '', status: '', per_page: 500, report_type: 'staff_list', columns: [] });
const filters = reactive(defaults());
const appliedFilters = ref(null);
const options = ref({ mdas: [], departments: [], stations: [], cadres: [], salary_scales: [], statuses: [], columns: [], report_types: [] });
const rows = ref([]);
const columns = ref([]);
const groups = ref([]);
const meta = ref(null);
const loading = ref(false);
const initializing = ref(true);
const exporting = ref(false);
const error = ref('');
const exportError = ref('');
const dirty = computed(() => appliedFilters.value && JSON.stringify(filters) !== JSON.stringify(appliedFilters.value));
const departments = computed(() => options.value.departments.filter((item) => !filters.mda_id || item.mda_id === Number(filters.mda_id)));
const stations = computed(() => options.value.stations.filter((item) => !filters.mda_id || item.mda_id === Number(filters.mda_id)));
const cadres = computed(() => options.value.cadres.filter((item) => filters.department_id
    ? item.department_id === Number(filters.department_id)
    : departments.value.some((department) => department.id === item.department_id)));
const headers = computed(() => [
    { key: 'serial', label: 'S/N' },
    ...columns.value,
]);
const tableRows = computed(() => rows.value.map((row, index) => ({
    ...row,
    serial: (meta.value?.from ?? 1) + index,
    ...Object.fromEntries(row.allowances.map((item) => [
        `allowance_${item.id}`,
        item.eligibility === 'Eligible' ? item.amount ?? 'Rate unavailable' : item.eligibility,
    ])),
})));
const generatedAt = computed(() => meta.value ? reportDateTime(meta.value.generated_at) : '');
const grouped = computed(() => meta.value && meta.value.report_type !== 'staff_list');
const reportGroups = computed(() => {
    const pageGroups = new Map();
    for (const row of tableRows.value) {
        const key = String(row.group_key);
        if (!pageGroups.has(key)) {
            const group = groups.value.find(item => String(item.id) === key);
            pageGroups.set(key, { ...group, rows: [] });
        }
        pageGroups.get(key).rows.push(row);
    }
    return [...pageGroups.values()];
});
const selectAllColumns = () => { filters.columns = options.value.columns.map(column => column.key); };
const clearFilters = () => { Object.assign(filters, defaults()); selectAllColumns(); };

watch(() => filters.mda_id, () => { filters.department_id = ''; filters.station_ids = []; filters.cadre_ids = []; });
watch(() => filters.department_id, () => { filters.cadre_ids = []; });

async function generate(page = 1, useApplied = false) {
    if (loading.value || (!useApplied && !filters.columns.length)) return;
    loading.value = true;
    error.value = '';
    const requested = JSON.parse(JSON.stringify(useApplied ? appliedFilters.value : filters));
    try {
        const { data } = await api.get('/reports/staff-list', { params: { ...requested, page } });
        rows.value = data.data;
        columns.value = data.columns;
        groups.value = data.groups;
        meta.value = data.meta;
        appliedFilters.value = requested;
    } catch (failure) {
        error.value = apiMessage(failure, 'The staff report could not be generated. Please try again.');
    } finally {
        loading.value = false;
    }
}

async function download() {
    if (!meta.value?.total || dirty.value || exporting.value) return;
    exporting.value = true;
    exportError.value = '';
    try {
        const response = await api.get('/reports/staff-list/export', { params: appliedFilters.value, responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = response.headers['content-disposition']?.match(/filename="?([^";]+)"?/)?.[1] ?? 'staff-list.xlsx';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (failure) {
        let message = 'The Excel report could not be downloaded. Please try again.';
        if (failure.response?.data instanceof Blob) {
            try { message = JSON.parse(await failure.response.data.text()).message ?? message; } catch { /* Keep the fallback for non-JSON errors. */ }
        } else {
            message = apiMessage(failure, message);
        }
        exportError.value = message;
    } finally {
        exporting.value = false;
    }
}

onMounted(async () => {
    if (!canAccessReports.value) {
        setPageError('This action is unauthorized.');
        initializing.value = false;
        return;
    }
    try {
        options.value = (await api.get('/reports/staff-list/options')).data.data;
        selectAllColumns();
    } catch (failure) {
        error.value = apiMessage(failure, 'Report filters could not be loaded. Please refresh the page.');
    } finally {
        initializing.value = false;
    }
});
</script>

<template>
    <div v-if="canAccessReports" class="space-y-6">
        <AppPageHeader eyebrow="Reports" title="Staff reports" subtitle="Choose your columns and staff filters, then generate a list or group staff by facility, cadre, rank, or salary grade." :show-breadcrumbs="false">
            <template #actions>
                <AppButton v-if="canExport" :disabled="initializing || loading || exporting || !meta?.total || !!dirty || !!error" @click="download">
                    {{ exporting ? 'Preparing Excel…' : 'Download Excel' }}
                </AppButton>
            </template>
        </AppPageHeader>
        <form class="space-y-6" @submit.prevent="generate()">
            <AppCard title="Choose report" subtitle="Tick the columns to include in the preview and Excel download.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <AppSelect v-model="filters.report_type" label="Report format" :options="options.report_types" option-label="label" option-value="key" :searchable="false" :disabled="initializing || loading" help="Grouped reports list staff under each group, with a staff total. Salary grade uses salary scale and grade level." />
                    <AppSelect v-model="filters.columns" multiple label="Columns to include" :options="options.columns" option-label="label" option-value="key" placeholder="Select columns" :disabled="initializing || loading" :help="`${filters.columns.length} of ${options.columns.length} columns selected. Select at least one column.`" />
                </div>
                <div class="mt-3 flex gap-3">
                    <AppButton variant="outline" :disabled="initializing || loading" @click="selectAllColumns">Select all columns</AppButton>
                    <AppButton variant="outline" :disabled="initializing || loading" @click="filters.columns = []">Clear columns</AppButton>
                </div>
            </AppCard>
            <AppCard title="Choose staff" subtitle="Leave filters open to include all staff you can access.">
                <div class="space-y-4">
                <fieldset :disabled="initializing || loading" class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <AppTextInput v-model="filters.search" label="Name, staff number, CNO or PSN" placeholder="Search staff" />
                    <AppSelect v-model="filters.mda_id" label="MDA" :options="options.mdas" option-label="name" option-value="id" placeholder="All accessible MDAs" />
                    <AppSelect v-model="filters.department_id" label="Department" :options="departments" option-label="name" option-value="id" placeholder="All departments" />
                    <AppSelect v-model="filters.station_ids" multiple label="Stations" :options="stations" option-label="name" option-value="id" placeholder="All stations" :disabled="initializing || loading" />
                    <AppSelect v-model="filters.cadre_ids" multiple label="Cadres" :options="cadres" option-label="name" option-value="id" placeholder="All cadres" :disabled="initializing || loading" />
                    <AppSelect v-model="filters.salary_scale_id" label="Salary scale" :options="options.salary_scales" option-label="code" option-value="id" placeholder="All scales" />
                    <AppSelect v-model="filters.status" label="Staff status" :options="options.statuses.map(value => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))" placeholder="All statuses" />
                    <AppSelect v-model="filters.per_page" label="Preview rows per page" :searchable="false" :options="[20, 50, 100, 250, 500].map(value => ({ value, label: String(value) }))" />
                </fieldset>
                <div class="flex flex-wrap items-center gap-3">
                    <AppButton type="submit" :disabled="initializing || loading || !filters.columns.length">{{ loading ? 'Generating…' : 'Generate report' }}</AppButton>
                    <AppButton variant="outline" :disabled="initializing || loading" @click="clearFilters">Clear filters</AppButton>
                    <span v-if="dirty" class="text-sm text-ehrmis-muted">Report choices changed. Generate the report to apply them.</span>
                </div>
                </div>
            </AppCard>
        </form>
        <AppAlert v-if="error" tone="danger" title="Report unavailable">{{ error }}</AppAlert>
        <AppAlert v-if="exportError" tone="danger" title="Download failed">{{ exportError }}</AppAlert>
        <AppLoadingState v-if="initializing || loading" :lines="5" aria-label="Generating staff report" role="status" />
        <AppEmptyState v-else-if="!meta && !error" title="Generate your staff report" message="Choose a report format, tick your columns, select staff filters, and click Generate report." />
        <AppCard v-else-if="meta" :title="`${meta.total.toLocaleString()} staff in this report`" :subtitle="`Generated ${generatedAt}. Excel includes every matching record.`">

            <AppEmptyState v-if="!rows.length" title="No staff match these filters" message="Change the filters and generate the report again." />

            <div v-if="rows.length && grouped" class="space-y-6">
                <AppCard v-for="group in reportGroups" :key="group.id" :title="group.group_label" :subtitle="`${group.staff_count.toLocaleString()} staff in this group · ${group.rows.length.toLocaleString()} shown on this page`">
                    <StaffReportTable :headers="headers" :rows="group.rows" />
                </AppCard>
            </div>
            <StaffReportTable v-else-if="rows.length" :headers="headers" :rows="tableRows" />
            <div v-if="meta.total" class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm text-ehrmis-muted">Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total.toLocaleString() }} staff · Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <div class="flex gap-2">
                    <AppButton variant="outline" :disabled="loading || meta.current_page <= 1" @click="generate(meta.current_page - 1, true)">Previous</AppButton>
                    <AppButton variant="outline" :disabled="loading || meta.current_page >= meta.last_page" @click="generate(meta.current_page + 1, true)">Next</AppButton>
                </div>
            </div>
        </AppCard>
    </div>
</template>
