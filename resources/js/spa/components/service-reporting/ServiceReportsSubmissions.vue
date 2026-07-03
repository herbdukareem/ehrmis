<script setup>
import DataTable from '../DataTable.vue';
import StatusPill from '../StatusPill.vue';
import { formatDateTime, submittedBy } from '../../lib/serviceReporting';

defineProps({
    filters: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
    monthOptions: { type: Array, default: () => [] },
    yearOptions: { type: Array, default: () => [] },
    isGlobalUser: { type: Boolean, default: false },
    mdas: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    submissions: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    canReview: { type: Boolean, default: false },
    canApprove: { type: Boolean, default: false },
    canLock: { type: Boolean, default: false },
});

defineEmits(['apply-filters', 'clear-filters', 'continue-draft']);

const submissionColumns = [
    { key: 'template', label: 'Template' },
    { key: 'period', label: 'Period' },
    { key: 'mda', label: 'MDA' },
    { key: 'station', label: 'Station / Facility' },
    { key: 'status', label: 'Status' },
    { key: 'submitted_by', label: 'Submitted By' },
    { key: 'submitted_at', label: 'Submitted At' },
    { key: 'actions', label: 'Actions' },
];
</script>

<template>
    <section class="civic-reporting-stack">
        <article class="civic-workspace civic-reporting-panel">
            <form class="civic-filter-line civic-reporting-filters" @submit.prevent="$emit('apply-filters')">
                <label class="civic-field">
                    <span>Template</span>
                    <select v-model="filters.template_id">
                        <option value="">All templates</option>
                        <option v-for="template in templates" :key="template.id" :value="template.id">{{ template.name }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Status</span>
                    <select v-model="filters.status">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="submitted">Submitted</option>
                        <option value="under_review">Under review</option>
                        <option value="returned">Returned</option>
                        <option value="approved">Approved</option>
                        <option value="locked">Locked</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Month</span>
                    <select v-model="filters.month">
                        <option value="">All months</option>
                        <option v-for="month in monthOptions" :key="month.value" :value="month.value">{{ month.label }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Year</span>
                    <select v-model="filters.year">
                        <option value="">All years</option>
                        <option v-for="year in yearOptions" :key="year" :value="year">{{ year }}</option>
                    </select>
                </label>
                <label v-if="isGlobalUser" class="civic-field">
                    <span>MDA</span>
                    <select v-model="filters.mda_id">
                        <option value="">All visible MDAs</option>
                        <option v-for="mda in mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Station / Facility</span>
                    <select v-model="filters.station_id">
                        <option value="">All stations</option>
                        <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
                    </select>
                </label>
                <div class="civic-filter-actions">
                    <button class="civic-button civic-button-primary" type="submit" :disabled="busy">Apply</button>
                    <button class="civic-button" type="button" :disabled="busy" @click="$emit('clear-filters')">Clear</button>
                </div>
            </form>

            <DataTable v-if="submissions.length" :columns="submissionColumns" :rows="submissions">
                <template #template="{ row }"><RouterLink class="civic-record-link" :to="`/service-reports/submissions/${row.id}`">{{ row.template?.name }}</RouterLink></template>
                <template #period="{ row }">{{ row.period?.label }}</template>
                <template #mda="{ row }">{{ row.mda?.code ?? row.mda?.name }}</template>
                <template #station="{ row }">{{ row.station?.name ?? 'MDA-level' }}</template>
                <template #status="{ row }"><StatusPill :value="row.status" /></template>
                <template #submitted_by="{ row }">{{ submittedBy(row) }}</template>
                <template #submitted_at="{ row }">{{ formatDateTime(row.submitted_at) }}</template>
                <template #actions="{ row }">
                    <div class="civic-table-actions">
                        <RouterLink class="civic-table-action" :to="`/service-reports/submissions/${row.id}`">View</RouterLink>
                        <button v-if="['draft', 'returned'].includes(row.status)" class="civic-table-action" type="button" @click="$emit('continue-draft', row)">Continue Draft</button>
                        <RouterLink v-if="canReview && ['submitted', 'under_review'].includes(row.status)" class="civic-table-action" :to="`/service-reports/submissions/${row.id}`">Review</RouterLink>
                        <RouterLink v-if="canApprove && ['submitted', 'under_review'].includes(row.status)" class="civic-table-action" :to="`/service-reports/submissions/${row.id}`">Approve</RouterLink>
                        <RouterLink v-if="canLock && row.status === 'approved'" class="civic-table-action" :to="`/service-reports/submissions/${row.id}`">Lock</RouterLink>
                    </div>
                </template>
            </DataTable>

            <div v-else class="civic-reporting-empty">
                <strong>No submissions found.</strong>
                <span>Adjust the filters or create a monthly return.</span>
            </div>
        </article>
    </section>
</template>
