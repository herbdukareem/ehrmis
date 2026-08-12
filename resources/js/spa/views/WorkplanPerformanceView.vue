<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();
const plans = ref([]); const selectedWorkplanId = ref(String(route.query.workplan_id ?? '')); const period = ref(['q1', 'q2', 'q3', 'q4', 'annual'].includes(route.query.period) ? route.query.period : 'q1'); const performance = ref(null);
const loading = ref(true); const loadingPerformance = ref(false); const error = ref('');
const selectedPlan = computed(() => plans.value.find((plan) => String(plan.id) === String(selectedWorkplanId.value)));
const percent = (value) => value == null ? 'N/A' : `${(Number(value) * 100).toFixed(1)}%`;
const amount = (value) => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const loadPlans = async () => {
  loading.value = true;
  try {
    plans.value = (await api.get('/workplans')).data.data ?? [];
    if (plans.value.length && !plans.value.some((plan) => String(plan.id) === String(selectedWorkplanId.value))) selectedWorkplanId.value = plans.value[0].id;
  } catch (e) { error.value = apiMessage(e); } finally { loading.value = false; }
};
const loadPerformance = async () => {
  if (!selectedWorkplanId.value) return;
  loadingPerformance.value = true; error.value = '';
  try { performance.value = (await api.get(`/workplans/${selectedWorkplanId.value}/performance`, { params: { period: period.value } })).data.data; }
  catch (e) { error.value = apiMessage(e); performance.value = null; }
  finally { loadingPerformance.value = false; }
};
const apply = async () => { await loadPerformance(); };
onMounted(async () => { await loadPlans(); await loadPerformance(); });
</script>

<template>
  <PageHeading eyebrow="Performance management" title="Performance views & drill-down">
    <span class="civic-kicker">Verified reports only</span>
  </PageHeading>
  <div v-if="error" class="civic-error">{{ error }}</div>
  <LoadingBlock v-else-if="loading" />
  <template v-else>
    <section class="civic-workspace">
      <div class="civic-form-grid">
        <label class="civic-field civic-field-wide"><span>Workplan year & revision</span><select v-model="selectedWorkplanId"><option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.year }} · Revision {{ plan.revision_no }} · {{ plan.title }} · {{ plan.status }}</option></select></label>
        <label class="civic-field"><span>Reporting period</span><select v-model="period"><option value="q1">Q1</option><option value="q2">Q2</option><option value="q3">Q3</option><option value="q4">Q4</option><option value="annual">Annual</option></select></label>
        <div class="civic-field"><span>&nbsp;</span><button class="civic-button civic-button-primary" :disabled="loadingPerformance || !selectedWorkplanId" @click="apply">Apply</button></div>
      </div>
      <p v-if="selectedPlan">{{ selectedPlan.mda?.name }} · {{ selectedPlan.year }} · Revision {{ selectedPlan.revision_no }} <StatusPill :status="selectedPlan.status" /></p>
      <p>Scores are calculated by the server from verified progress reports. Draft, submitted, returned, and missing expected reports do not contribute measured achievement.</p>
    </section>
    <LoadingBlock v-if="loadingPerformance" />
    <template v-else-if="performance">
      <section class="civic-workspace"><h3>MDA performance — {{ performance.period.toUpperCase() }}</h3>
        <div class="civic-form-grid"><p class="civic-field"><span>Official achievement</span><strong>{{ percent(performance.mda.official_score) }}</strong></p><p class="civic-field"><span>Raw achievement</span><strong>{{ percent(performance.mda.raw_achievement_ratio) }}</strong></p><p class="civic-field"><span>Reporting completeness</span><strong>{{ percent(performance.reporting_completeness.ratio) }}</strong><br><small>{{ performance.reporting_completeness.verified_reports }} verified / {{ performance.reporting_completeness.expected_reports }} expected · {{ performance.reporting_completeness.missing_or_unverified_reports }} missing or unverified</small></p><p class="civic-field"><span>Evidence coverage</span><strong>{{ percent(performance.evidence_coverage.ratio) }}</strong><br><small>{{ performance.evidence_coverage.verified_reports_with_evidence }} verified reports with evidence</small></p><p class="civic-field"><span>Planned cost</span><strong>{{ amount(performance.financial_execution.planned_cost) }}</strong></p><p class="civic-field"><span>Verified reported expenditure</span><strong>{{ amount(performance.financial_execution.reported_expenditure) }}</strong><br><small>Execution: {{ percent(performance.financial_execution.ratio) }}</small></p></div>
      </section>
      <section class="civic-workspace"><h3>Department drill-down</h3>
        <details v-for="department in performance.departments" :key="department.id ?? 'unassigned'" class="civic-detail">
          <summary><strong>{{ department.name }}</strong> · Achievement {{ percent(department.official_score) }} · Completeness {{ percent(department.reporting_completeness.ratio) }} · Evidence {{ percent(department.evidence_coverage.ratio) }} · Planned {{ amount(department.financial_execution.planned_cost) }} / Verified spend {{ amount(department.financial_execution.reported_expenditure) }}</summary>
          <details v-for="objective in department.objectives" :key="objective.id" class="civic-detail">
            <summary><strong>{{ objective.code }} · {{ objective.title }}</strong> · Achievement {{ percent(objective.official_score) }} · Completeness {{ percent(objective.reporting_completeness.ratio) }} · Evidence {{ percent(objective.evidence_coverage.ratio) }} · Planned {{ amount(objective.financial_execution.planned_cost) }} / Verified spend {{ amount(objective.financial_execution.reported_expenditure) }}</summary>
            <details v-for="activity in objective.activities" :key="activity.id" class="civic-detail">
              <summary><strong>{{ activity.code }} · {{ activity.title }}</strong> · Achievement {{ percent(activity.official_score) }} · Report: {{ activity.report_status || 'No report' }} · {{ activity.verified ? 'Verified' : 'Unverified / missing' }} · Evidence {{ percent(activity.evidence_coverage.ratio) }} · Planned {{ amount(activity.financial_execution.planned_cost) }} / Verified spend {{ amount(activity.financial_execution.reported_expenditure) }}</summary>
              <div v-for="indicator in activity.indicators" :key="indicator.id" class="civic-form-grid"><p class="civic-field-wide"><strong>{{ indicator.code }}</strong><br>Target: {{ indicator.target_value ?? 'N/A' }} · Actual: {{ indicator.actual_value ?? 'N/A' }} · Official achievement: {{ percent(indicator.official_score) }} · Raw: {{ percent(indicator.raw_achievement_ratio) }} · Weight: {{ indicator.weight }}</p></div>
            </details>
          </details>
        </details>
        <p v-if="!performance.departments.length">There are no eligible indicators with targets for this Workplan revision and reporting period.</p>
      </section>
    </template>
  </template>
</template>
