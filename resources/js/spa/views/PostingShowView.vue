<script setup>
import { computed, onMounted, ref } from 'vue';
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
const busy = ref(true);
const feedback = ref('');
const note = ref('');
const approvalColumns = [
    { key: 'stage', label: 'Stage' },
    { key: 'decision', label: 'Decision' },
    { key: 'actor', label: 'Actor' },
    { key: 'comment', label: 'Comment' },
    { key: 'acted_at', label: 'Acted' },
];
const journey = computed(() => [
    { label: 'Origin', value: data.value?.from_mda?.name },
    { label: 'Origin station', value: data.value?.from_station?.name ?? data.value?.from_department?.name ?? 'Unassigned' },
    { label: 'Destination', value: data.value?.to_mda?.name },
    { label: 'Destination station', value: data.value?.to_station?.name ?? data.value?.to_department?.name ?? 'Unassigned' },
]);

const load = async () => {
    busy.value = true;
    data.value = (await api.get(`/posting-requests/${route.params.id}`)).data.data;
    busy.value = false;
};

const action = async (name, payload = {}) => {
    feedback.value = '';
    try {
        const response = await api.post(`/posting-requests/${route.params.id}/${name}`, payload);
        pushToast(response.data.message);
        await load();
    } catch (error) {
        feedback.value = apiMessage(error);
    }
};

onMounted(load);
</script>

<template>
    <LoadingBlock v-if="busy" />
    <template v-else>
        <PageHeading :eyebrow="data.request_number" :title="data.staff?.full_name" description="Posting request review, approval, letter issue, and staff-record effecting.">
            <StatusPill :status="data.status" />
        </PageHeading>
        <div v-if="feedback" class="civic-error">{{ feedback }}</div>

        <section class="civic-decision-bar">
            <div v-for="item in journey" :key="item.label"><span>{{ item.label }}</span><strong>{{ item.value }}</strong></div>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Workflow actions</span><h2>Posting decision</h2></div></div>
            <label class="civic-field civic-field-wide"><span>Decision note</span><input v-model="note" placeholder="Required for rejection"></label>
            <div class="civic-action-cluster">
                <button v-if="can('create-postings') && ['draft', 'rejected'].includes(data.status)" class="civic-button" type="button" @click="action('submit')">Submit</button>
                <button v-if="can('approve-own-mda-postings') && data.status === 'submitted'" class="civic-button civic-button-primary" type="button" @click="action('approve-origin', { comment: note })">Origin approve</button>
                <button v-if="can('approve-receiving-mda-postings') && data.status === 'from_mda_approved'" class="civic-button civic-button-primary" type="button" @click="action('approve-receiving', { comment: note })">Receiving approve</button>
                <button v-if="can('approve-inter-mda-postings') && ['from_mda_approved', 'receiving_mda_approved'].includes(data.status)" class="civic-button civic-button-primary" type="button" @click="action('approve-final', { comment: note })">Final approve</button>
                <button v-if="can('approve-own-mda-postings') && !['effected', 'cancelled'].includes(data.status)" class="civic-button civic-button-danger" type="button" @click="action('reject', { comment: note })">Reject</button>
                <button v-if="can('print-posting-letters') && data.status === 'approved'" class="civic-button" type="button" @click="action('issue')">Issue letter</button>
                <button v-if="can('effect-postings') && ['approved', 'issued'].includes(data.status)" class="civic-button civic-button-primary" type="button" @click="action('effect')">Effect posting</button>
            </div>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Staff movement</span><h2>Posting details</h2></div></div>
            <div class="civic-form-grid">
                <div class="civic-field"><span>Staff number</span><strong>{{ data.staff?.staff_number }}</strong></div>
                <div class="civic-field"><span>Posting type</span><strong>{{ data.posting_type }}</strong></div>
                <div class="civic-field"><span>Effective date</span><strong>{{ data.effective_date }}</strong></div>
                <div class="civic-field civic-field-wide"><span>Reason</span><p>{{ data.reason ?? 'No reason entered.' }}</p></div>
                <div class="civic-field"><span>Letter</span><strong>{{ data.letter?.letter_number ?? 'Not issued' }}</strong></div>
                <div class="civic-field"><span>PDF</span><a v-if="data.letter?.pdf_url" class="civic-record-link" :href="data.letter.pdf_url" target="_blank" rel="noopener">Open letter PDF</a><strong v-else>Pending</strong></div>
                <div class="civic-field"><span>Effected at</span><strong>{{ data.effected_at ?? 'Pending' }}</strong></div>
            </div>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Audit trail</span><h2>Approvals</h2></div></div>
            <DataTable :columns="approvalColumns" :rows="data.approvals" row-key="acted_at">
                <template #decision="{ row }"><StatusPill :status="row.decision" /></template>
                <template #actor="{ row }">{{ row.actor?.name ?? 'System' }}</template>
            </DataTable>
        </section>
    </template>
</template>
