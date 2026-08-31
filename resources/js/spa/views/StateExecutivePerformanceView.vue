<script setup>
import { onMounted, ref } from 'vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import { api, apiMessage } from '../lib/api';

const year = ref(String(new Date().getFullYear()));
const period = ref('q1');
const data = ref(null);
const loading = ref(false);
const error = ref('');

const percent = (value) => value == null ? 'N/A' : `${(Number(value) * 100).toFixed(1)}%`;

const load = async () => {
    loading.value = true;
    error.value = '';

    try {
        data.value = (await api.get('/workplan-performance/state/executive', {
            params: { year: year.value, period: period.value },
        })).data.data;
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        loading.value = false;
    }
};

onMounted(load);
</script>

<template>
    <div class="executive-print">
        <header class="executive-print-header">
            <strong>HMB-eHRMIS</strong>
            <span>State Executive Performance Report</span>
            <span v-if="data">{{ data.year }} - {{ data.period.toUpperCase() }} - Generated {{ data.generated_at }}</span>
        </header>

        <PageHeading
            eyebrow="Performance management"
            title="State Executive Performance"
            description="A print-friendly statewide executive view of verified performance, ranking classification, and independent period observations."
        >
            <button class="civic-button civic-button-primary no-print" :disabled="loading" @click="window.print()">Print executive view</button>
        </PageHeading>

        <div v-if="error" class="civic-error no-print">{{ error }}</div>

        <div class="civic-page-stack">
            <section class="civic-workspace no-print">
                <div class="civic-section-heading">
                    <div>
                        <span class="civic-kicker">Executive filter</span>
                        <h2>Review the statewide briefing period</h2>
                        <p>Use the filter controls below to generate the executive briefing for a specific year and reporting period before printing.</p>
                    </div>
                </div>

                <div class="civic-workspace-body civic-split-toolbar">
                    <form class="civic-form-grid" @submit.prevent="load">
                        <label class="civic-field">
                            <span>Year</span>
                            <input v-model="year" type="number">
                        </label>

                        <label class="civic-field">
                            <span>Period</span>
                            <select v-model="period">
                                <option v-for="value in ['q1', 'q2', 'q3', 'q4', 'annual']" :key="value" :value="value">{{ value.toUpperCase() }}</option>
                            </select>
                        </label>

                        <div class="civic-field civic-form-action">
                            <span>Apply filters</span>
                            <button class="civic-button civic-button-primary" :disabled="loading">Apply</button>
                        </div>
                    </form>

                    <div class="civic-aside-note">
                        This executive view remains descriptive. Evidence coverage and financial execution are shown alongside achievement but do not alter the official score or ranking eligibility.
                    </div>
                </div>
            </section>

            <LoadingBlock v-if="loading" />

            <template v-else-if="data">
                <section class="civic-workspace">
                    <div class="civic-section-heading">
                        <div>
                            <span class="civic-kicker">Executive summary</span>
                            <h2>{{ data.period.toUpperCase() }} {{ data.year }}</h2>
                            <p>Snapshot of statewide official achievement, completeness, evidence, expenditure, and classification counts for the selected period.</p>
                        </div>
                        <span class="civic-inline-note">Print-safe briefing</span>
                    </div>

                    <div class="civic-workspace-body">
                        <div class="civic-summary-grid">
                            <article class="civic-summary-card">
                                <span>Official achievement</span>
                                <strong>{{ percent(data.state.official_score) }}</strong>
                                <small>Verified statewide official score.</small>
                            </article>

                            <article class="civic-summary-card">
                                <span>Reporting completeness</span>
                                <strong>{{ percent(data.state.reporting_completeness.ratio) }}</strong>
                                <small>{{ data.state.reporting_completeness.expected_reports }} expected / {{ data.state.reporting_completeness.verified_reports }} verified / {{ data.state.reporting_completeness.missing_or_unverified_reports }} missing</small>
                            </article>

                            <article class="civic-summary-card">
                                <span>Evidence coverage</span>
                                <strong>{{ percent(data.state.evidence_coverage.ratio) }}</strong>
                                <small>Verified reports supported by evidence.</small>
                            </article>

                            <article class="civic-summary-card">
                                <span>Financial execution</span>
                                <strong>{{ percent(data.state.financial_execution.ratio) }}</strong>
                                <small>Verified expenditure against eligible planned cost.</small>
                            </article>

                            <article class="civic-summary-card">
                                <span>Report volume</span>
                                <strong>{{ data.state.reporting_completeness.expected_reports }} / {{ data.state.reporting_completeness.verified_reports }} / {{ data.state.reporting_completeness.missing_or_unverified_reports }}</strong>
                                <small>Expected, verified, and missing or unverified reports.</small>
                            </article>

                            <article class="civic-summary-card">
                                <span>MDA classifications</span>
                                <strong>{{ data.classifications.ranked_count }} / {{ data.classifications.insufficient_reporting_count }} / {{ data.classifications.no_eligible_plan_data_count }}</strong>
                                <small>Ranked, insufficient reporting, and no eligible plan data.</small>
                            </article>
                        </div>
                    </div>
                </section>

                <section class="civic-workspace">
                    <div class="civic-section-heading">
                        <div>
                            <span class="civic-kicker">State ranking sheet</span>
                            <h2>MDA performance</h2>
                            <p>Ranked MDAs meet the approved completeness threshold. Every row remains visible for executive review.</p>
                        </div>
                    </div>

                    <div class="civic-performance-table-wrap">
                        <div class="civic-table-wrap">
                            <table class="civic-table">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>MDA</th>
                                        <th>Revision</th>
                                        <th>Achievement</th>
                                        <th>Completeness</th>
                                        <th>Evidence</th>
                                        <th>Financial execution</th>
                                        <th>Missing reports</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="mda in data.mdas" :key="mda.id">
                                        <td>{{ mda.rank ?? 'N/A' }}</td>
                                        <td>
                                            <RouterLink :to="{ path: '/workplan-performance', query: { workplan_id: mda.workplan_id, period: data.period } }">
                                                {{ mda.name }}
                                            </RouterLink>
                                        </td>
                                        <td>Revision {{ mda.revision_no }}</td>
                                        <td>{{ percent(mda.official_score) }}</td>
                                        <td>{{ percent(mda.reporting_completeness.ratio) }}</td>
                                        <td>{{ percent(mda.evidence_coverage.ratio) }}</td>
                                        <td>{{ percent(mda.financial_execution.ratio) }}</td>
                                        <td>{{ mda.reporting_completeness.missing_or_unverified_reports }}</td>
                                        <td>{{ mda.rank_status.replaceAll('_', ' ') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="civic-workspace">
                    <div class="civic-section-heading">
                        <div>
                            <span class="civic-kicker">Independent period observations</span>
                            <h2>Executive trend view</h2>
                            <p>Each period remains independent. This matrix helps executives compare the official score observed for the same MDA across separate reporting windows.</p>
                        </div>
                    </div>

                    <div class="civic-performance-table-wrap">
                        <div class="civic-table-wrap">
                            <table class="civic-table">
                                <thead>
                                    <tr>
                                        <th>MDA</th>
                                        <th v-for="item in data.trends.periods" :key="item">{{ item.toUpperCase() }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="mda in data.trends.mdas" :key="mda.id">
                                        <td>{{ mda.name }}</td>
                                        <td v-for="item in data.trends.periods" :key="item">
                                            <RouterLink
                                                v-if="mda.observations[item]?.workplan_id"
                                                :to="{ path: '/workplan-performance', query: { workplan_id: mda.observations[item].workplan_id, period: item } }"
                                            >
                                                {{ percent(mda.observations[item].official_score) }}
                                            </RouterLink>
                                            <template v-else>N/A</template>
                                            <small v-if="mda.observations[item]">Rev {{ mda.observations[item].revision_no ?? 'N/A' }}</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="civic-workspace executive-methodology">
                    <div class="civic-section-heading">
                        <div>
                            <span class="civic-kicker">Methodology</span>
                            <h2>Interpretation notes</h2>
                            <p>Use these notes when presenting the view or exporting it to print.</p>
                        </div>
                    </div>

                    <div class="civic-workspace-body">
                        <div class="civic-copy-block">
                            <p>Official achievement is calculated from verified progress reports against the approved workplan structure. Missing or unverified expected reports contribute zero achievement.</p>
                            <p>Reporting completeness is presented separately from achievement. MDAs below the approved reporting-completeness threshold remain visible but are not assigned a numerical rank.</p>
                            <p>Evidence coverage and financial execution are informational metrics and do not contribute to the achievement score or rank.</p>
                            <p class="print-only">Print-friendly executive view only. This is not a certified report or formal export.</p>
                        </div>
                    </div>
                </section>
            </template>
        </div>
    </div>
</template>
