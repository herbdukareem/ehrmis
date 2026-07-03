<script setup>
import DataTable from '../DataTable.vue';
import { formatNumber } from '../../lib/serviceReporting';

defineProps({
    analyticsForm: { type: Object, required: true },
    analytics: { type: Object, default: null },
    templates: { type: Array, default: () => [] },
    indicators: { type: Array, default: () => [] },
    isGlobalUser: { type: Boolean, default: false },
    mdas: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
});

defineEmits(['run-analytics']);

const analyticsColumns = {
    yearly: [
        { key: 'year', label: 'Year' },
        { key: 'value', label: 'Total' },
    ],
    monthly: [
        { key: 'period', label: 'Month' },
        { key: 'value', label: 'Total' },
    ],
    facility: [
        { key: 'station_name', label: 'Station / Facility' },
        { key: 'value', label: 'Total' },
    ],
};
</script>

<template>
    <section class="civic-reporting-stack">
        <article class="civic-workspace civic-reporting-panel">
            <form class="civic-filter-line civic-reporting-filters" @submit.prevent="$emit('run-analytics')">
                <label class="civic-field">
                    <span>Template</span>
                    <select v-model="analyticsForm.template_code">
                        <option v-for="template in templates" :key="template.code" :value="template.code">{{ template.name }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Indicator</span>
                    <select v-model="analyticsForm.indicator_code">
                        <option v-for="indicator in indicators" :key="indicator.code" :value="indicator.code">{{ indicator.label }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>From Month/Year</span>
                    <input v-model="analyticsForm.from" type="month">
                </label>
                <label class="civic-field">
                    <span>To Month/Year</span>
                    <input v-model="analyticsForm.to" type="month">
                </label>
                <label v-if="isGlobalUser" class="civic-field">
                    <span>MDA</span>
                    <select v-model="analyticsForm.mda_id">
                        <option value="">All visible MDAs</option>
                        <option v-for="mda in mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Station / Facility</span>
                    <select v-model="analyticsForm.station_id">
                        <option value="">All stations</option>
                        <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Status</span>
                    <select v-model="analyticsForm.status">
                        <option value="approved,locked">Approved / Locked</option>
                        <option value="approved">Approved</option>
                        <option value="locked">Locked</option>
                        <option value="submitted,under_review,approved,locked">Submitted and above</option>
                    </select>
                </label>
                <div class="civic-filter-actions">
                    <button class="civic-button civic-button-primary" :disabled="busy">Run analytics</button>
                </div>
            </form>
        </article>

        <template v-if="analytics">
            <div class="civic-reporting-stat-grid civic-reporting-stat-grid-compact">
                <article class="civic-reporting-stat-card">
                    <span>Grand Total</span>
                    <strong>{{ formatNumber(analytics.totals.grand_total) }}</strong>
                    <small>{{ analytics.indicator.label }}</small>
                </article>
                <article v-for="row in analytics.by_year" :key="row.year" class="civic-reporting-stat-card">
                    <span>{{ row.year }} Total</span>
                    <strong>{{ formatNumber(row.value) }}</strong>
                    <small>{{ analytics.indicator.label }}</small>
                </article>
            </div>

            <div v-if="Number(analytics.totals.grand_total) === 0" class="civic-reporting-empty">
                <strong>No approved data found for the selected filters.</strong>
                <span>Try a wider period range, another station, or a different indicator.</span>
            </div>

            <article class="civic-workspace civic-reporting-panel">
                <div class="civic-section-heading"><h2>Yearly breakdown</h2></div>
                <DataTable :columns="analyticsColumns.yearly" :rows="analytics.by_year" row-key="year">
                    <template #value="{ row }">{{ formatNumber(row.value) }}</template>
                </DataTable>
            </article>

            <article class="civic-workspace civic-reporting-panel">
                <div class="civic-section-heading"><h2>Monthly trend</h2></div>
                <DataTable :columns="analyticsColumns.monthly" :rows="analytics.series" row-key="period">
                    <template #value="{ row }">{{ formatNumber(row.value) }}</template>
                </DataTable>
            </article>

            <article class="civic-workspace civic-reporting-panel">
                <div class="civic-section-heading"><h2>Facility comparison</h2></div>
                <DataTable v-if="analytics.facility_comparison.length" :columns="analyticsColumns.facility" :rows="analytics.facility_comparison" row-key="station_id">
                    <template #value="{ row }">{{ formatNumber(row.value) }}</template>
                </DataTable>
                <div v-else class="civic-reporting-empty">
                    <strong>No facility comparison available.</strong>
                    <span>Facility-level data appears here when station submissions exist.</span>
                </div>
            </article>
        </template>
    </section>
</template>
