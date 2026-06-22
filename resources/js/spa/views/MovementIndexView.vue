<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { auth, can } from '../stores/auth';

const router = useRouter();
const rows = ref([]);
const options = ref({ mdas: [] });
const busy = ref(true);
const generating = ref(false);
const feedback = ref('');
const currentYear = new Date().getFullYear();
const form = reactive({
    name: `${currentYear}: Movement Sheet - Full Details`,
    mda_id: auth.user?.assigned_mda?.id ?? '',
    year: currentYear,
    budget_year: currentYear + 1,
    budget_minimum_step: 5,
});
const columns = [
    { key: 'name', label: 'Workbook' },
    { key: 'mda', label: 'MDA' },
    { key: 'year', label: 'Movement year' },
    { key: 'budget_year', label: 'Budget year' },
    { key: 'budget_minimum_step', label: 'Minimum step' },
    { key: 'lines', label: 'Staff' },
    { key: 'status', label: 'Record status' },
    { key: 'approval_status', label: 'Approval' },
];

const load = async () => {
    busy.value = true;
    const response = await api.get('/movement-workbooks');
    rows.value = response.data.data.map((row) => ({ ...row, lines: row.summary?.lines_generated ?? 0 }));
    options.value = response.data.options;
    if (!form.mda_id) form.mda_id = options.value.mdas[0]?.id ?? '';
    busy.value = false;
};

const generate = async () => {
    generating.value = true;
    feedback.value = '';
    try {
        const response = await api.post('/movement-workbooks', form);
        await router.push(`/movement-workbooks/${response.data.data.id}`);
    } catch (error) {
        feedback.value = apiMessage(error);
    } finally {
        generating.value = false;
    }
};

onMounted(load);
</script>

<template>
    <PageHeading eyebrow="Annual personnel movement" title="Movement workbooks" description="Create and review promotion, retirement, and establishment movement snapshots." />

    <section v-if="can('create-movement-sheets')" class="civic-workspace civic-movement-create">
        <div class="civic-section-heading">
            <div><span class="civic-kicker">New workbook</span><h2>Prepare movement sheet</h2></div>
            <small>Proposed promotions are costed at no less than the selected budget step.</small>
        </div>
        <div v-if="feedback" class="civic-error">{{ feedback }}</div>
        <form class="civic-form-grid" @submit.prevent="generate">
            <label class="civic-field civic-field-wide"><span>Workbook name</span><input v-model="form.name" required></label>
            <label class="civic-field"><span>MDA</span><select v-model="form.mda_id" required :disabled="!auth.user?.has_global_access && options.mdas.length <= 1"><option v-for="mda in options.mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option></select></label>
        <label class="civic-field"><span>Movement year</span><input v-model.number="form.year" type="number" min="2020" max="2100" required></label>
            <label class="civic-field"><span>Budget year</span><input v-model.number="form.budget_year" type="number" :min="form.year" max="2100" required></label>
            <label class="civic-field"><span>Budget minimum step</span><select v-model.number="form.budget_minimum_step"><option v-for="step in 15" :key="step" :value="step">Step {{ step }}</option></select></label>
            <div class="civic-field civic-form-action"><span>Generate detailed workbook</span><button class="civic-button civic-button-primary" :disabled="generating">{{ generating ? 'Generating...' : 'Create movement sheet' }}</button></div>
        </form>
    </section>

    <section class="civic-workspace">
        <div class="civic-section-heading"><div><span class="civic-kicker">Workbook history</span><h2>Movement sheets</h2></div></div>
        <LoadingBlock v-if="busy" />
        <DataTable v-else :columns="columns" :rows="rows">
            <template #name="{ row }"><RouterLink class="civic-record-link" :to="`/movement-workbooks/${row.id}`">{{ row.name }}</RouterLink></template>
            <template #mda="{ row }">{{ row.mda?.code }} - {{ row.mda?.name }}</template>
            <template #budget_minimum_step="{ row }">Step {{ row.budget_minimum_step ?? 5 }}</template>
            <template #status="{ row }"><StatusPill :status="row.status" /></template>
            <template #approval_status="{ row }"><StatusPill :status="row.approval_status ?? 'draft'" /></template>
        </DataTable>
    </section>
</template>
