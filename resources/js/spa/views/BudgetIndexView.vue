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
    { key: 'staff', label: 'Staff' }, { key: 'status', label: 'Record status' }, { key: 'approval_status', label: 'Approval' },
];
onMounted(async () => { rows.value = (await api.get('/budget-workbooks')).data.data.map((row) => ({ ...row, staff: row.summary?.staff_count ?? 0 })); });
</script>

<template>
    <PageHeading eyebrow="Recurrent personnel estimate" title="Budget workbooks" description="Approved workforce cost snapshots prepared from movement workbooks." />
    <section class="civic-workspace">
        <LoadingBlock v-if="!rows.length" />
        <DataTable v-else :columns="columns" :rows="rows">
            <template #id="{ row }"><RouterLink class="civic-record-link" :to="`/budget-workbooks/${row.id}`">Budget #{{ row.id }}</RouterLink></template>
            <template #mda="{ row }">{{ row.mda?.code }} — {{ row.mda?.name }}</template>
            <template #status="{ row }"><StatusPill :status="row.status" /></template>
            <template #approval_status="{ row }"><StatusPill :status="row.approval_status ?? 'draft'" /></template>
        </DataTable>
    </section>
</template>
