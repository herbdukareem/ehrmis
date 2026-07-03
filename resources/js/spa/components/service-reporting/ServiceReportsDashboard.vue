<script setup>
import DataTable from '../DataTable.vue';
import StatusPill from '../StatusPill.vue';
import { formatDateTime, formatNumber } from '../../lib/serviceReporting';

defineProps({
    statCards: { type: Array, required: true },
    quickActions: { type: Array, required: true },
    pendingSubmissions: { type: Array, default: () => [] },
});

const pendingColumns = [
    { key: 'template', label: 'Template' },
    { key: 'period', label: 'Period' },
    { key: 'mda', label: 'MDA' },
    { key: 'station', label: 'Station / Facility' },
    { key: 'status', label: 'Status' },
    { key: 'updated_at', label: 'Last Updated' },
    { key: 'actions', label: 'Actions' },
];
</script>

<template>
    <section class="civic-reporting-stack">
        <div class="civic-reporting-intro">
            <div>
                <div class="civic-eyebrow">Workspace</div>
                <h2>MDA Service Reporting and Returns</h2>
                <p>Manage monthly service reports, facility returns, approvals, and reporting analytics.</p>
            </div>
        </div>

        <div class="civic-reporting-stat-grid">
            <article v-for="card in statCards" :key="card.key" class="civic-reporting-stat-card">
                <span>{{ card.label }}</span>
                <strong>{{ formatNumber(card.value) }}</strong>
                <small>{{ card.hint }}</small>
            </article>
        </div>

        <div class="civic-reporting-action-grid">
            <RouterLink v-for="action in quickActions" :key="action.label" :to="action.to" class="civic-reporting-action-card">
                <strong>{{ action.label }}</strong>
                <span>{{ action.description }}</span>
            </RouterLink>
        </div>

        <article class="civic-workspace civic-reporting-panel">
            <div class="civic-workspace-header">
                <div>
                    <div class="civic-eyebrow">Pending work</div>
                    <h2>Pending submissions</h2>
                    <p class="civic-section-note">Returned, submitted, and under-review returns that need attention.</p>
                </div>
            </div>

            <DataTable v-if="pendingSubmissions.length" :columns="pendingColumns" :rows="pendingSubmissions">
                <template #template="{ row }">{{ row.template?.name }}</template>
                <template #period="{ row }">{{ row.period?.label }}</template>
                <template #mda="{ row }">{{ row.mda?.code ?? row.mda?.name }}</template>
                <template #station="{ row }">{{ row.station?.name ?? 'MDA-level' }}</template>
                <template #status="{ row }"><StatusPill :value="row.status" /></template>
                <template #updated_at="{ row }">{{ formatDateTime(row.updated_at) }}</template>
                <template #actions="{ row }">
                    <RouterLink class="civic-table-action" :to="`/service-reports/submissions/${row.id}`">View</RouterLink>
                </template>
            </DataTable>

            <div v-else class="civic-reporting-empty">
                <strong>No pending submissions yet.</strong>
                <span>Create or submit a monthly return when a reporting period is open.</span>
            </div>
        </article>
    </section>
</template>
