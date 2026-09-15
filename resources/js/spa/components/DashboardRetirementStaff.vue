<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppLoadingState from '@/Components/AppLoadingState.vue';
import AppTable from '@/Components/AppTable.vue';
import StatusPill from './StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const props = defineProps({ year: { type: Number, required: true }, kind: { type: String, required: true } });
defineEmits(['close']);
const rows = ref([]);
const meta = ref(null);
const loading = ref(false);
const error = ref('');
let requestVersion = 0;
const title = computed(() => `${props.year} ${props.kind === 'history' ? 'retirement history' : 'projected retirements'}`);
const headers = [
    { key: 'serial', label: 'S/N' }, { key: 'staff_number', label: 'Staff number' },
    { key: 'full_name', label: 'Staff name' }, { key: 'mda', label: 'MDA' },
    { key: 'department', label: 'Department' }, { key: 'station', label: 'Station' },
    { key: 'cadre', label: 'Cadre' }, { key: 'rank', label: 'Rank' },
    { key: 'retirement_date', label: 'Retirement date' }, { key: 'status', label: 'Staff status' },
];
const tableRows = computed(() => rows.value.map((row, index) => ({ ...row, serial: (meta.value?.from ?? 1) + index })));
const date = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

async function load(page = 1) {
    const version = ++requestVersion;
    loading.value = true;
    error.value = '';
    rows.value = [];
    meta.value = null;
    try {
        const response = await api.get('/dashboard/retirement-staff', { params: { year: props.year, page, per_page: 50 } });
        if (version !== requestVersion) return;
        rows.value = response.data.data;
        meta.value = response.data.meta;
    } catch (failure) {
        if (version !== requestVersion) return;
        error.value = apiMessage(failure, 'The staff list could not be loaded. Please try again.');
    } finally {
        if (version === requestVersion) loading.value = false;
    }
}

watch(() => props.year, () => load(), { immediate: true });
onBeforeUnmount(() => { requestVersion++; });
</script>

<template>
    <AppCard id="retirement-staff-list" class="mt-6" :title="title" :subtitle="meta ? `${meta.total.toLocaleString()} staff match this year within your dashboard access.` : 'Staff included in the selected retirement bar.'" :aria-busy="loading">
        <template #actions>
            <AppButton variant="outline" size="sm" @click="$emit('close')">Close list</AppButton>
        </template>
        <p v-if="kind === 'history'" class="mb-4 text-sm text-ehrmis-muted">Uses recorded retirement dates, or expected dates where no retirement history is recorded.</p>
        <AppLoadingState v-if="loading" :lines="4" role="status" aria-label="Loading affected staff" />
        <AppAlert v-else-if="error" tone="danger" title="Staff list unavailable">
            {{ error }}
            <AppButton class="mt-3" variant="outline" size="sm" @click="load()">Try again</AppButton>
        </AppAlert>
        <AppEmptyState v-else-if="!rows.length" title="No staff for this year" message="Choose another retirement bar to view its staff list." />
        <template v-else>
            <AppTable :headers="headers" :rows="tableRows">
                <template #cell="{ row, value, column }">
                    <template v-if="column.key === 'full_name'">
                        <RouterLink v-if="row.can_view_record" :to="`/staff/${row.id}`" class="font-semibold text-ehrmis-primary-700 underline hover:text-ehrmis-primary-900">{{ value }}</RouterLink>
                        <span v-else>{{ value }}</span>
                    </template>
                    <template v-else-if="column.key === 'retirement_date'">
                        <span class="whitespace-nowrap">{{ date(value) }}</span>
                        <span class="block text-xs text-ehrmis-muted">{{ row.retirement_date_source }}</span>
                    </template>
                    <StatusPill v-else-if="column.key === 'status'" :status="value" />
                    <span v-else>{{ value ?? '—' }}</span>
                </template>
            </AppTable>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm text-ehrmis-muted" role="status">Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total.toLocaleString() }} staff</span>
                <div v-if="meta.last_page > 1" class="flex gap-2">
                    <AppButton variant="outline" size="sm" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Previous</AppButton>
                    <AppButton variant="outline" size="sm" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Next</AppButton>
                </div>
            </div>
        </template>
    </AppCard>
</template>
