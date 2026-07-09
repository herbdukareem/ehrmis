<script setup>
import { computed, onMounted, ref } from 'vue';
import DonutChart from '../components/DonutChart.vue';
import HorizontalBarChart from '../components/HorizontalBarChart.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import VerticalBarChart from '../components/VerticalBarChart.vue';
import { api } from '../lib/api';
import { can } from '../stores/auth';
import { setPageError } from '../stores/app';

const data = ref(null);
const activeView = ref('organization');
const selectedCadreIndex = ref(0);
const views = [
    { id: 'organization', label: 'Departments' },
    { id: 'salary', label: 'Salary & gender' },
    { id: 'cadres', label: 'Cadres & allowances' },
    { id: 'retirement', label: 'Retirement trends' },
];
const selectedCadre = computed(() => data.value?.distributions.cadres[selectedCadreIndex.value] ?? null);
const isStateOverview = computed(() => data.value?.scope?.mode && data.value.scope.mode !== 'mda');
const mdaChartRows = computed(() => [...(data.value?.mda_overview ?? [])]
    .sort((a, b) => b.staff_count - a.staff_count));
const mdaWorkforceMaximum = computed(() => Math.max(
    ...mdaChartRows.value.flatMap((row) => [row.active_staff, row.retiring_this_year]),
    1,
));
const retirementMaximum = computed(() => Math.max(
    ...(data.value?.retirement_trends.history ?? []).map((row) => row.total),
    ...(data.value?.retirement_trends.projection ?? []).map((row) => row.total),
    1,
));

onMounted(async () => {
    if (!can('view-reports')) {
        setPageError('This action is unauthorized.');
        return;
    }

    data.value = (await api.get('/dashboard')).data.data;
});
</script>

<template>
    <PageHeading eyebrow="Workforce intelligence" title="Executive establishment overview" description="Authoritative workforce composition, allowance exposure, and retirement outlook for the visible establishment." />
    <LoadingBlock v-if="!data" />
    <template v-else>
        <section v-if="isStateOverview" class="civic-metric-band civic-metric-band-wide">
            <div><span>Visible MDAs</span><strong>{{ data.scope.mda_count.toLocaleString() }}</strong></div>
            <div><span>No staff yet</span><strong>{{ data.state_attention.mdas_with_no_staff.toLocaleString() }}</strong></div>
            <div><span>Workflow pressure</span><strong>{{ data.state_attention.workflow_pressure.toLocaleString() }}</strong></div>
            <div><span>Data issues</span><strong>{{ data.state_attention.data_issues.toLocaleString() }}</strong></div>
        </section>

        <section v-if="isStateOverview" class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <h2>MDAs overview</h2>
                </div>
                <small>Active and retiring staff</small>
            </div>
            <div class="civic-mda-column-chart">
                <div class="civic-mda-column-legend">
                    <span><i data-kind="active"></i> Active</span>
                    <span><i data-kind="retiring"></i> Retiring</span>
                </div>
                <div class="civic-mda-column-grid">
                    <div v-for="row in mdaChartRows" :key="row.mda.id" class="civic-mda-column">
                        <div class="civic-mda-column-bars">
                            <div class="civic-mda-column-bar">
                                <strong>{{ row.active_staff.toLocaleString() }}</strong>
                                <span data-kind="active" :style="{ height: `${Math.max((row.active_staff / mdaWorkforceMaximum) * 100, 3)}%` }"></span>
                            </div>
                            <div class="civic-mda-column-bar">
                                <strong>{{ row.retiring_this_year.toLocaleString() }}</strong>
                                <span data-kind="retiring" :style="{ height: `${Math.max((row.retiring_this_year / mdaWorkforceMaximum) * 100, 3)}%` }"></span>
                            </div>
                        </div>
                        <small>{{ row.mda.code }}</small>
                    </div>
                </div>
            </div>
        </section>

        <section class="civic-metric-band civic-metric-band-wide">
            <div><span>Total staff</span><strong>{{ data.counts.staff.toLocaleString() }}</strong></div>
            <div><span>Active</span><strong>{{ data.counts.active_staff.toLocaleString() }}</strong></div>
            <div><span>Retired</span><strong>{{ data.counts.retired_staff.toLocaleString() }}</strong></div>
            <div><span>Other status</span><strong>{{ data.counts.other_staff.toLocaleString() }}</strong></div>
        </section>

        <section class="civic-retirement-strip">
            <div class="civic-retirement-lead"><span>Retirement watch</span><strong>Upcoming exits</strong></div>
            <div><span>This month</span><strong>{{ data.retirement_windows.this_month }}</strong></div>
            <div><span>Next month</span><strong>{{ data.retirement_windows.next_month }}</strong></div>
            <div><span>This year</span><strong>{{ data.retirement_windows.this_year }}</strong></div>
            <div><span>Next year</span><strong>{{ data.retirement_windows.next_year }}</strong></div>
        </section>

        <section class="civic-workspace civic-intelligence">
            <nav class="civic-analytics-nav" aria-label="Dashboard analysis views">
                <button v-for="view in views" :key="view.id" :class="{ active: activeView === view.id }" @click="activeView = view.id">{{ view.label }}</button>
            </nav>

            <div v-if="activeView === 'organization'" class="civic-analytics-panel">
                <div class="civic-analysis-heading"><div><div class="civic-eyebrow">Organization</div><h2>Staff distribution by department</h2></div><span>Top {{ Math.min(data.distributions.departments.length, 12) }} departments shown</span></div>
                <HorizontalBarChart :rows="data.distributions.departments" />
            </div>

            <div v-if="activeView === 'salary'" class="civic-analytics-panel civic-split-analysis">
                <div>
                    <div class="civic-analysis-heading"><div><div class="civic-eyebrow">Establishment cost structure</div><h2>Salary scale distribution</h2></div></div>
                    <HorizontalBarChart :rows="data.distributions.salary_scales" />
                </div>
                <div>
                    <div class="civic-analysis-heading"><div><div class="civic-eyebrow">Workforce composition</div><h2>Gender distribution</h2></div></div>
                    <DonutChart :rows="data.distributions.gender" />
                </div>
            </div>

            <div v-if="activeView === 'cadres'" class="civic-analytics-panel civic-cadre-analysis">
                <div class="civic-cadre-list">
                    <div class="civic-analysis-heading"><div><div class="civic-eyebrow">Cadre establishment</div><h2>Staff by cadre</h2></div></div>
                    <button v-for="(cadre, index) in data.distributions.cadres" :key="cadre.id ?? cadre.name" :class="{ active: selectedCadreIndex === index }" @click="selectedCadreIndex = index">
                        <span>{{ cadre.name }}</span><strong>{{ cadre.staff_count.toLocaleString() }}</strong>
                    </button>
                </div>
                <div class="civic-allowance-view">
                    <div class="civic-analysis-heading">
                        <div><div class="civic-eyebrow">Allowance eligibility</div><h2>{{ selectedCadre?.name }}</h2></div>
                        <span>{{ selectedCadre?.staff_count.toLocaleString() }} staff in cadre</span>
                    </div>
                    <p class="civic-muted">Counts show eligible staff within the selected cadre, not monetary values.</p>
                    <VerticalBarChart :rows="selectedCadre?.allowances ?? []" />
                </div>
            </div>

            <div v-if="activeView === 'retirement'" class="civic-analytics-panel">
                <div class="civic-analysis-heading">
                    <div>
                        <div class="civic-eyebrow">
                            Ten-year retirement picture
                        </div>
                        <h2>History and five-year projection</h2>
                    </div>
                    
                    </div>
                <div class="civic-timeline-chart">
                    <div v-for="row in data.retirement_trends.history" :key="`h-${row.label}`" class="civic-timeline-column history">
                        <div class="civic-timeline-value">{{ row.total }}</div><div class="civic-timeline-track"><span :style="{ height: `${Math.max((row.total / retirementMaximum) * 100, 3)}%` }"></span></div><strong>{{ row.label }}</strong><small>Actual</small>
                    </div>
                    <div v-for="row in data.retirement_trends.projection" :key="`p-${row.label}`" class="civic-timeline-column projection">
                        <div class="civic-timeline-value">{{ row.total }}</div><div class="civic-timeline-track"><span :style="{ height: `${Math.max((row.total / retirementMaximum) * 100, 3)}%` }"></span></div><strong>{{ row.label }}</strong><small>Projected</small>
                    </div>
                </div>
            </div>
        </section>
    </template>
</template>
