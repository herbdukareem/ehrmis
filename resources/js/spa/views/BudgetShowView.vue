<script setup>
import { onMounted, ref } from 'vue';
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
            <div><span>Current gross</span><strong>{{ Number(data.summary?.current_gross_total ?? 0).toLocaleString() }}</strong></div>
            <div><span>Proposed gross</span><strong>{{ Number(data.summary?.proposed_gross_total ?? 0).toLocaleString() }}</strong></div>
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
        <section class="civic-workspace"><DataTable :columns="columns" :rows="data.lines" /></section>
    </template>
</template>
