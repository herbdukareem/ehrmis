<script setup>
import { computed, onMounted, ref } from 'vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import { api, apiMessage } from '../lib/api';

const years = ref([]); const year = ref(''); const period = ref('q1'); const overview = ref(null); const loading = ref(true); const loadingOverview = ref(false); const error = ref('');
const percent = (value) => value == null ? 'N/A' : `${(Number(value) * 100).toFixed(1)}%`;
const amount = (value) => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const rows = computed(() => overview.value?.mdas ?? []);

const loadYears = async () => {
  loading.value = true;
  try {
    const plans = (await api.get('/workplans')).data.data ?? [];
    years.value = [...new Set(plans.map((plan) => String(plan.year)))].sort((a, b) => Number(b) - Number(a));
    year.value = years.value[0] ?? String(new Date().getFullYear());
  } catch (e) { error.value = apiMessage(e); } finally { loading.value = false; }
};
const load = async () => {
  loadingOverview.value = true; error.value = '';
  try { overview.value = (await api.get('/workplan-performance/state', { params: { year: year.value, period: period.value } })).data.data; }
  catch (e) { error.value = apiMessage(e); overview.value = null; }
  finally { loadingOverview.value = false; }
};
onMounted(async () => { await loadYears(); await load(); });
</script>

<template>
  <PageHeading eyebrow="Performance management" title="State Performance Overview"><span class="civic-kicker">Verified reports only · alphabetical MDA list</span></PageHeading>
  <div v-if="error" class="civic-error">{{ error }}</div><LoadingBlock v-else-if="loading" />
  <template v-else>
    <section class="civic-workspace"><div class="civic-form-grid"><label class="civic-field"><span>Workplan year</span><select v-model="year"><option v-for="value in years" :key="value" :value="value">{{ value }}</option></select></label><label class="civic-field"><span>Reporting period</span><select v-model="period"><option value="q1">Q1</option><option value="q2">Q2</option><option value="q3">Q3</option><option value="q4">Q4</option><option value="annual">Annual</option></select></label><div class="civic-field"><span>&nbsp;</span><button class="civic-button civic-button-primary" :disabled="loadingOverview" @click="load">Apply</button></div></div><p>State figures are calculated on the server from each MDA’s governing Workplan revision. This list is alphabetical and does not rank MDAs.</p></section>
    <LoadingBlock v-if="loadingOverview" />
    <template v-else-if="overview"><section class="civic-workspace"><h3>State summary — {{ overview.period.toUpperCase() }} {{ overview.year }}</h3><div class="civic-form-grid"><p class="civic-field"><span>Verified achievement</span><strong>{{ percent(overview.state.official_score) }}</strong></p><p class="civic-field"><span>Reporting completeness</span><strong>{{ percent(overview.state.reporting_completeness.ratio) }}</strong><br><small>{{ overview.state.reporting_completeness.verified_reports }} verified / {{ overview.state.reporting_completeness.expected_reports }} expected · {{ overview.state.reporting_completeness.missing_or_unverified_reports }} missing</small></p><p class="civic-field"><span>Evidence coverage</span><strong>{{ percent(overview.state.evidence_coverage.ratio) }}</strong></p><p class="civic-field"><span>Planned cost</span><strong>{{ amount(overview.state.financial_execution.planned_cost) }}</strong></p><p class="civic-field"><span>Verified expenditure</span><strong>{{ amount(overview.state.financial_execution.reported_expenditure) }}</strong><br><small>Execution: {{ percent(overview.state.financial_execution.ratio) }}</small></p><p class="civic-field"><span>MDAs</span><strong>{{ overview.mda_count_with_verified_reporting }} / {{ overview.mda_count_expected_to_report }}</strong><br><small>with verified reporting / expected to report</small></p></div></section>
      <section class="civic-workspace"><h3>MDA overview</h3><div class="civic-table-wrap"><table class="civic-table"><thead><tr><th>MDA</th><th>Revision</th><th>Achievement</th><th>Completeness</th><th>Evidence</th><th>Financial execution</th><th>Missing</th></tr></thead><tbody><tr v-for="mda in rows" :key="mda.id"><td><RouterLink :to="{ path: '/workplan-performance', query: { workplan_id: mda.workplan_id, period: overview.period } }">{{ mda.name }}</RouterLink></td><td>Revision {{ mda.revision_no }}</td><td>{{ percent(mda.official_score) }}</td><td>{{ percent(mda.reporting_completeness.ratio) }}</td><td>{{ percent(mda.evidence_coverage.ratio) }}</td><td>{{ percent(mda.financial_execution.ratio) }}</td><td>{{ mda.reporting_completeness.missing_or_unverified_reports }}</td></tr><tr v-if="!rows.length"><td colspan="7">No MDA has an eligible Workplan structure for this year and period.</td></tr></tbody></table></div></section>
    </template>
  </template>
</template>
