<script setup>
import { computed, onMounted, ref } from 'vue';
import DonutChart from '../components/DonutChart.vue';
import HorizontalBarChart from '../components/HorizontalBarChart.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import { api } from '../lib/api';

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
const retirementMaximum = computed(() => Math.max(
    ...(data.value?.retirement_trends.history ?? []).map((row) => row.total),
    ...(data.value?.retirement_trends.projection ?? []).map((row) => row.total),
    1,
));

onMounted(async () => { data.value = (await api.get('/dashboard')).data.data; });
</script>

<template>
    <PageHeading eyebrow="Workforce intelligence" title="Executive establishment overview" description="Authoritative workforce composition, allowance exposure, and retirement outlook for the visible establishment." />
    <LoadingBlock v-if="!data" />
    <template v-else>
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
                    <HorizontalBarChart :rows="selectedCadre?.allowances ?? []" />
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
