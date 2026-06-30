<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { can } from '../stores/auth';
import { pushToast } from '../stores/app';

const rows = ref([]);
const options = ref({ mdas: [], staff: [], departments: [], stations: [] });
const busy = ref(true);
const saving = ref(false);
const feedback = ref('');
const form = reactive({
    staff_id: '',
    to_mda_id: '',
    to_department_id: '',
    to_station_id: '',
    effective_date: new Date().toISOString().slice(0, 10),
    reason: '',
});
const departments = computed(() => options.value.departments.filter((department) => !form.to_mda_id || department.mda_id === Number(form.to_mda_id)));
const stations = computed(() => options.value.stations.filter((station) => !form.to_mda_id || station.mda_id === Number(form.to_mda_id)));
const columns = [
    { key: 'request_number', label: 'Request' },
    { key: 'staff', label: 'Staff' },
    { key: 'from', label: 'From' },
    { key: 'to', label: 'To' },
    { key: 'effective_date', label: 'Effective' },
    { key: 'status', label: 'Status' },
];

watch(() => form.to_mda_id, () => {
    if (form.to_department_id && !departments.value.some((department) => department.id === Number(form.to_department_id))) form.to_department_id = '';
    if (form.to_station_id && !stations.value.some((station) => station.id === Number(form.to_station_id))) form.to_station_id = '';
});

const load = async () => {
    busy.value = true;
    const response = await api.get('/posting-requests');
    rows.value = response.data.data;
    options.value = response.data.options;
    form.staff_id ||= options.value.staff[0]?.id ?? '';
    form.to_mda_id ||= options.value.mdas[0]?.id ?? '';
    busy.value = false;
};

const createPosting = async () => {
    saving.value = true;
    feedback.value = '';
    try {
        const response = await api.post('/posting-requests', {
            ...form,
            to_department_id: form.to_department_id || null,
            to_station_id: form.to_station_id || null,
            reason: form.reason || null,
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
    <PageHeading eyebrow="Staff posting" title="Posting requests" description="Move staff between stations, departments, or MDAs with staged approval and auditable staff-history updates." />

    <section v-if="can('create-postings')" class="civic-workspace">
        <div class="civic-section-heading"><div><span class="civic-kicker">New posting</span><h2>Prepare posting request</h2></div></div>
        <div v-if="feedback" class="civic-error">{{ feedback }}</div>
        <form class="civic-form-grid" @submit.prevent="createPosting">
            <label class="civic-field civic-field-wide"><span>Staff</span><select v-model="form.staff_id" required><option v-for="staff in options.staff" :key="staff.id" :value="staff.id">{{ staff.staff_number }} - {{ staff.full_name }} ({{ staff.department ?? 'No department' }})</option></select></label>
            <label class="civic-field"><span>Destination MDA</span><select v-model="form.to_mda_id" required><option v-for="mda in options.mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option></select></label>
            <label class="civic-field"><span>Destination department</span><select v-model="form.to_department_id"><option value="">Unassigned</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></label>
            <label class="civic-field"><span>Destination station</span><select v-model="form.to_station_id"><option value="">Unassigned</option><option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option></select></label>
            <label class="civic-field"><span>Effective date</span><input v-model="form.effective_date" type="date" required></label>
            <label class="civic-field civic-field-wide"><span>Reason</span><textarea v-model="form.reason" rows="3"></textarea></label>
            <div class="civic-field civic-form-action"><span>Create request</span><button class="civic-button civic-button-primary" :disabled="saving">{{ saving ? 'Creating...' : 'Create posting request' }}</button></div>
        </form>
    </section>

    <section class="civic-workspace">
        <div class="civic-section-heading"><div><span class="civic-kicker">Posting workflow</span><h2>Requests</h2></div></div>
        <LoadingBlock v-if="busy" />
        <DataTable v-else :columns="columns" :rows="rows">
            <template #request_number="{ row }"><RouterLink class="civic-record-link" :to="`/posting-requests/${row.id}`">{{ row.request_number }}</RouterLink></template>
            <template #staff="{ row }">{{ row.staff?.full_name }}<br><small>{{ row.staff?.staff_number }}</small></template>
            <template #from="{ row }">{{ row.from_mda?.code }} / {{ row.from_station?.name ?? row.from_department?.name ?? 'Unassigned' }}</template>
            <template #to="{ row }">{{ row.to_mda?.code }} / {{ row.to_station?.name ?? row.to_department?.name ?? 'Unassigned' }}</template>
            <template #status="{ row }"><StatusPill :status="row.status" /></template>
        </DataTable>
    </section>
</template>
