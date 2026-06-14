<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();
const data = ref(null);
const busy = ref(false);
const note = ref('');
const feedback = ref('');
const meta = ref(null);
const filters = reactive({ search: '', severity: '', error_code: '', published: '', publishable: '', per_page: 20, page: 1 });
const columns = [
    { key: 'staff_number', label: 'Staff no.' }, { key: 'full_name', label: 'Officer' },
    { key: 'department', label: 'Department' }, { key: 'cadre', label: 'Cadre' },
    { key: 'salary_scale', label: 'Scale' }, { key: 'issues', label: 'Issues' }, { key: 'status', label: 'Status' },
];

const load = async () => {
    const response = await api.get(`/legacy-staff-imports/${route.params.id}`, { params: filters });
    data.value = response.data.data;
    meta.value = response.data.meta;
};
const applyFilter = (values = {}) => {
    Object.assign(filters, { search: '', severity: '', error_code: '', published: '', publishable: '', page: 1 }, values);
    load();
};
const resetFilters = () => {
    Object.assign(filters, { search: '', severity: '', error_code: '', published: '', publishable: '', per_page: 20, page: 1 });
    load();
};
const changePage = (page) => {
    filters.page = page;
    load();
};
const action = async (name) => {
    busy.value = true; feedback.value = '';
    try {
        const response = await api.post(`/legacy-staff-imports/${route.params.id}/${name}`, { comment: note.value });
        feedback.value = response.data.message;
        await load();
    } catch (error) { feedback.value = apiMessage(error); }
    finally { busy.value = false; }
};
watch(() => filters.per_page, () => changePage(1));
onMounted(load);
</script>

<template>
    <LoadingBlock v-if="!data" />
    <template v-else>
        <PageHeading :eyebrow="data.batch.source_table" :title="`Import batch #${data.batch.id}`" description="Validate source records, record the decision, then publish approved staff.">
            <StatusPill :status="data.batch.status" />
        </PageHeading>
        <section class="civic-decision-bar">
            <button class="civic-metric-action" type="button" @click="applyFilter()"><span>All rows</span><strong>{{ data.summary.rows_staged }}</strong></button>
            <button class="civic-metric-action" type="button" @click="applyFilter({ publishable: '1' })"><span>Publishable</span><strong>{{ data.summary.rows_publishable }}</strong></button>
            <button class="civic-metric-action" type="button" @click="applyFilter({ severity: 'warning' })"><span>Warnings</span><strong>{{ data.summary.warnings_count }}</strong></button>
            <button class="civic-metric-action" type="button" @click="applyFilter({ severity: 'error' })"><span>Errors</span><strong>{{ data.summary.errors_count }}</strong></button>
            <label class="civic-field civic-decision-note"><span>Decision note</span><input v-model="note" placeholder="Required when rejecting"></label>
            <div class="civic-action-cluster">
                <button v-if="data.can.submit_approval" class="civic-button" :disabled="busy" @click="action('submit')">Submit</button>
                <button v-if="data.can.approve" class="civic-button civic-button-primary" :disabled="busy" @click="action('approve')">Approve</button>
                <button v-if="data.can.reject" class="civic-button civic-button-danger" :disabled="busy" @click="action('reject')">Reject</button>
                <button v-if="data.can.publish" class="civic-button civic-button-primary" :disabled="busy" @click="action('publish')">Publish</button>
            </div>
        </section>
        <div v-if="feedback" class="civic-feedback">{{ feedback }}</div>
        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div><div class="civic-eyebrow">Review records</div><h2>{{ meta?.total ?? 0 }} matching rows</h2></div>
                <button class="civic-button" type="button" @click="resetFilters">Clear filters</button>
            </div>
            <div class="civic-filter-line">
                <label class="civic-field"><span>Search officer or staff no.</span><input v-model="filters.search" @keyup.enter="changePage(1)"></label>
                <label class="civic-field"><span>Issue type</span><select v-model="filters.severity" @change="changePage(1)"><option value="">All rows</option><option value="error">Rows with errors</option><option value="warning">Rows with warnings</option></select></label>
                <label class="civic-field"><span>Specific issue</span><select v-model="filters.error_code" @change="changePage(1)"><option value="">All issue codes</option><option v-for="code in [...(data.options.error_codes ?? []), ...(data.options.warning_codes ?? [])]" :key="code" :value="code">{{ code.replaceAll('_', ' ') }}</option></select></label>
                <label class="civic-field"><span>Publication</span><select v-model="filters.published" @change="changePage(1)"><option value="">All records</option><option value="0">Not published</option><option value="1">Published</option></select></label>
                <label class="civic-field"><span>Rows per page</span><select v-model.number="filters.per_page"><option :value="20">20</option><option :value="50">50</option><option :value="100">100</option></select></label>
                <button class="civic-button civic-button-primary" type="button" @click="changePage(1)">Apply</button>
            </div>
            <DataTable :columns="columns" :rows="data.rows">
                <template #staff_number="{ row }"><RouterLink class="civic-record-link" :to="`/legacy-staff-imports/${data.batch.id}/rows/${row.id}`">{{ row.staff_number ?? `Row ${row.id}` }}</RouterLink></template>
                <template #full_name="{ row }"><div class="civic-primary-cell">{{ row.full_name }}</div><small>{{ row.station?.name ?? 'No station' }}</small></template>
                <template #department="{ row }">{{ row.department?.name ?? '—' }}</template>
                <template #cadre="{ row }">{{ row.cadre?.name ?? '—' }}</template>
                <template #salary_scale="{ row }">{{ row.salary_scale?.code ?? '—' }}</template>
                <template #issues="{ row }"><RouterLink class="civic-record-link" :to="`/legacy-staff-imports/${data.batch.id}/rows/${row.id}`">{{ row.issue_summary.errors_count }} error(s), {{ row.issue_summary.warnings_count }} warning(s)</RouterLink></template>
                <template #status="{ row }"><StatusPill :status="row.status" /></template>
            </DataTable>
            <div v-if="meta?.last_page > 1" class="civic-pagination">
                <button :disabled="meta.current_page === 1" @click="changePage(meta.current_page - 1)">Previous</button>
                <span>Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} records</span>
                <button :disabled="meta.current_page === meta.last_page" @click="changePage(meta.current_page + 1)">Next</button>
            </div>
        </section>
    </template>
</template>
