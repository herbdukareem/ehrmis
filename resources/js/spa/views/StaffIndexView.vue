<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api } from '../lib/api';

const rows = ref([]);
const meta = ref(null);
const options = ref(null);
const busy = ref(true);
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

onMounted(async () => {
    options.value = (await api.get('/staff/options')).data.data;
    await load();
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
</template>
