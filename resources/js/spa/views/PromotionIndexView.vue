<script setup>
import { onMounted, reactive, ref } from 'vue';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { auth, can } from '../stores/auth';
import { pushToast } from '../stores/app';

const rows = ref([]);
const options = ref({ mdas: [] });
const busy = ref(true);
const saving = ref(false);
const feedback = ref('');
const currentYear = new Date().getFullYear();
const form = reactive({
    title: `${currentYear} Promotion Exercise`,
    year: currentYear,
    mda_id: auth.user?.assigned_mda?.id ?? '',
    opens_at: '',
    closes_at: '',
    status: 'open',
});
const columns = [
    { key: 'title', label: 'Cycle' },
    { key: 'mda', label: 'Scope' },
    { key: 'year', label: 'Year' },
    { key: 'applications_count', label: 'Applications' },
    { key: 'sittings_count', label: 'Sittings' },
    { key: 'status', label: 'Status' },
];

const load = async () => {
    busy.value = true;
    const response = await api.get('/promotion-cycles');
    rows.value = response.data.data;
    options.value = response.data.options;
    if (!form.mda_id) form.mda_id = options.value.mdas[0]?.id ?? '';
    busy.value = false;
};

const createCycle = async () => {
    saving.value = true;
    feedback.value = '';
    try {
        const response = await api.post('/promotion-cycles', {
            ...form,
            mda_id: form.mda_id || null,
            opens_at: form.opens_at || null,
            closes_at: form.closes_at || null,
        });
        pushToast(response.data.message);
        await load();
    } catch (error) {
        feedback.value = apiMessage(error);
    } finally {
        saving.value = false;
    }
};

onMounted(load);
</script>

<template>
    <PageHeading eyebrow="Promotion management" title="Promotion cycles" description="Collect APA submissions, organize promotion sittings, and issue authorized letters." />

    <section v-if="can('manage-promotion-sittings')" class="civic-workspace">
        <div class="civic-section-heading">
            <div><span class="civic-kicker">New cycle</span><h2>Open promotion exercise</h2></div>
            <RouterLink class="civic-button" to="/promotion/apply">Public APA form</RouterLink>
        </div>
        <div v-if="feedback" class="civic-error">{{ feedback }}</div>
        <form class="civic-form-grid" @submit.prevent="createCycle">
            <label class="civic-field civic-field-wide"><span>Title</span><input v-model="form.title" required></label>
            <label class="civic-field"><span>Year</span><input v-model.number="form.year" type="number" min="2020" max="2100" required></label>
            <label class="civic-field"><span>MDA scope</span><select v-model="form.mda_id"><option value="">All accessible MDAs</option><option v-for="mda in options.mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option></select></label>
            <label class="civic-field"><span>Opens</span><input v-model="form.opens_at" type="date"></label>
            <label class="civic-field"><span>Closes</span><input v-model="form.closes_at" type="date"></label>
            <label class="civic-field"><span>Status</span><select v-model="form.status"><option value="draft">Draft</option><option value="open">Open</option><option value="closed">Closed</option></select></label>
            <div class="civic-field civic-form-action"><span>Create cycle</span><button class="civic-button civic-button-primary" :disabled="saving">{{ saving ? 'Creating...' : 'Create promotion cycle' }}</button></div>
        </form>
    </section>

    <section class="civic-workspace">
        <div class="civic-section-heading"><div><span class="civic-kicker">Promotion exercises</span><h2>Cycles</h2></div></div>
        <LoadingBlock v-if="busy" />
        <DataTable v-else :columns="columns" :rows="rows">
            <template #title="{ row }"><RouterLink class="civic-record-link" :to="`/promotion-cycles/${row.id}`">{{ row.title }}</RouterLink></template>
            <template #mda="{ row }">{{ row.mda ? `${row.mda.code} - ${row.mda.name}` : 'All accessible MDAs' }}</template>
            <template #status="{ row }"><StatusPill :status="row.status" /></template>
        </DataTable>
    </section>
</template>
