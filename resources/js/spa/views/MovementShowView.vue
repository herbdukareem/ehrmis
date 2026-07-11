<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppTabs from '../components/AppTabs.vue';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { can } from '../stores/auth';

const route = useRoute();
const data = ref(null);
const activeTab = ref('detail');
const note = ref('');
const feedback = ref('');
const tabs = computed(() => [
    { id: 'detail', label: 'Staff movement detail', count: data.value?.lines?.length ?? 0 },
    { id: 'summary', label: 'Summary', count: data.value?.department_summaries?.length ?? 0 },
]);
const detailColumns = [
    { key: 'legacy_cno', label: 'CNO' },
    { key: 'full_name', label: 'Name' },
    { key: 'highest_qualification', label: 'H. Qual.' },
    { key: 'current_placement', label: 'Current placement' },
    { key: 'date_last_promotion', label: 'DPA' },
    { key: 'next_promotion_date', label: 'DNP' },
    { key: 'proposed_placement', label: 'Moving to' },
    { key: 'eligibility_status', label: 'Eligibility' },
];
const summaryColumns = [
    { key: 'serial_number', label: 'S/N' },
    { key: 'scale', label: 'Scale' },
    { key: 'level', label: 'Level' },
    { key: 'present_staff', label: 'Present No. of Staff' },
    { key: 'staff_moving', label: 'No. of Staff Moving' },
    { key: 'staff_retiring', label: 'No. of Staff Retiring' },
    { key: 'staff_joining', label: 'No. of Staff Joining' },
    { key: 'expected_total', label: 'Expected Total' },
];
const departmentGroups = computed(() => Object.values(
    (data.value?.lines ?? []).reduce((groups, line) => {
        const key = line.department_id ?? line.department;
        (groups[key] ??= { department_id: line.department_id, department: line.department, lines: [] }).lines.push(line);
        return groups;
    }, {}),
));
const summaryRows = (rows) => rows.map((row, index) => ({ ...row, serial_number: index + 1 }));
const exportUrl = (departmentId = null) => `/api/movement-workbooks/${route.params.id}/summary-export${departmentId ? `?department_id=${departmentId}` : ''}`;
const detailExportUrl = (departmentId = null) => `/api/movement-workbooks/${route.params.id}/detail-export${departmentId ? `?department_id=${departmentId}` : ''}`;
const load = async () => { data.value = (await api.get(`/movement-workbooks/${route.params.id}`)).data.data; };
const action = async (name) => {
    try {
        feedback.value = (await api.post(`/movement-workbooks/${route.params.id}/${name}`, { comment: note.value })).data.message;
        await load();
    } catch (error) {
        feedback.value = apiMessage(error);
    }
};
onMounted(load);
</script>

<template>
    <LoadingBlock v-if="!data" />
    <template v-else>
        <PageHeading :eyebrow="`${data.mda?.code} - Movement ${data.year} - Budget ${data.budget_year ?? data.year + 1}`" :title="data.name" description="Official staff-movement detail and proposed personnel cost snapshot.">
            <StatusPill :status="data.status" />
        </PageHeading>
        <section v-if="!['generating', 'generation_failed'].includes(data.status)" class="civic-decision-bar">
            <div><span>Staff considered</span><strong>{{ data.summary?.staff_considered ?? 0 }}</strong></div>
            <div><span>Promotion due</span><strong>{{ data.summary?.due_for_promotion ?? 0 }}</strong></div>
            <div><span>Retiring</span><strong>{{ data.summary?.retiring_in_year ?? 0 }}</strong></div>
            <div><span>Budget minimum</span><strong>Step {{ data.budget_minimum_step ?? 5 }}</strong></div>
            <label v-if="can('approve-movement-sheets')" class="civic-field civic-decision-note"><span>Decision note</span><input v-model="note" placeholder="Required when rejecting"></label>
            <div class="civic-action-cluster">
                <button v-if="can('create-movement-sheets')" class="civic-button" @click="action('review')">Submit</button>
                <button v-if="can('approve-movement-sheets')" class="civic-button civic-button-primary" @click="action('approve')">Approve</button>
                <button v-if="can('approve-movement-sheets')" class="civic-button civic-button-danger" @click="action('reject')">Reject</button>
                <button v-if="can('approve-movement-sheets')" class="civic-button" @click="action('lock')">Lock</button>
                <button v-if="can('approve-movement-sheets')" class="civic-button" @click="action('reopen')">Reopen</button>
            </div>
        </section>
        <div v-if="feedback" class="civic-feedback">{{ feedback }}</div>

        <div v-if="data.status === 'generating'" class="civic-feedback">
            This workbook is still generating in the background. Refresh this page in a moment to see the staff movement detail.
        </div>
        <div v-else-if="data.status === 'generation_failed'" class="civic-error">
            {{ data.summary?.generation_failure ?? 'Workbook generation failed. Try generating it again.' }}
        </div>

        <template v-else>
        <AppTabs v-model="activeTab" :tabs="tabs" />

        <section v-if="activeTab === 'detail'" class="civic-movement-groups">
            <div class="civic-summary-toolbar">
                <div><span class="civic-kicker">Staff movement detail</span><strong>{{ data.lines.length }} staff</strong></div>
                <a class="civic-button civic-button-primary" :href="detailExportUrl()">Export all departments</a>
            </div>
            <details v-for="department in departmentGroups" :key="department.department_id ?? department.department" class="civic-workspace civic-summary-panel" open>
                <summary>
                    <div><span class="civic-kicker">Department movement sheet</span><h2>{{ department.department }}</h2></div>
                    <div class="civic-summary-actions">
                        <strong>{{ department.lines.length }} staff</strong>
                        <a class="civic-button" :href="detailExportUrl(department.department_id)" @click.stop>Export to Excel</a>
                        <span class="civic-summary-toggle"></span>
                    </div>
                </summary>
                <DataTable :columns="detailColumns" :rows="department.lines">
                    <template #legacy_cno="{ row }"><RouterLink class="civic-record-link" :to="`/staff/${row.staff_id}`">{{ row.legacy_cno ?? row.staff_number }}</RouterLink></template>
                    <template #eligibility_status="{ row }">
                        <StatusPill :status="row.eligibility_status" />
                        <small v-if="row.eligibility_reason" class="civic-policy-reason">{{ row.eligibility_reason }}</small>
                    </template>
                </DataTable>
            </details>
        </section>

        <section v-else class="civic-movement-summary">
            <div class="civic-summary-toolbar">
                <div><span class="civic-kicker">Department establishment summary</span><strong>{{ data.department_summaries.length }} departments</strong></div>
                <a class="civic-button civic-button-primary" :href="exportUrl()">Export all departments</a>
            </div>
            <details v-for="department in data.department_summaries" :key="department.department_id ?? department.department" class="civic-workspace civic-summary-panel" open>
                <summary>
                    <div><span class="civic-kicker">Department</span><h2>{{ department.department }}</h2></div>
                    <div class="civic-summary-actions">
                        <strong>{{ department.rows.length }} levels</strong>
                        <a class="civic-button" :href="exportUrl(department.department_id)" @click.stop>Export to Excel</a>
                        <span class="civic-summary-toggle"></span>
                    </div>
                </summary>
                <DataTable :columns="summaryColumns" :rows="summaryRows(department.rows)" row-key="serial_number" />
            </details>
        </section>
        </template>
    </template>
</template>
