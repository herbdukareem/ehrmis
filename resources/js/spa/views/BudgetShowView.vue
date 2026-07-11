<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();
const data = ref(null);
const note = ref('');
const feedback = ref('');
const columns = [
    { key: 'department', label: 'Department' }, { key: 'scale', label: 'Scale' }, { key: 'level', label: 'Level' },
    { key: 'staff_count', label: 'Staff' }, { key: 'current_gross_total', label: 'Current gross' },
    { key: 'proposed_gross_total', label: 'Proposed gross' }, { key: 'variance_total', label: 'Variance' },
];
const reports = [
    { key: 'recurrent-expenditure', label: 'Recurrent expenditure' },
    { key: 'staff-list', label: 'Staff list' },
    { key: 'qualification-distribution', label: 'Qualification distribution' },
    { key: 'staff-strength', label: 'Staff strength' },
];
const canPrintReports = computed(() => ['approved', 'locked'].includes(data.value?.status));
const money = (value) => Number(value ?? 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const reportUrl = (report) => `/api/budget-workbooks/${route.params.id}/reports/${report}`;
const load = async () => { data.value = (await api.get(`/budget-workbooks/${route.params.id}`)).data.data; };
const action = async (name) => {
    try { feedback.value = (await api.post(`/budget-workbooks/${route.params.id}/${name}`, { comment: note.value })).data.message; await load(); }
    catch (error) { feedback.value = apiMessage(error); }
};
onMounted(load);
</script>

<template>
    <LoadingBlock v-if="!data" />
    <template v-else>
        <PageHeading :eyebrow="`${data.mda?.code} · ${data.year}`" :title="`Budget workbook #${data.id}`" description="Recurrent personnel estimate and approval record.">
            <StatusPill :status="data.status" />
        </PageHeading>
        <section class="civic-decision-bar">
            <div><span>Staff</span><strong>{{ data.summary?.staff_count ?? 0 }}</strong></div>
            <div><span>Current gross</span><strong>{{ money(data.summary?.current_gross_total) }}</strong></div>
            <div><span>Proposed gross</span><strong>{{ money(data.summary?.proposed_gross_total) }}</strong></div>
            <label class="civic-field civic-decision-note"><span>Decision note</span><input v-model="note" placeholder="Required when rejecting"></label>
            <div class="civic-action-cluster">
                <button class="civic-button" @click="action('submit')">Submit</button>
                <button class="civic-button civic-button-primary" @click="action('approve')">Approve</button>
                <button class="civic-button civic-button-danger" @click="action('reject')">Reject</button>
                <button class="civic-button" @click="action('lock')">Lock</button>
                <button class="civic-button" @click="action('reopen')">Reopen</button>
            </div>
        </section>
        <div v-if="feedback" class="civic-feedback">{{ feedback }}</div>
        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div><span class="civic-kicker">Approved reports</span><h2>Print budget reports</h2></div>
                <small v-if="!canPrintReports">Reports become available after the budget workbook is approved.</small>
            </div>
            <div v-if="canPrintReports" class="civic-action-cluster">
                <a v-for="report in reports" :key="report.key" class="civic-button" :href="reportUrl(report.key)" target="_blank" rel="noopener">
                    {{ report.label }}
                </a>
            </div>
            <p v-else class="civic-muted">Approve this workbook first, then users with budget view rights can print the recurrent expenditure and supporting reports.</p>
        </section>
        <section class="civic-workspace">
            <DataTable :columns="columns" :rows="data.lines">
                <template #current_gross_total="{ row }">{{ money(row.current_gross_total) }}</template>
                <template #proposed_gross_total="{ row }">{{ money(row.proposed_gross_total) }}</template>
                <template #variance_total="{ row }">{{ money(row.variance_total) }}</template>
            </DataTable>
        </section>
    </template>
</template>
