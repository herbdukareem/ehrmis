<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { can } from '../stores/auth';
import { pushToast } from '../stores/app';

const route = useRoute();
const data = ref(null);
const options = ref({ mdas: [], ranks: [], salary_scales: [] });
const busy = ref(true);
const feedback = ref('');
const actionNote = ref('');
const selectedApplicationId = ref('');
const selectedSittingId = ref('');
const screenForm = reactive({
    proposed_rank_id: '',
    proposed_salary_scale_id: '',
    proposed_level: '',
    proposed_step: 1,
    status: 'listed_for_sitting',
});
const sittingForm = reactive({
    title: '',
    mda_id: '',
    sitting_date: new Date().toISOString().slice(0, 10),
    panel_notes: '',
});
const decisionForm = reactive({
    application_id: '',
    decision: 'approved',
    remarks: '',
    correction_notes: '',
});
const applicationColumns = [
    { key: 'application_number', label: 'Application' },
    { key: 'full_name', label: 'Officer' },
    { key: 'mda', label: 'MDA' },
    { key: 'proposed', label: 'Proposed' },
    { key: 'status', label: 'Status' },
    { key: 'letter', label: 'Letter' },
];
const sittingColumns = [
    { key: 'title', label: 'Sitting' },
    { key: 'mda', label: 'MDA' },
    { key: 'sitting_date', label: 'Date' },
    { key: 'decisions_count', label: 'Decisions' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Actions' },
];
const selectedApplication = computed(() => data.value?.applications?.find((item) => item.id === Number(selectedApplicationId.value)));
const selectedSitting = computed(() => data.value?.sittings?.find((item) => item.id === Number(selectedSittingId.value)));
const mdaApplications = computed(() => {
    if (!selectedSitting.value) return data.value?.applications ?? [];
    return (data.value?.applications ?? []).filter((application) => application.mda_id === selectedSitting.value.mda_id);
});
const ranksForScreen = computed(() => options.value.ranks.filter((rank) => !selectedApplication.value?.mda_id || options.value.salary_scales.some((scale) => scale.id === rank.salary_scale_id && scale.mda_id === selectedApplication.value.mda_id)));
const scalesForScreen = computed(() => options.value.salary_scales.filter((scale) => !selectedApplication.value?.mda_id || scale.mda_id === selectedApplication.value.mda_id));

const load = async () => {
    busy.value = true;
    const response = await api.get(`/promotion-cycles/${route.params.id}`);
    data.value = response.data.data;
    options.value = response.data.options;
    sittingForm.mda_id ||= data.value.mda?.id ?? options.value.mdas[0]?.id ?? '';
    sittingForm.title ||= `${data.value.title} Sitting`;
    selectedApplicationId.value ||= data.value.applications[0]?.id ?? '';
    selectedSittingId.value ||= data.value.sittings[0]?.id ?? '';
    decisionForm.application_id ||= mdaApplications.value[0]?.id ?? '';
    busy.value = false;
};

const run = async (callback) => {
    feedback.value = '';
    try {
        const response = await callback();
        pushToast(response.data.message);
        await load();
    } catch (error) {
        feedback.value = apiMessage(error);
    }
};

const screenApplication = () => run(() => api.post(`/promotion-applications/${selectedApplicationId.value}/screen`, {
    ...screenForm,
    proposed_rank_id: screenForm.proposed_rank_id || null,
    proposed_salary_scale_id: screenForm.proposed_salary_scale_id || null,
    proposed_level: screenForm.proposed_level || null,
    proposed_step: screenForm.proposed_step || null,
}));
const createSitting = () => run(() => api.post(`/promotion-cycles/${route.params.id}/sittings`, sittingForm));
const saveDecision = () => run(() => api.post(`/promotion-sittings/${selectedSittingId.value}/decisions`, decisionForm));
const sittingAction = (sitting, action, payload = {}) => run(() => api.post(`/promotion-sittings/${sitting.id}/${action}`, payload));
const printLetter = (application) => run(() => api.post(`/promotion-applications/${application.id}/print-letter`));

onMounted(load);
</script>

<template>
    <LoadingBlock v-if="busy" />
    <template v-else>
        <PageHeading :eyebrow="`Promotion cycle ${data.year}`" :title="data.title" description="Screen APA submissions, conduct sittings, authorize printing, and issue promotion letters.">
            <StatusPill :status="data.status" />
        </PageHeading>
        <div v-if="feedback" class="civic-error">{{ feedback }}</div>

        <section class="civic-decision-bar">
            <div><span>Applications</span><strong>{{ data.applications.length }}</strong></div>
            <div><span>Sittings</span><strong>{{ data.sittings.length }}</strong></div>
            <div><span>Approved</span><strong>{{ data.applications.filter((item) => ['approved', 'approved_with_corrections', 'letter_printed'].includes(item.status)).length }}</strong></div>
            <div><span>Rejected</span><strong>{{ data.applications.filter((item) => item.status === 'rejected').length }}</strong></div>
        </section>

        <section v-if="can('screen-promotions')" class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Screening</span><h2>Prepare application for sitting</h2></div></div>
            <form class="civic-form-grid" @submit.prevent="screenApplication">
                <label class="civic-field civic-field-wide"><span>Application</span><select v-model="selectedApplicationId" required><option v-for="application in data.applications" :key="application.id" :value="application.id">{{ application.application_number }} - {{ application.full_name }}</option></select></label>
                <label class="civic-field"><span>Proposed rank</span><select v-model="screenForm.proposed_rank_id"><option value="">Select rank</option><option v-for="rank in ranksForScreen" :key="rank.id" :value="rank.id">{{ rank.name }} - Level {{ rank.level }}</option></select></label>
                <label class="civic-field"><span>Salary scale</span><select v-model="screenForm.proposed_salary_scale_id"><option value="">Use rank scale</option><option v-for="scale in scalesForScreen" :key="scale.id" :value="scale.id">{{ scale.code }} - {{ scale.name }}</option></select></label>
                <label class="civic-field"><span>Level</span><input v-model.number="screenForm.proposed_level" type="number" min="1" max="20"></label>
                <label class="civic-field"><span>Step</span><input v-model.number="screenForm.proposed_step" type="number" min="1" max="20"></label>
                <label class="civic-field"><span>Status</span><select v-model="screenForm.status"><option value="screened">Screened</option><option value="listed_for_sitting">Listed for sitting</option></select></label>
                <div class="civic-field civic-form-action"><span>Save screening</span><button class="civic-button civic-button-primary">Save screening</button></div>
            </form>
        </section>

        <section v-if="can('manage-promotion-sittings')" class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Sitting</span><h2>Create promotion sitting</h2></div></div>
            <form class="civic-form-grid" @submit.prevent="createSitting">
                <label class="civic-field civic-field-wide"><span>Title</span><input v-model="sittingForm.title" required></label>
                <label class="civic-field"><span>MDA</span><select v-model="sittingForm.mda_id" required><option v-for="mda in options.mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option></select></label>
                <label class="civic-field"><span>Sitting date</span><input v-model="sittingForm.sitting_date" type="date" required></label>
                <label class="civic-field civic-field-wide"><span>Panel notes</span><textarea v-model="sittingForm.panel_notes" rows="3"></textarea></label>
                <div class="civic-field civic-form-action"><span>Create sitting</span><button class="civic-button civic-button-primary">Create sitting</button></div>
            </form>
        </section>

        <section v-if="can('decide-promotions') && data.sittings.length" class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Commission decision</span><h2>Record sitting decision</h2></div></div>
            <form class="civic-form-grid" @submit.prevent="saveDecision">
                <label class="civic-field"><span>Sitting</span><select v-model="selectedSittingId" required><option v-for="sitting in data.sittings" :key="sitting.id" :value="sitting.id">{{ sitting.title }}</option></select></label>
                <label class="civic-field"><span>Application</span><select v-model="decisionForm.application_id" required><option v-for="application in mdaApplications" :key="application.id" :value="application.id">{{ application.application_number }} - {{ application.full_name }}</option></select></label>
                <label class="civic-field"><span>Decision</span><select v-model="decisionForm.decision"><option value="approved">Approved</option><option value="approved_with_corrections">Approved with corrections</option><option value="rejected">Rejected</option></select></label>
                <label class="civic-field civic-field-wide"><span>Remarks</span><textarea v-model="decisionForm.remarks" rows="3"></textarea></label>
                <label class="civic-field civic-field-wide"><span>Corrections</span><textarea v-model="decisionForm.correction_notes" rows="3"></textarea></label>
                <div class="civic-field civic-form-action"><span>Save decision</span><button class="civic-button civic-button-primary">Save decision</button></div>
            </form>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Applications</span><h2>APA submissions</h2></div></div>
            <DataTable :columns="applicationColumns" :rows="data.applications">
                <template #application_number="{ row }"><span class="civic-record-link">{{ row.application_number }}</span></template>
                <template #mda="{ row }">{{ row.mda?.code }}</template>
                <template #proposed="{ row }">{{ row.proposed_salary_scale?.code ?? 'N/A' }} {{ row.proposed_level ?? '-' }}/{{ row.proposed_step ?? '-' }}</template>
                <template #status="{ row }"><StatusPill :status="row.status" /></template>
                <template #letter="{ row }">
                    <button v-if="can('print-promotion-letters') && ['approved', 'approved_with_corrections'].includes(row.status)" class="civic-button" type="button" @click="printLetter(row)">Print</button>
                    <a v-else-if="row.letter?.pdf_url" class="civic-button" :href="row.letter.pdf_url" target="_blank" rel="noopener">View PDF</a>
                    <span v-else>{{ row.letter?.letter_number ?? 'Pending' }}</span>
                </template>
            </DataTable>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Sittings</span><h2>Promotion consideration sittings</h2></div></div>
            <label v-if="can('approve-promotion-printing')" class="civic-field civic-decision-note"><span>Approval note</span><input v-model="actionNote" placeholder="Required when rejecting print approval"></label>
            <DataTable :columns="sittingColumns" :rows="data.sittings">
                <template #mda="{ row }">{{ row.mda?.code }}</template>
                <template #status="{ row }"><StatusPill :status="row.status" /></template>
                <template #actions="{ row }">
                    <div class="civic-action-cluster">
                        <button v-if="can('manage-promotion-sittings') && ['draft', 'in_review'].includes(row.status)" class="civic-button" type="button" @click="sittingAction(row, 'complete')">Complete</button>
                        <button v-if="can('manage-promotion-sittings') && row.status === 'completed'" class="civic-button" type="button" @click="sittingAction(row, 'submit-print-approval')">Submit print</button>
                        <button v-if="can('approve-promotion-printing') && row.status === 'print_approval_pending'" class="civic-button civic-button-primary" type="button" @click="sittingAction(row, 'approve-print', { comment: actionNote })">Authorize</button>
                        <button v-if="can('approve-promotion-printing') && row.status === 'print_approval_pending'" class="civic-button civic-button-danger" type="button" @click="sittingAction(row, 'reject-print', { comment: actionNote })">Reject</button>
                    </div>
                </template>
            </DataTable>
        </section>
    </template>
</template>
