<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppTable from '@/Components/AppTable.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppLoadingState from '@/Components/AppLoadingState.vue';
import AppAlert from '@/Components/AppAlert.vue';
import MoneyDisplay from '@/Components/MoneyDisplay.vue';
import { api, apiMessage } from '../lib/api';
import { hasAnyPermission } from '../stores/auth';
import { setPageError } from '../stores/app';
import { reportDate, reportDateTime } from '../lib/reportFormatting';

const canAccessReports = computed(() => hasAnyPermission(['view-reports', 'export-reports']));
const canExport = computed(() => hasAnyPermission(['export-reports']));
const defaults = { search: '', mda_id: '', department_id: '', station_id: '', cadre_id: '', salary_scale_id: '', status: '', per_page: 500 };
const filters = reactive({ ...defaults });
const appliedFilters = ref(null);
const options = ref({ mdas: [], departments: [], stations: [], cadres: [], salary_scales: [], statuses: [] });
const rows = ref([]);
const columns = ref([]);
const allowanceTypes = ref([]);
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
    ...allowanceTypes.value.map((type) => ({ key: `allowance_${type.id}`, label: `${type.name} (NGN)` })),
]);
const tableRows = computed(() => rows.value.map((row, index) => ({
    ...row,
    serial: (meta.value?.from ?? 1) + index,
    ...Object.fromEntries(row.allowances.map((item) => [
        `allowance_${item.id}`,
        item.eligibility === 'Eligible' ? item.amount ?? 'Rate unavailable' : item.eligibility,
    ])),
})));
const moneyColumns = ['basic_salary', 'allowance_total', 'gross_salary'];
const dateColumns = ['date_of_birth', 'date_first_appointment', 'date_last_promotion', 'next_promotion_date', 'expected_retirement_date'];
const text = (value) => value === null || value === undefined || value === '' ? '—' : value;
const date = reportDate;
const generatedAt = computed(() => meta.value ? reportDateTime(meta.value.generated_at) : '');

watch(() => filters.mda_id, () => { filters.department_id = ''; filters.station_id = ''; filters.cadre_id = ''; });
watch(() => filters.department_id, () => { filters.cadre_id = ''; });

async function generate(page = 1, useApplied = false) {
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    const requested = { ...(useApplied ? appliedFilters.value : filters) };
    try {
        const { data } = await api.get('/reports/staff-list', { params: { ...requested, page } });
        rows.value = data.data;
        columns.value = data.columns;
        allowanceTypes.value = data.allowance_types;
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
    } catch (failure) {
        error.value = apiMessage(failure, 'Report filters could not be loaded. Please refresh the page.');
    } finally {
        initializing.value = false;
    }
});
</script>

<template>
    <div v-if="canAccessReports" class="space-y-6">
        <AppPageHeader eyebrow="Reports" title="Staff list report" subtitle="Select your filters, then generate a table with one row per staff member." :show-breadcrumbs="false">
            <template #actions>
                <AppButton v-if="canExport" :disabled="initializing || loading || exporting || !meta?.total || !!dirty || !!error" @click="download">
                    {{ exporting ? 'Preparing Excel…' : 'Download Excel' }}
                </AppButton>
            </template>
        </AppPageHeader>
        <AppCard title="Choose staff" subtitle="Leave filters open to include all staff you can access.">
            <form class="space-y-4" @submit.prevent="generate()">
                <fieldset :disabled="initializing || loading" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <AppTextInput v-model="filters.search" label="Name, staff number, CNO or PSN" placeholder="Search staff" />
                    <AppSelect v-model="filters.mda_id" label="MDA" :options="options.mdas" option-label="name" option-value="id" placeholder="All accessible MDAs" />
                    <AppSelect v-model="filters.department_id" label="Department" :options="departments" option-label="name" option-value="id" placeholder="All departments" />
                    <AppSelect v-model="filters.station_id" label="Station" :options="stations" option-label="name" option-value="id" placeholder="All stations" />
                    <AppSelect v-model="filters.cadre_id" label="Cadre" :options="cadres" option-label="name" option-value="id" placeholder="All cadres" />
                    <AppSelect v-model="filters.salary_scale_id" label="Salary scale" :options="options.salary_scales" option-label="code" option-value="id" placeholder="All scales" />
                    <AppSelect v-model="filters.status" label="Staff status" :options="options.statuses.map(value => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))" placeholder="All statuses" />
                    <AppSelect v-model="filters.per_page" label="Preview rows per page" :searchable="false" :options="[20, 50, 100, 250, 500].map(value => ({ value, label: String(value) }))" />
                </fieldset>
                <div class="flex flex-wrap items-center gap-3">
                    <AppButton type="submit" :disabled="initializing || loading">{{ loading ? 'Generating…' : 'Generate report' }}</AppButton>
                    <AppButton variant="outline" :disabled="initializing || loading" @click="Object.assign(filters, defaults)">Clear filters</AppButton>
                    <span v-if="dirty" class="text-sm text-ehrmis-muted">Filters changed. Generate the report to apply them.</span>
                </div>
            </form>
        </AppCard>
        <AppAlert v-if="error" tone="danger" title="Report unavailable">{{ error }}</AppAlert>
        <AppAlert v-if="exportError" tone="danger" title="Download failed">{{ exportError }}</AppAlert>
        <AppLoadingState v-if="initializing || loading" :lines="5" aria-label="Generating staff report" role="status" />
        <AppEmptyState v-else-if="!meta && !error" title="Generate your staff list" message="Choose the staff filters above and click Generate report. The table will include basic information, appointments, and allowances." />
        <AppCard v-else-if="meta" :title="`${meta.total.toLocaleString()} staff in this report`" :subtitle="`Generated ${generatedAt}. Excel includes every matching record.`">

            <AppEmptyState v-if="!rows.length" title="No staff match these filters" message="Change the filters and generate the report again." />

            <AppTable v-if="rows.length" :headers="headers" :rows="tableRows" class="staff-report-table">
                <template #cell="{ row, value, column }">
                    <template v-if="moneyColumns.includes(column.key) || column.key.startsWith('allowance_')">
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
                    <span v-else-if="dateColumns.includes(column.key)">{{ date(value) }}</span>
                    <strong v-else-if="column.key === 'full_name'">{{ text(value) }}</strong>
                    <span v-else>{{ text(value) }}</span>
                </template>
            </AppTable>
            <div v-if="meta.total" class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm text-ehrmis-muted">Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total.toLocaleString() }} staff · Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <div class="flex gap-2">
                    <AppButton variant="outline" :disabled="meta.current_page <= 1" @click="generate(meta.current_page - 1, true)">Previous</AppButton>
                    <AppButton variant="outline" :disabled="meta.current_page >= meta.last_page" @click="generate(meta.current_page + 1, true)">Next</AppButton>
                </div>
            </div>
        </AppCard>
    </div>
</template>

<style scoped>
.staff-report-table :deep(td), .staff-report-table :deep(th) { white-space: nowrap; }

.staff-report-table :deep(th) { position: sticky; top: 0; z-index: 1; }
</style>
