<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppStatCard from '@/Components/AppStatCard.vue';
import AppTable from '@/Components/AppTable.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import { api, apiMessage } from '../../lib/api';
import { officeDownload, officeLabel as label } from '../../lib/executiveOffice';
const props = defineProps({ context: { type: Object, required: true }, catalog: { type: Object, required: true } });
const emit = defineEmits(['open']);
const data = ref(null), busy = ref(false), error = ref(''), applied = ref(null), options = ref({ users: [], departments: [] });
const filters = reactive({ type: '', status: '', priority: '', assigned_to: '', department_id: '', from: '', to: '' });
const dirty = computed(() => applied.value && JSON.stringify(filters) !== JSON.stringify(applied.value));
const statuses = computed(() => [...new Set(filters.type ? props.catalog.states[filters.type] : Object.values(props.catalog.states).flat())].map(value => ({ value, label: label(value) })));
async function generate(page = 1, previous = false) { busy.value = true; error.value = ''; const requested = JSON.parse(JSON.stringify(previous ? applied.value : filters)); try { data.value = (await api.get('/executive-office/reports', { params: { ...requested, mda_id: props.context.mda_id, page } })).data; applied.value = requested; } catch (e) { error.value = apiMessage(e); } finally { busy.value = false; } }
async function download() { busy.value = true; try { await officeDownload('/executive-office/reports/export', { ...applied.value, mda_id: props.context.mda_id }, 'executive-office-report.xlsx'); } catch (e) { error.value = apiMessage(e); } finally { busy.value = false; } }
onMounted(async () => { try { options.value = (await api.get('/executive-office/options', { params: { mda_id: props.context.mda_id } })).data; await generate(); } catch (e) { error.value = apiMessage(e); } });
</script>
<template>
    <div class="space-y-5">
        <AppAlert v-if="error" tone="danger">{{ error }}</AppAlert>
        <AppCard title="Office activity reports" subtitle="Filter records by registration date. Completion and overdue figures reflect their current state; Excel includes every matching record.">
            <form class="space-y-4" @submit.prevent="generate()"><fieldset :disabled="busy" class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-3">
                <AppTextInput v-model="filters.from" label="Registered from" type="date" /><AppTextInput v-model="filters.to" label="Registered to" type="date" />
                <AppSelect v-model="filters.type" label="Record type" :options="['appointment', 'correspondence', 'directive', 'meeting', 'visitor', 'briefing'].map(value => ({ value, label: label(value) }))" placeholder="All types" />
                <AppSelect v-model="filters.priority" label="Priority" :options="['normal', 'high', 'urgent'].map(value => ({ value, label: label(value) }))" placeholder="All priorities" />
                <AppSelect v-model="filters.department_id" label="Department" :options="options.departments" option-label="name" option-value="id" placeholder="All departments" />
                <AppSelect v-model="filters.assigned_to" label="Responsible officer" :options="options.users" option-label="name" option-value="id" placeholder="All officers" />
                <AppSelect v-model="filters.status" label="Status" :options="statuses" placeholder="All statuses" />
            </fieldset><div class="flex flex-wrap gap-3"><AppButton type="submit" :disabled="busy">Generate office report</AppButton><AppButton variant="outline" :disabled="busy || !data?.summary.total || !!dirty" @click="download">Download Excel</AppButton><span v-if="dirty" class="text-sm text-ehrmis-muted">Generate again to apply changed filters.</span></div></form>
        </AppCard>
        <template v-if="data">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"><AppStatCard v-for="(value, key) in data.summary" :key="key" :label="label(key)" :value="value ?? 'Not available'" /></div>
            <AppCard title="Department activity"><AppTable :rows="data.by_department" :headers="[{ key: 'department', label: 'Department' }, { key: 'total', label: 'Registered matters' }]" /></AppCard>
            <AppCard title="Matching office records"><AppTable :rows="data.data" :headers="[{ key: 'reference', label: 'Reference' }, { key: 'subject', label: 'Subject' }, { key: 'status', label: 'Status' }, { key: 'open', label: 'Action' }]"><template #cell-status="{ value }">{{ label(value) }}</template><template #cell-open="{ row }"><AppButton variant="outline" size="sm" @click="emit('open', row)">Open</AppButton></template></AppTable><div v-if="data.meta.last_page > 1" class="mt-4 flex gap-3"><AppButton variant="outline" :disabled="busy || data.meta.current_page <= 1" @click="generate(data.meta.current_page - 1, true)">Previous</AppButton><span>{{ data.meta.current_page }} / {{ data.meta.last_page }}</span><AppButton variant="outline" :disabled="busy || data.meta.current_page >= data.meta.last_page" @click="generate(data.meta.current_page + 1, true)">Next</AppButton></div></AppCard>
        </template>
    </div>
</template>
