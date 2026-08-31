<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();

const plans = ref([]);
const selectedWorkplanId = ref(String(route.query.workplan_id ?? ''));
const period = ref(['q1', 'q2', 'q3', 'q4', 'annual'].includes(route.query.period) ? route.query.period : 'q1');
const performance = ref(null);
const loading = ref(true);
const loadingPerformance = ref(false);
const error = ref('');

const selectedPlan = computed(() => plans.value.find((plan) => String(plan.id) === String(selectedWorkplanId.value)));
const eligibleDepartments = computed(() => performance.value?.departments ?? []);

const percent = (value) => value == null ? 'N/A' : `${(Number(value) * 100).toFixed(1)}%`;
const amount = (value) => Number(value ?? 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const loadPlans = async () => {
    loading.value = true;

    try {
        plans.value = (await api.get('/workplans')).data.data ?? [];

        if (plans.value.length && !plans.value.some((plan) => String(plan.id) === String(selectedWorkplanId.value))) {
            selectedWorkplanId.value = plans.value[0].id;
        }
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        loading.value = false;
    }
};

const loadPerformance = async () => {
    if (!selectedWorkplanId.value) {
        return;
    }

    loadingPerformance.value = true;
    error.value = '';

    try {
        performance.value = (await api.get(`/workplans/${selectedWorkplanId.value}/performance`, {
            params: { period: period.value },
        })).data.data;
    } catch (e) {
        error.value = apiMessage(e);
        performance.value = null;
    } finally {
        loadingPerformance.value = false;
    }
};

const apply = async () => {
    await loadPerformance();
};

onMounted(async () => {
    await loadPlans();
    await loadPerformance();
});
</script>

<template>
    <PageHeading
        eyebrow="Performance management"
        title="Performance views and drill-down"
        description="Server-calculated performance across MDA, department, objective, activity, and indicator levels using verified progress reports only."
    >
        <span class="civic-inline-note">Verified reports only</span>
    </PageHeading>

    <div v-if="error" class="civic-error">{{ error }}</div>
    <LoadingBlock v-else-if="loading" />

    <div v-else class="civic-page-stack">
        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Performance filter</span>
                    <h2>Workplan selection</h2>
                    <p>Select the governing workplan revision and reporting period to review official achievement, completeness, evidence coverage, and expenditure.</p>
                </div>
            </div>

            <div class="civic-workspace-body civic-split-toolbar">
                <form class="civic-form-grid" @submit.prevent="apply">
                    <label class="civic-field civic-field-wide">
                        <span>Workplan year and revision</span>
                        <select v-model="selectedWorkplanId">
                            <option v-for="plan in plans" :key="plan.id" :value="plan.id">
                                {{ plan.year }} - Revision {{ plan.revision_no }} - {{ plan.title }} - {{ plan.status }}
                            </option>
                        </select>
                    </label>

                    <label class="civic-field">
                        <span>Reporting period</span>
                        <select v-model="period">
                            <option value="q1">Q1</option>
                            <option value="q2">Q2</option>
                            <option value="q3">Q3</option>
                            <option value="q4">Q4</option>
                            <option value="annual">Annual</option>
                        </select>
                    </label>

                    <div class="civic-field civic-form-action">
                        <span>Apply filters</span>
                        <button class="civic-button civic-button-primary" :disabled="loadingPerformance || !selectedWorkplanId">Apply</button>
                    </div>
                </form>

                <div class="civic-aside-note">
                    Scores are calculated from verified progress reports only. Draft, submitted, returned, and missing expected reports do not contribute measured achievement.
                </div>
            </div>

            <div v-if="selectedPlan" class="civic-workspace-body civic-workspace-body-tight">
                <div class="civic-chip-row">
                    <span class="civic-soft-chip">{{ selectedPlan.mda?.name }}</span>
                    <span class="civic-soft-chip">Year {{ selectedPlan.year }}</span>
                    <span class="civic-soft-chip">Revision {{ selectedPlan.revision_no }}</span>
                    <StatusPill :status="selectedPlan.status" />
                </div>
            </div>
        </section>

        <LoadingBlock v-if="loadingPerformance" />

        <template v-else-if="performance">
            <section class="civic-workspace">
                <div class="civic-section-heading">
                    <div>
                        <span class="civic-kicker">Official performance</span>
                        <h2>MDA performance - {{ performance.period.toUpperCase() }}</h2>
                        <p>Official score is the verified weighted outcome. Raw achievement, completeness, evidence coverage, and expenditure remain visible alongside it.</p>
                    </div>
                </div>

                <div class="civic-workspace-body">
                    <div class="civic-summary-grid">
                        <article class="civic-summary-card">
                            <span>Official achievement</span>
                            <strong>{{ percent(performance.mda.official_score) }}</strong>
                            <small>Verified weighted score for the selected period.</small>
                        </article>

                        <article class="civic-summary-card">
                            <span>Raw achievement</span>
                            <strong>{{ percent(performance.mda.raw_achievement_ratio) }}</strong>
                            <small>Unnormalized ratio before official score adjustments.</small>
                        </article>

                        <article class="civic-summary-card">
                            <span>Reporting completeness</span>
                            <strong>{{ percent(performance.reporting_completeness.ratio) }}</strong>
                            <small>
                                {{ performance.reporting_completeness.verified_reports }} verified /
                                {{ performance.reporting_completeness.expected_reports }} expected /
                                {{ performance.reporting_completeness.missing_or_unverified_reports }} missing or unverified
                            </small>
                        </article>

                        <article class="civic-summary-card">
                            <span>Evidence coverage</span>
                            <strong>{{ percent(performance.evidence_coverage.ratio) }}</strong>
                            <small>{{ performance.evidence_coverage.verified_reports_with_evidence }} verified reports with evidence.</small>
                        </article>

                        <article class="civic-summary-card">
                            <span>Planned cost</span>
                            <strong>{{ amount(performance.financial_execution.planned_cost) }}</strong>
                            <small>Total planned operational cost for eligible activities.</small>
                        </article>

                        <article class="civic-summary-card">
                            <span>Verified expenditure</span>
                            <strong>{{ amount(performance.financial_execution.reported_expenditure) }}</strong>
                            <small>Execution: {{ percent(performance.financial_execution.ratio) }}</small>
                        </article>
                    </div>
                </div>
            </section>

            <section class="civic-workspace">
                <div class="civic-section-heading">
                    <div>
                        <span class="civic-kicker">Drill-down tree</span>
                        <h2>Department, objective, and activity performance</h2>
                        <p>Expand the hierarchy to inspect which organizational layer is driving performance and where indicator-level gaps remain.</p>
                    </div>
                    <span class="civic-inline-note">{{ eligibleDepartments.length }} departments in scope</span>
                </div>

                <div class="civic-workspace-body">
                    <template v-if="eligibleDepartments.length">
                        <details v-for="department in eligibleDepartments" :key="department.id ?? 'unassigned'" class="civic-detail">
                            <summary>
                                <strong>{{ department.name }}</strong>
                                Achievement {{ percent(department.official_score) }} /
                                Completeness {{ percent(department.reporting_completeness.ratio) }} /
                                Evidence {{ percent(department.evidence_coverage.ratio) }} /
                                Planned {{ amount(department.financial_execution.planned_cost) }} /
                                Verified spend {{ amount(department.financial_execution.reported_expenditure) }}
                            </summary>

                            <details v-for="objective in department.objectives" :key="objective.id" class="civic-detail">
                                <summary>
                                    <strong>{{ objective.code }} - {{ objective.title }}</strong>
                                    Achievement {{ percent(objective.official_score) }} /
                                    Completeness {{ percent(objective.reporting_completeness.ratio) }} /
                                    Evidence {{ percent(objective.evidence_coverage.ratio) }} /
                                    Planned {{ amount(objective.financial_execution.planned_cost) }} /
                                    Verified spend {{ amount(objective.financial_execution.reported_expenditure) }}
                                </summary>

                                <details v-for="activity in objective.activities" :key="activity.id" class="civic-detail">
                                    <summary>
                                        <strong>{{ activity.code }} - {{ activity.title }}</strong>
                                        Achievement {{ percent(activity.official_score) }} /
                                        Report {{ activity.report_status || 'No report' }} /
                                        {{ activity.verified ? 'Verified' : 'Unverified or missing' }} /
                                        Evidence {{ percent(activity.evidence_coverage.ratio) }} /
                                        Planned {{ amount(activity.financial_execution.planned_cost) }} /
                                        Verified spend {{ amount(activity.financial_execution.reported_expenditure) }}
                                    </summary>

                                    <div class="civic-list-stack">
                                        <article v-for="indicator in activity.indicators" :key="indicator.id" class="civic-list-card">
                                            <div class="civic-list-card-header">
                                                <div>
                                                    <h3 class="civic-list-card-title">{{ indicator.code }}</h3>
                                                    <p class="civic-list-card-meta">
                                                        Target {{ indicator.target_value ?? 'N/A' }} /
                                                        Actual {{ indicator.actual_value ?? 'N/A' }} /
                                                        Official {{ percent(indicator.official_score) }} /
                                                        Raw {{ percent(indicator.raw_achievement_ratio) }} /
                                                        Weight {{ indicator.weight }}
                                                    </p>
                                                </div>
                                            </div>
                                        </article>
                                    </div>
                                </details>
                            </details>
                        </details>
                    </template>

                    <p v-else class="civic-performance-empty">There are no eligible indicators with targets for this workplan revision and reporting period.</p>
                </div>
            </section>
        </template>
    </div>
</template>
