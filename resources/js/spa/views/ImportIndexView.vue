<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { can } from '../stores/auth';

const rows = ref([]);
const meta = ref(null);
const options = ref({});
const busy = ref(true);
const uploadBusy = ref('');
const feedback = ref('');
const error = ref('');
const selectedType = ref('departments');
const selectedFile = shallowRef(null);
const selectedFileName = ref('');
const fileInput = ref(null);
const refreshTimer = ref(null);
const filters = reactive({ status: '', source_table: '', page: 1 });
const importTypes = [
    { id: 'departments', title: 'Departments', description: 'Import departments belonging to your MDA before uploading cadres and ranks.' },
    { id: 'stations', title: 'Stations', description: 'Import work stations belonging to your MDA.' },
    { id: 'cadres', title: 'Cadres', description: 'Import cadres linked to an accessible department and salary scale.' },
    { id: 'ranks', title: 'Ranks', description: 'Import ranks linked to an existing cadre, department, salary scale, and level.' },
    { id: 'staff-list', title: 'Staff list', description: 'Stage staff records into the controlled review and approval workflow.' },
];
const unifiedQualificationExamples = 'FSLC, SSCE, NCE, OND, HND, BSc/BA/BEng, PGD, MSc/MA/MBA, PhD, Professional Fellowship, A/L CERT, V/T, NO CERT';
const selectedImport = computed(() => importTypes.find((type) => type.id === selectedType.value) ?? importTypes[0]);
const canUpload = computed(() => selectedFileName.value !== '' && uploadBusy.value === '');
const hasInFlightBatch = computed(() => rows.value.some((row) => ['queued', 'staging'].includes(row.status)));
const columns = [
    { key: 'id', label: 'Batch' }, { key: 'source_table', label: 'Source' },
    { key: 'rows_staged', label: 'Staged' }, { key: 'errors_count', label: 'Errors' },
    { key: 'status', label: 'Status' }, { key: 'started_at', label: 'Started' },
];

const load = async (options = {}) => {
    if (!options.silent) busy.value = true;
    const response = await api.get('/legacy-staff-imports', { params: filters });
    rows.value = response.data.data;
    meta.value = response.data.meta;
    options.value = response.data.options;
    if (!options.silent) busy.value = false;
    scheduleRefresh();
};
const upload = async () => {
    const file = selectedFile.value ?? fileInput.value?.files?.[0] ?? null;
    if (!file) {
        error.value = 'Select an XLSX, XLS, or CSV file before importing.';
        return;
    }

    const type = selectedType.value;
    uploadBusy.value = type;
    feedback.value = '';
    error.value = '';

    try {
        const form = new FormData();
        form.append('file', file);
        const response = await api.post(`/operational-imports/${type}`, form);
        const result = response.data.data ?? {};
        feedback.value = type === 'staff-list'
            ? response.data.message
            : `${response.data.message} Created: ${result.created ?? 0}, updated: ${result.updated ?? 0}, skipped: ${result.skipped ?? 0}.`;
        resetFile();
        await load();

        if (type === 'staff-list' && response.data.data?.batch_id) {
            window.location.href = `/legacy-staff-imports/${response.data.data.batch_id}`;
        }
    } catch (uploadError) {
        error.value = apiMessage(uploadError, 'The spreadsheet could not be imported.');
    } finally {
        uploadBusy.value = '';
    }
};
const resetFile = () => {
    selectedFile.value = null;
    selectedFileName.value = '';
    if (fileInput.value) fileInput.value.value = '';
};
const selectFile = (event) => {
    const file = event.currentTarget.files?.[0] ?? null;
    selectedFile.value = file;
    selectedFileName.value = file?.name ?? '';
    error.value = '';
};
const clearRefreshTimer = () => {
    if (refreshTimer.value) {
        clearTimeout(refreshTimer.value);
        refreshTimer.value = null;
    }
};
const scheduleRefresh = () => {
    clearRefreshTimer();
    if (!hasInFlightBatch.value) return;
    refreshTimer.value = setTimeout(() => load({ silent: true }), 5000);
};
watch(() => [filters.status, filters.source_table], () => load());
onMounted(() => load());
onBeforeUnmount(clearRefreshTimer);
</script>

<template>
    <PageHeading eyebrow="Controlled intake" title="Data imports" description="Import reference data and move staff lists through formal review and approval." />
    <div v-if="feedback" class="civic-feedback">{{ feedback }}</div>
    <div v-if="error" class="civic-error">{{ error }}</div>
    <div v-if="hasInFlightBatch" class="civic-feedback">A staff-list batch is currently staging in the background. This page refreshes automatically every few seconds.</div>

    <section v-if="can('import-staff')" class="civic-import-grid civic-import-grid-single">
        <article class="civic-import-card">
            <div class="civic-eyebrow">Spreadsheet intake</div>
            <h2>{{ selectedImport.title }}</h2>
            <p>{{ selectedImport.description }}</p>
            <label class="civic-field">
                <span>What do you want to import?</span>
                <select v-model="selectedType" @change="resetFile">
                    <option v-for="type in importTypes" :key="type.id" :value="type.id">{{ type.title }}</option>
                </select>
            </label>
            <div v-if="selectedType === 'staff-list'" class="civic-import-note">
                <strong>Highest qualification check</strong>
                <span>The staff <code>highest_qualification</code> value must match the unified qualification types. Examples: {{ unifiedQualificationExamples }}.</span>
                <span>Common legacy values such as <code>ND</code>, <code>DEGREE</code>, <code>MASTERS</code>, <code>PRY CERT</code>, and <code>S/CERT</code> are accepted and mapped automatically.</span>
            </div>
            <label class="civic-field">
                <span>Select XLSX, XLS, or CSV file</span>
                <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv" @change="selectFile">
                <small v-if="selectedFileName">Ready to import: {{ selectedFileName }}</small>
            </label>
            <div class="civic-action-cluster">
                <a class="civic-button" :href="`/api/operational-imports/${selectedType}/template`">Download {{ selectedImport.title }} template</a>
                <button class="civic-button civic-button-primary" type="button" :disabled="!canUpload" @click="upload">
                    {{ uploadBusy ? 'Importing...' : `Import ${selectedImport.title}` }}
                </button>
            </div>
        </article>
    </section>

    <section class="civic-workspace civic-import-guide">
        <div class="civic-section-heading"><div><div class="civic-eyebrow">Import guide</div><h2>How spreadsheet imports work</h2></div></div>
        <div class="civic-guide-grid">
            <article><strong>Reference imports</strong><p>Departments, stations, cadres, and ranks are validated and written directly to their MDA-scoped reference tables. Existing records are skipped using their identifying code or relationship.</p></article>
            <article><strong>Staff-list staging</strong><p>Staff spreadsheets are never written directly into the live staff registry. Every row is preserved, normalized, matched to reference data, and staged for review.</p></article>
            <article><strong>Review and approval</strong><p>Open a batch to filter all rows, errors, warnings, and issue codes. Resolve blocking errors, review warnings, submit for approval, then publish approved rows.</p></article>
            <article><strong>Publishing</strong><p>Publishing creates or updates live staff records and their employment, salary, qualification, allowance, and status-history records. Raw spreadsheet data remains preserved for audit.</p></article>
        </div>
        <details class="civic-import-tables">
            <summary>Database tables involved</summary>
            <p><strong>Direct reference imports:</strong> <code>departments</code>, <code>stations</code>, <code>cadres</code>, and <code>ranks</code>.</p>
            <p><strong>Staff import review:</strong> <code>legacy_staff_import_batches</code> stores each upload; <code>legacy_staff_import_rows</code> stores every raw and normalized row; <code>legacy_staff_import_errors</code> stores warnings/errors; <code>legacy_staff_import_publications</code> records publishing results.</p>
            <p><strong>After publishing:</strong> live data is written to <code>staff</code>, <code>staff_employments</code>, <code>staff_salary_placements</code>, <code>staff_qualifications</code>, <code>staff_allowance_assignments</code>, and <code>staff_status_histories</code>.</p>
        </details>
    </section>

    <section class="civic-workspace">
        <div class="civic-section-heading"><div><div class="civic-eyebrow">Review queue</div><h2>Staff import batches</h2></div></div>
        <div class="civic-filter-line">
            <label class="civic-field"><span>Workflow status</span><select v-model="filters.status"><option value="">All statuses</option><option v-for="status in options.statuses" :key="status">{{ status }}</option></select></label>
            <label class="civic-field"><span>Source table</span><select v-model="filters.source_table"><option value="">All sources</option><option v-for="source in options.source_tables" :key="source">{{ source }}</option></select></label>
            <div class="civic-record-count">{{ meta?.total ?? 0 }} batches</div>
        </div>
        <LoadingBlock v-if="busy" />
        <DataTable v-else :columns="columns" :rows="rows">
            <template #id="{ row }"><RouterLink class="civic-record-link" :to="`/legacy-staff-imports/${row.id}`">Batch #{{ row.id }}</RouterLink></template>
            <template #status="{ row }"><StatusPill :status="row.status" /></template>
        </DataTable>
    </section>
</template>
