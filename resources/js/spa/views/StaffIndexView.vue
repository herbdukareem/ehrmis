<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const rows = ref([]);
const meta = ref(null);
const options = ref(null);
const busy = ref(true);
const flaggedStaff = ref([]);
const showFlaggedModal = ref(false);
const showEditModal = ref(false);
const editingStaff = ref(null);
const editBusy = ref(false);
const editError = ref('');
const issueRanks = computed(() => options.value?.ranks?.filter((rank) => !editForm.cadre_id || rank.cadre_id === Number(editForm.cadre_id)) ?? []);
const editForm = reactive({
    date_of_birth: '',
    cadre_id: '',
    rank_id: '',
    qualification_type_id: '',
    allowance_ids: [],
});
const filters = reactive({
    search: '',
    cno: '',
    psn: '',
    department_id: '',
    status: '',
    cadre_id: '',
    rank_id: '',
    page: 1,
});
let timer;
const cadres = computed(() => options.value?.cadres?.filter((cadre) => !filters.department_id || cadre.department_id === Number(filters.department_id)) ?? []);
const ranks = computed(() => options.value?.ranks?.filter((rank) => !filters.cadre_id || rank.cadre_id === Number(filters.cadre_id)) ?? []);

const columns = [
    { key: 'staff_number', label: 'Staff no.' },
    { key: 'full_name', label: 'Officer' },
    { key: 'department', label: 'Department' },
    { key: 'cadre', label: 'Cadre' },
    { key: 'salary_display', label: 'Placement' },
    { key: 'status', label: 'Status' },
];

const load = async () => {
    busy.value = true;
    const response = await api.get('/staff', { params: filters });
    rows.value = response.data.data;
    meta.value = response.data.meta;
    busy.value = false;
};

watch(() => [filters.search, filters.cno, filters.psn, filters.department_id, filters.status, filters.cadre_id, filters.rank_id], () => {
    clearTimeout(timer);
    filters.page = 1;
    timer = setTimeout(load, 250);
});

watch(() => filters.department_id, () => {
    if (filters.cadre_id && !cadres.value.some((cadre) => cadre.id === Number(filters.cadre_id))) {
        filters.cadre_id = '';
    }
});

watch(() => filters.cadre_id, () => {
    if (filters.rank_id && !ranks.value.some((rank) => rank.id === Number(filters.rank_id))) {
        filters.rank_id = '';
    }
});

const resetFilters = () => {
    Object.assign(filters, { search: '', cno: '', psn: '', department_id: '', status: '', cadre_id: '', rank_id: '', page: 1 });
};

const loadFlagged = async () => {
    flaggedStaff.value = (await api.get('/staff/flagged-issues')).data.data;
};

const openEditModal = async (staffSummary) => {
    showFlaggedModal.value = false;
    editError.value = '';
    editingStaff.value = (await api.get(`/staff/${staffSummary.id}`)).data.data;
    Object.assign(editForm, {
        date_of_birth: editingStaff.value.date_of_birth ?? '',
        cadre_id: editingStaff.value.current_employment?.cadre_id ?? '',
        rank_id: editingStaff.value.current_employment?.rank_id ?? '',
        qualification_type_id: editingStaff.value.qualifications?.find((q) => q.is_highest)?.qualification_type_id ?? '',
        allowance_ids: editingStaff.value.allowance_assignments?.filter((a) => a.is_eligible).map((a) => a.allowance_type_id) ?? [],
    });
    showEditModal.value = true;
};

const closeEditModal = () => {
    showEditModal.value = false;
    editingStaff.value = null;
    showFlaggedModal.value = flaggedStaff.value.length > 0;
};

const saveIssueResolution = async () => {
    editBusy.value = true;
    editError.value = '';

    try {
        await api.put(`/staff/${editingStaff.value.id}/flagged-issues`, {
            date_of_birth: editForm.date_of_birth || null,
            cadre_id: editForm.cadre_id || null,
            rank_id: editForm.rank_id || null,
            qualification_type_id: editForm.qualification_type_id || null,
            allowances: (options.value?.allowance_types ?? []).map((type) => ({
                allowance_type_id: type.id,
                is_eligible: editForm.allowance_ids.includes(type.id),
            })),
        });
        await loadFlagged();
        showEditModal.value = false;
        editingStaff.value = null;
        showFlaggedModal.value = flaggedStaff.value.length > 0;
        await load();
    } catch (error) {
        editError.value = apiMessage(error);
    } finally {
        editBusy.value = false;
    }
};

onMounted(async () => {
    options.value = (await api.get('/staff/options')).data.data;
    await load();
    await loadFlagged();
    showFlaggedModal.value = flaggedStaff.value.length > 0;
});
</script>

<template>
    <PageHeading eyebrow="Establishment record" title="Staff registry" description="Search and inspect the authoritative workforce register." />
    <section class="civic-workspace">
        <div class="civic-filter-line">
            <label class="civic-field civic-field-search"><span>Search registry</span><input v-model="filters.search" placeholder="Staff number or officer name"></label>
            <label class="civic-field"><span>CNO</span><input v-model="filters.cno" placeholder="Filter by CNO"></label>
            <label class="civic-field"><span>PSN</span><input v-model="filters.psn" placeholder="Filter by PSN"></label>
        </div>
        <div class="civic-filter-line">
            <label class="civic-field"><span>Department</span><select v-model="filters.department_id"><option value="">All departments</option><option v-for="department in options?.departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></label>
            <label class="civic-field"><span>Status</span><select v-model="filters.status"><option value="">All statuses</option><option v-for="status in options?.statuses" :key="status">{{ status }}</option></select></label>
            <label class="civic-field"><span>Cadre</span><select v-model="filters.cadre_id"><option value="">All cadres</option><option v-for="cadre in cadres" :key="cadre.id" :value="cadre.id">{{ cadre.name }}</option></select></label>
            <label class="civic-field"><span>Rank</span><select v-model="filters.rank_id"><option value="">All ranks</option><option v-for="rank in ranks" :key="rank.id" :value="rank.id">{{ rank.name }}</option></select></label>
            <button class="civic-button" type="button" @click="resetFilters">Clear filters</button>
            <div class="civic-record-count">{{ meta?.total ?? 0 }} records</div>
        </div>
        <LoadingBlock v-if="busy" />
        <DataTable v-else :columns="columns" :rows="rows">
            <template #staff_number="{ row }"><RouterLink class="civic-record-link" :to="`/staff/${row.id}`">{{ row.staff_number }}</RouterLink></template>
            <template #full_name="{ row }"><div class="civic-primary-cell">{{ row.full_name }}</div><small>{{ row.mda?.code ?? 'No MDA' }}</small></template>
            <template #status="{ row }"><StatusPill :status="row.status" /></template>
        </DataTable>
        <div v-if="meta?.last_page > 1" class="civic-pagination">
            <button :disabled="meta.current_page === 1" @click="filters.page--; load()">Previous</button>
            <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button :disabled="meta.current_page === meta.last_page" @click="filters.page++; load()">Next</button>
        </div>
    </section>

    <div v-if="showFlaggedModal" class="civic-preview-overlay" @click.self="showFlaggedModal = false">
        <section class="civic-camera-modal">
            <header class="civic-workspace-header">
                <div>
                    <div class="civic-eyebrow">Needs review</div>
                    <h2>{{ flaggedStaff.length }} staff record(s) with unresolved cadre, rank, qualification, or allowance issues</h2>
                </div>
                <button class="civic-button" type="button" @click="showFlaggedModal = false">Close</button>
            </header>
            <div class="civic-camera-modal-body">
                <ul class="civic-flagged-staff-list">
                    <li v-for="staff in flaggedStaff" :key="staff.id">
                        <div class="civic-flagged-staff-row">
                            <div>
                                <RouterLink class="civic-record-link" :to="`/staff/${staff.id}`">{{ staff.full_name }}</RouterLink>
                                <small>{{ staff.staff_number }} · {{ staff.mda ?? 'No MDA' }}</small>
                            </div>
                            <button class="civic-button" type="button" @click="openEditModal(staff)">Edit</button>
                        </div>
                        <ul>
                            <li v-for="(issue, index) in staff.issues" :key="index">{{ issue.message }}</li>
                        </ul>
                    </li>
                </ul>
            </div>
        </section>
    </div>

    <div v-if="showEditModal" class="civic-preview-overlay" @click.self="closeEditModal">
        <section class="civic-camera-modal">
            <header class="civic-workspace-header">
                <div>
                    <div class="civic-eyebrow">Resolve flagged issues</div>
                    <h2>{{ editingStaff?.full_name }}</h2>
                </div>
                <button class="civic-button" type="button" @click="closeEditModal">Close</button>
            </header>
            <div class="civic-camera-modal-body">
                <div v-if="editError" class="civic-error">{{ editError }}</div>
                <div class="civic-form-grid">
                    <label class="civic-field"><span>Date of birth</span><input v-model="editForm.date_of_birth" type="date"></label>
                    <label class="civic-field"><span>Cadre</span><select v-model="editForm.cadre_id"><option value="">No change</option><option v-for="cadre in options?.cadres" :key="cadre.id" :value="cadre.id">{{ cadre.name }}</option></select></label>
                    <label class="civic-field"><span>Rank</span><select v-model="editForm.rank_id"><option value="">No change</option><option v-for="rank in issueRanks" :key="rank.id" :value="rank.id">{{ rank.name }}</option></select></label>
                    <label class="civic-field"><span>Highest qualification</span><select v-model="editForm.qualification_type_id"><option value="">No change</option><option v-for="qualification in options?.qualification_types" :key="qualification.id" :value="qualification.id">{{ qualification.name }}</option></select></label>
                </div>
                <div class="civic-allowance-editor">
                    <div class="civic-eyebrow">Allowances</div>
                    <div class="civic-check-grid civic-allowance-check-grid">
                        <label v-for="type in options?.allowance_types" :key="type.id" class="civic-check">
                            <input v-model="editForm.allowance_ids" type="checkbox" :value="type.id">
                            <span>{{ type.name }}</span>
                        </label>
                    </div>
                </div>
                <button class="civic-button civic-button-primary" type="button" :disabled="editBusy" @click="saveIssueResolution">
                    {{ editBusy ? 'Saving...' : 'Save and resolve issues' }}
                </button>
            </div>
        </section>
    </div>
</template>
