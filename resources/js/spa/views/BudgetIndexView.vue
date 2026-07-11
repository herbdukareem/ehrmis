<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { pushToast } from '../stores/app';
import { can } from '../stores/auth';

const router = useRouter();
const rows = ref([]);
const options = ref({ movement_workbooks: [] });
const busy = ref(true);
const generating = ref(false);
const feedback = ref('');
const form = reactive({
    movement_workbook_id: '',
});
const columns = [
    { key: 'id', label: 'Workbook' },
    { key: 'mda', label: 'MDA' },
    { key: 'year', label: 'Year' },
    { key: 'staff', label: 'Staff' },
    { key: 'status', label: 'Record status' },
    { key: 'approval_status', label: 'Approval' },
];

const selectedMovementWorkbook = computed(() => options.value.movement_workbooks.find((workbook) => workbook.id === form.movement_workbook_id));
const canGenerate = computed(() => can('create-budgets') && Boolean(form.movement_workbook_id) && !generating.value);

const load = async () => {
    busy.value = true;
    feedback.value = '';

    try {
        const response = await api.get('/budget-workbooks');
        rows.value = response.data.data.map((row) => ({ ...row, staff: row.summary?.staff_count ?? 0 }));
        options.value = response.data.options ?? { movement_workbooks: [] };

        if (!form.movement_workbook_id) {
            form.movement_workbook_id = options.value.movement_workbooks[0]?.id ?? '';
        }
    } catch (error) {
        feedback.value = apiMessage(error);
    } finally {
        busy.value = false;
    }
};

const generate = async () => {
    generating.value = true;
    feedback.value = '';

    try {
        const response = await api.post('/budget-workbooks', form);
        pushToast(response.data.message);
        await router.push(`/budget-workbooks/${response.data.data.id}`);
    } catch (error) {
        feedback.value = apiMessage(error);
    } finally {
        generating.value = false;
    }
};

onMounted(load);
</script>

<template>
    <PageHeading eyebrow="Recurrent personnel estimate" title="Budget workbooks" description="Approved workforce cost snapshots prepared from movement workbooks." />

    <section v-if="can('create-budgets')" class="civic-workspace civic-movement-create">
        <div class="civic-section-heading">
            <div><span class="civic-kicker">New workbook</span><h2>Generate budget estimate</h2></div>
            <small>Budget workbooks are created from approved or locked movement sheets.</small>
        </div>

        <div v-if="feedback" class="civic-error">{{ feedback }}</div>

        <p v-if="busy" class="civic-muted">Checking approved movement workbooks...</p>

        <form v-else-if="options.movement_workbooks.length" class="civic-form-grid" @submit.prevent="generate">
            <label class="civic-field civic-field-wide">
                <span>Approved movement workbook</span>
                <select v-model="form.movement_workbook_id" required>
                    <option v-for="workbook in options.movement_workbooks" :key="workbook.id" :value="workbook.id">
                        {{ workbook.label }}{{ workbook.budget_workbook_id ? ' - existing budget will be refreshed' : '' }}
                    </option>
                </select>
                <small v-if="selectedMovementWorkbook">
                    {{ selectedMovementWorkbook.name }} - {{ selectedMovementWorkbook.status }}
                </small>
            </label>

            <div class="civic-field civic-form-action">
                <span>Create recurrent estimate</span>
                <button class="civic-button civic-button-primary" :disabled="!canGenerate">
                    {{ generating ? 'Generating...' : 'Create budget workbook' }}
                </button>
            </div>
        </form>

        <p v-else class="civic-muted">
            No approved movement workbook is available yet. Approve or lock a movement sheet first, then come back here to generate the budget workbook.
        </p>
    </section>

    <section class="civic-workspace">
        <div class="civic-section-heading"><div><span class="civic-kicker">Workbook history</span><h2>Budget estimates</h2></div></div>

        <LoadingBlock v-if="busy" />
        <DataTable v-else :columns="columns" :rows="rows">
            <template #id="{ row }"><RouterLink class="civic-record-link" :to="`/budget-workbooks/${row.id}`">Budget #{{ row.id }}</RouterLink></template>
            <template #mda="{ row }">{{ row.mda?.code }} - {{ row.mda?.name }}</template>
            <template #status="{ row }"><StatusPill :status="row.status" /></template>
            <template #approval_status="{ row }"><StatusPill :status="row.approval_status ?? 'draft'" /></template>
        </DataTable>
    </section>
</template>
