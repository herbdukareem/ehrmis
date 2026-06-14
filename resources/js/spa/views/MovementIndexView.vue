<script setup>
import { onMounted, ref } from 'vue';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api } from '../lib/api';

const rows = ref([]);
const columns = [
    { key: 'id', label: 'Workbook' }, { key: 'mda', label: 'MDA' }, { key: 'year', label: 'Year' },
    { key: 'lines', label: 'Lines' }, { key: 'status', label: 'Record status' }, { key: 'approval_status', label: 'Approval' },
];
onMounted(async () => {
    rows.value = (await api.get('/movement-workbooks')).data.data.map((row) => ({ ...row, lines: row.summary?.lines_generated ?? 0 }));
});
</script>

<template>
    <PageHeading eyebrow="Annual personnel movement" title="Movement workbooks" description="Review promotion, retirement, and establishment movement snapshots." />
    <section class="civic-workspace">
        <LoadingBlock v-if="!rows.length" />
        <DataTable v-else :columns="columns" :rows="rows">
            <template #id="{ row }"><RouterLink class="civic-record-link" :to="`/movement-workbooks/${row.id}`">Workbook #{{ row.id }}</RouterLink></template>
            <template #mda="{ row }">{{ row.mda?.code }} — {{ row.mda?.name }}</template>
            <template #status="{ row }"><StatusPill :status="row.status" /></template>
            <template #approval_status="{ row }"><StatusPill :status="row.approval_status ?? 'draft'" /></template>
        </DataTable>
    </section>
</template>
