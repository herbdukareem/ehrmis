<script setup>
import AppSelect from '@/Components/AppSelect.vue';
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
const selectedStaffId = ref('');
const form = reactive({
    staff_ids: [],
    to_mda_id: '',
    to_department_id: '',
    to_station_id: '',
    effective_date: new Date().toISOString().slice(0, 10),
    reason: '',
});
const selectedStaff = computed(() => options.value.staff.filter((staff) => form.staff_ids.includes(staff.id)));
const selectedOriginMdaId = computed(() => selectedStaff.value[0]?.mda_id ?? null);
const staffOptions = computed(() => options.value.staff.map((staff) => ({
    value: staff.id,
    label: `${staff.staff_number} - ${staff.full_name} (${staff.department ?? 'No department'}${staff.station ? ` / ${staff.station}` : ''})`,
})));
const availableStaffOptions = computed(() => staffOptions.value.filter((option) => {
    if (form.staff_ids.includes(option.value)) {
        return false;
    }

    const staff = options.value.staff.find((entry) => entry.id === option.value);

    return !selectedOriginMdaId.value || staff?.mda_id === selectedOriginMdaId.value;
}));
const mdaOptions = computed(() => options.value.mdas.map((mda) => ({
    value: mda.id,
    label: `${mda.code} - ${mda.name}`,
})));
const departments = computed(() => options.value.departments.filter((department) => !form.to_mda_id || department.mda_id === Number(form.to_mda_id)));
const stations = computed(() => options.value.stations.filter((station) => !form.to_mda_id || station.mda_id === Number(form.to_mda_id)));
const departmentOptions = computed(() => departments.value.map((department) => ({
    value: department.id,
    label: department.name,
})));
const stationOptions = computed(() => stations.value.map((station) => ({
    value: station.id,
    label: station.name,
})));
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

const addSelectedStaff = () => {
    const staffId = Number(selectedStaffId.value || 0);
    if (!staffId || form.staff_ids.includes(staffId)) {
        return;
    }

    form.staff_ids.push(staffId);
    selectedStaffId.value = availableStaffOptions.value[0]?.value ?? '';
};

const removeSelectedStaff = (staffId) => {
    form.staff_ids = form.staff_ids.filter((value) => value !== staffId);
    if (!form.staff_ids.length) {
        selectedStaffId.value = availableStaffOptions.value[0]?.value ?? '';
    }
};

const load = async () => {
    busy.value = true;
    const response = await api.get('/posting-requests');
    rows.value = response.data.data;
    options.value = response.data.options;
    form.to_mda_id ||= options.value.mdas[0]?.id ?? '';
    selectedStaffId.value ||= options.value.staff[0]?.id ?? '';
    busy.value = false;
};

const createPosting = async () => {
    saving.value = true;
    feedback.value = '';
    if (!form.staff_ids.length) {
        feedback.value = 'Select at least one staff member for this posting request.';
        saving.value = false;
        return;
    }
    try {
        const response = await api.post('/posting-requests', {
            ...form,
            staff_ids: form.staff_ids,
            to_department_id: form.to_department_id || null,
            to_station_id: form.to_station_id || null,
            reason: form.reason || null,
        });
        pushToast(response.data.message);
        form.staff_ids = [];
        form.reason = '';
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
            <AppSelect
                v-model="selectedStaffId"
                class="civic-field-wide"
                variant="civic"
                label="Add staff to this posting"
                :options="availableStaffOptions"
                placeholder="Select staff to add"
                search-placeholder="Search by staff number or name"
            />
            <div class="civic-field civic-form-action">
                <span>Selection</span>
                <button class="civic-button" type="button" @click="addSelectedStaff">Add selected staff</button>
            </div>
            <div class="civic-field civic-field-wide">
                <span>Selected staff</span>
                <div v-if="selectedStaff.length" class="civic-tag-line">
                    <span v-for="staff in selectedStaff" :key="staff.id">
                        {{ staff.staff_number }} - {{ staff.full_name }}
                        <button class="civic-button" type="button" @click="removeSelectedStaff(staff.id)">Remove</button>
                    </span>
                </div>
                <p v-else>No staff added yet.</p>
            </div>
            <AppSelect
                v-model="form.to_mda_id"
                variant="civic"
                label="Destination MDA"
                :options="mdaOptions"
                placeholder="Select destination MDA"
                search-placeholder="Search MDA"
            />
            <AppSelect
                v-model="form.to_department_id"
                variant="civic"
                label="Destination department"
                :options="departmentOptions"
                placeholder="Unassigned"
                search-placeholder="Search department"
            />
            <AppSelect
                v-model="form.to_station_id"
                variant="civic"
                label="Destination station"
                :options="stationOptions"
                placeholder="Unassigned"
                search-placeholder="Search station"
            />
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
            <template #staff="{ row }">
                {{ row.staff?.full_name }}<span v-if="row.staff_count > 1"> + {{ row.staff_count - 1 }} more</span><br>
                <small>{{ row.staff?.staff_number }}{{ row.staff_count > 1 ? ` / ${row.staff_count} staff` : '' }}</small>
            </template>
            <template #from="{ row }">{{ row.from_mda?.code }} / {{ row.from_station?.name ?? row.from_department?.name ?? 'Unassigned' }}</template>
            <template #to="{ row }">{{ row.to_mda?.code }} / {{ row.to_station?.name ?? row.to_department?.name ?? 'Unassigned' }}</template>
            <template #status="{ row }"><StatusPill :status="row.status" /></template>
        </DataTable>
    </section>
</template>
