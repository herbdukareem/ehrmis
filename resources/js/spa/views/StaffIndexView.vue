<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api } from '../lib/api';

const rows = ref([]);
const meta = ref(null);
const options = ref(null);
const busy = ref(true);
const filters = reactive({ search: '', status: '', page: 1 });
let timer;

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

watch(() => [filters.search, filters.status], () => {
    clearTimeout(timer);
    filters.page = 1;
    timer = setTimeout(load, 250);
});

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
            <label class="civic-field"><span>Status</span><select v-model="filters.status"><option value="">All statuses</option><option v-for="status in options?.statuses" :key="status">{{ status }}</option></select></label>
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
