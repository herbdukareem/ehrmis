<script setup>
import { computed } from 'vue';
import DataTable from '../DataTable.vue';
import HorizontalBarChart from '../HorizontalBarChart.vue';
import VerticalBarChart from '../VerticalBarChart.vue';
import { formatNumber } from '../../lib/serviceReporting';

const props = defineProps({
    analyticsForm: { type: Object, required: true },
    analytics: { type: Object, default: null },
    templates: { type: Array, default: () => [] },
    indicators: { type: Array, default: () => [] },
    isGlobalUser: { type: Boolean, default: false },
    mdas: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    canExport: { type: Boolean, default: false },
});

const emit = defineEmits(['run-analytics', 'change-report-style', 'download-report']);

const analyticsSets = computed(() => props.analytics?.indicators ?? (props.analytics ? [props.analytics] : []));
const formatTableValue = (value) => value === null || value === undefined || value === '' ? '—' : typeof value === 'number' ? formatNumber(value) : value;
const selectedIndicators = computed(() => props.indicators.filter((indicator) => props.analyticsForm.indicator_codes.includes(indicator.code)));
const indicatorSelectionLabel = computed(() => {
    if (!selectedIndicators.value.length) return 'Select indicators';
    if (selectedIndicators.value.length === 1) return selectedIndicators.value[0].label;

    return `${selectedIndicators.value.length} indicators selected`;
});

function changeReportStyle(style) {
    if (props.analyticsForm.report_style === style) return;

    props.analyticsForm.report_style = style;
    emit('change-report-style');
}

function toggleIndicator(code) {
    const selected = props.analyticsForm.indicator_codes;
    const index = selected.indexOf(code);

    if (index >= 0) {
        selected.splice(index, 1);
    } else if (selected.length < 6) {
        selected.push(code);
    }
}

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
                <div class="civic-reporting-style-toggle" role="group" aria-label="Analytics report style">
                    <button type="button" :class="{ active: analyticsForm.report_style === 'trend' }" @click="changeReportStyle('trend')">Charts & trends</button>
                    <button type="button" :class="{ active: analyticsForm.report_style === 'template_table' }" @click="changeReportStyle('template_table')">Template table</button>
                </div>
                <label class="civic-field">
                    <span>Template</span>
                    <select v-model="analyticsForm.template_code">
                        <option v-for="template in templates" :key="template.code" :value="template.code">{{ template.name }}</option>
                    </select>
                </label>
                <div v-if="analyticsForm.report_style === 'trend'" class="civic-field civic-reporting-indicator-picker">
                    <span>Indicators (up to 6)</span>
                    <details>
                        <summary><span>{{ indicatorSelectionLabel }}</span><small>{{ selectedIndicators.length }}/6 selected</small></summary>
                        <div class="civic-reporting-indicator-options">
                            <label v-for="indicator in indicators" :key="indicator.code" :class="{ disabled: !analyticsForm.indicator_codes.includes(indicator.code) && analyticsForm.indicator_codes.length >= 6 }">
                                <input type="checkbox" :checked="analyticsForm.indicator_codes.includes(indicator.code)" :disabled="!analyticsForm.indicator_codes.includes(indicator.code) && analyticsForm.indicator_codes.length >= 6" @change="toggleIndicator(indicator.code)">
                                <span>{{ indicator.label }}</span>
                            </label>
                        </div>
                    </details>
                </div>
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
                    <button v-if="analytics && canExport" class="civic-button" type="button" :disabled="busy" @click="emit('download-report')">Download report</button>
                </div>
            </form>
        </article>

        <div v-if="busy" class="civic-reporting-empty">
            <strong>Updating {{ analyticsForm.report_style === 'template_table' ? 'template table' : 'charts and trends' }}.</strong>
            <span>Applying the current filters to the selected report style.</span>
        </div>

        <template v-else-if="analytics && analyticsForm.report_style === 'template_table'">
            <div class="civic-reporting-analytics-title">
                <span>Template report</span>
                <h2>{{ analytics.template.name }}</h2>
            </div>

            <div v-if="!analytics.periods.length" class="civic-reporting-empty">
                <strong>No reporting months are available for the selected filters.</strong>
                <span>Select a date range that includes one or more months.</span>
            </div>

            <article v-for="section in analytics.sections" :key="section.id" class="civic-workspace civic-reporting-panel">
                <div class="civic-workspace-header">
                    <div><div class="civic-eyebrow">Template section</div><h2>{{ section.title }}</h2></div>
                    <small v-if="section.description">{{ section.description }}</small>
                </div>
                <div class="civic-reporting-table-scroll">
                    <table class="civic-table civic-template-report-table">
                        <thead><tr><th>Indicator</th><th>Dimension / detail</th><th v-for="period in analytics.periods" :key="period.key">{{ period.label }}</th></tr></thead>
                        <tbody>
                            <template v-for="indicator in section.indicators" :key="indicator.id">
                                <tr v-for="(row, rowIndex) in indicator.rows" :key="`${indicator.id}-${row.dimension_key ?? 'value'}-${row.dimension_value ?? 'value'}`">
                                    <td v-if="rowIndex === 0" :rowspan="indicator.rows.length"><strong>{{ indicator.label }}</strong><small v-if="indicator.unit">{{ indicator.unit }}</small></td>
                                    <td>{{ row.dimension_label ?? 'Total value' }}</td>
                                    <td v-for="period in analytics.periods" :key="period.key">{{ formatTableValue(row.values[period.key]) }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>
        </template>

        <template v-else-if="analytics">
            <section v-for="indicatorAnalytics in analyticsSets" :key="indicatorAnalytics.indicator.code" class="civic-reporting-stack">
                <div class="civic-reporting-analytics-title">
                    <span>Indicator analysis</span>
                    <h2>{{ indicatorAnalytics.indicator.label }}</h2>
                </div>

                <div class="civic-reporting-stat-grid civic-reporting-stat-grid-compact">
                    <article class="civic-reporting-stat-card">
                        <span>Grand Total</span>
                        <strong>{{ formatNumber(indicatorAnalytics.totals.grand_total) }}</strong>
                        <small>{{ indicatorAnalytics.indicator.label }}</small>
                    </article>
                    <article v-for="row in indicatorAnalytics.by_year" :key="row.year" class="civic-reporting-stat-card">
                        <span>{{ row.year }} Total</span>
                        <strong>{{ formatNumber(row.value) }}</strong>
                        <small>{{ indicatorAnalytics.indicator.label }}</small>
                    </article>
                </div>

                <div v-if="Number(indicatorAnalytics.totals.grand_total) === 0" class="civic-reporting-empty">
                    <strong>No approved data found for the selected filters.</strong>
                    <span>Try a wider period range, another station, or a different indicator.</span>
                </div>

                <template v-else>
                    <section class="civic-reporting-chart-grid" :aria-label="`${indicatorAnalytics.indicator.label} charts`">
                        <article class="civic-workspace civic-reporting-panel civic-reporting-chart-panel">
                            <div class="civic-reporting-chart-heading"><div><span>Trend over time</span><h2>Monthly reporting trend</h2></div><small>{{ indicatorAnalytics.indicator.label }}</small></div>
                            <VerticalBarChart :rows="indicatorAnalytics.series.map((row) => ({ label: row.period, total: Number(row.value) }))" :limit="12" />
                        </article>
                        <article class="civic-workspace civic-reporting-panel civic-reporting-chart-panel">
                            <div class="civic-reporting-chart-heading"><div><span>Comparison</span><h2>Facility performance</h2></div><small>Top facilities by total</small></div>
                            <HorizontalBarChart :rows="indicatorAnalytics.facility_comparison.map((row) => ({ label: row.station_name, total: Number(row.value) })).sort((left, right) => right.total - left.total)" :limit="8" />
                        </article>
                    </section>

                    <article class="civic-workspace civic-reporting-panel"><div class="civic-section-heading"><h2>Yearly breakdown</h2></div><DataTable :columns="analyticsColumns.yearly" :rows="indicatorAnalytics.by_year" row-key="year"><template #value="{ row }">{{ formatNumber(row.value) }}</template></DataTable></article>
                    <article class="civic-workspace civic-reporting-panel"><div class="civic-section-heading"><h2>Monthly trend</h2></div><DataTable :columns="analyticsColumns.monthly" :rows="indicatorAnalytics.series" row-key="period"><template #value="{ row }">{{ formatNumber(row.value) }}</template></DataTable></article>
                    <article class="civic-workspace civic-reporting-panel"><div class="civic-section-heading"><h2>Facility comparison</h2></div><DataTable v-if="indicatorAnalytics.facility_comparison.length" :columns="analyticsColumns.facility" :rows="indicatorAnalytics.facility_comparison" row-key="station_id"><template #value="{ row }">{{ formatNumber(row.value) }}</template></DataTable><div v-else class="civic-reporting-empty"><strong>No facility comparison available.</strong><span>Facility-level data appears here when station submissions exist.</span></div></article>
                </template>
            </section>
        </template>

        <div v-else class="civic-reporting-empty">
            <strong>{{ analyticsForm.report_style === 'template_table' ? 'Template table is ready.' : 'Charts and trends are ready.' }}</strong>
            <span>Adjust the filters if needed, then select Run analytics.</span>
        </div>
    </section>
</template>
