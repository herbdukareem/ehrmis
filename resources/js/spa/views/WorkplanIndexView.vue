<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { can } from '../stores/auth';

const router = useRouter();

const rows = ref([]);
const mdas = ref([]);
const loading = ref(true);
const error = ref('');
const saving = ref(false);

const form = reactive({
    mda_id: '',
    year: new Date().getFullYear(),
    title: '',
    description: '',
});

const columns = [
    { key: 'title', label: 'Annual workplan' },
    { key: 'mda', label: 'MDA' },
    { key: 'year', label: 'Year' },
    { key: 'revision_no', label: 'Revision' },
    { key: 'status', label: 'Status' },
];

const workplanCountLabel = computed(() => `${rows.value.length} ${rows.value.length === 1 ? 'workplan' : 'workplans'} in view`);

const load = async () => {
    loading.value = true;

    try {
        const response = await api.get('/workplans');
        rows.value = response.data.data;
        mdas.value = response.data.options?.mdas ?? [];

        if (!form.mda_id && mdas.value.length === 1) {
            form.mda_id = mdas.value[0].id;
        }
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        loading.value = false;
    }
};

const create = async () => {
    saving.value = true;
    error.value = '';

    try {
        const response = await api.post('/workplans', form);
        const workplan = response.data?.data;

        if (!workplan?.id) {
            throw new Error('The draft was created but its identifier was not returned.');
        }

        await router.push(`/workplans/${workplan.id}/edit`);
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        saving.value = false;
    }
};

onMounted(load);
</script>

<template>
    <PageHeading
        eyebrow="Performance management"
        title="Annual workplans"
        description="Draft annual commitments, accountable officers, target indicators, and revision-controlled performance baselines."
    />

    <div class="civic-page-stack">
        <section v-if="can('create-workplans')" class="civic-workspace civic-movement-create">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">New annual plan</span>
                    <h2>Create a draft</h2>
                    <p>Start with the MDA, workplan year, and a clear title. The draft opens immediately in the editor for objectives, activities, and targets.</p>
                </div>
                <span class="civic-inline-note">Draft revision 1 only</span>
            </div>

            <div class="civic-workspace-body">
                <div v-if="error" class="civic-error">{{ error }}</div>

                <form class="civic-form-grid" @submit.prevent="create">
                    <label class="civic-field">
                        <span>MDA</span>
                        <select v-model.number="form.mda_id" required>
                            <option value="" disabled>Select MDA</option>
                            <option v-for="mda in mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                        </select>
                    </label>

                    <label class="civic-field">
                        <span>Year</span>
                        <input v-model.number="form.year" type="number" min="2020" max="2100" required>
                    </label>

                    <label class="civic-field civic-field-wide">
                        <span>Title</span>
                        <input v-model="form.title" required placeholder="Annual Workplan">
                    </label>

                    <label class="civic-field civic-field-wide">
                        <span>Description</span>
                        <textarea v-model="form.description" rows="3"></textarea>
                    </label>

                    <div class="civic-field civic-form-action">
                        <span>Draft status</span>
                        <button class="civic-button civic-button-primary" :disabled="saving">
                            {{ saving ? 'Creating...' : 'Create draft' }}
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Revision register</span>
                    <h2>Workplan directory</h2>
                    <p>Each row represents a revision-controlled annual workplan. Open a revision to review status, drill into targets, or continue authoring.</p>
                </div>
                <span class="civic-inline-note">{{ workplanCountLabel }}</span>
            </div>

            <LoadingBlock v-if="loading" />
            <div v-else class="civic-performance-table-wrap">
                <DataTable :columns="columns" :rows="rows">
                    <template #title="{ row }">
                        <RouterLink class="civic-record-link" :to="`/workplans/${row.id}`">{{ row.title }}</RouterLink>
                    </template>
                    <template #mda="{ row }">{{ row.mda?.code }} - {{ row.mda?.name }}</template>
                    <template #status="{ row }"><StatusPill :status="row.status" /></template>
                </DataTable>
            </div>
        </section>
    </div>
</template>
