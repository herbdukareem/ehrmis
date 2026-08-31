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
        data.value = (await api.get('/workplan-performance/state/rankings', {
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
    <PageHeading
        eyebrow="Performance management"
        title="State Performance Ranking"
        description="Comparable statewide ranking of MDAs by official achievement, constrained by the approved minimum reporting-completeness threshold."
    >
        <span class="civic-inline-note">Ranking uses verified achievement only</span>
    </PageHeading>

    <div v-if="error" class="civic-error">{{ error }}</div>

    <div class="civic-page-stack">
        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Ranking controls</span>
                    <h2>Filter the ranking view</h2>
                    <p>Eligibility depends on the approved completeness threshold. Completeness, evidence, and expenditure remain visible but do not change achievement rank.</p>
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

                <div v-if="data" class="civic-aside-note">
                    Rank eligibility requires {{ percent(data.minimum_reporting_completeness) }} reporting completeness. MDAs below threshold remain visible but are not assigned a numeric rank.
                </div>
            </div>
        </section>

        <LoadingBlock v-if="loading" />

        <section v-else-if="data" class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Achievement ranking</span>
                    <h2>{{ data.period.toUpperCase() }} {{ data.year }}</h2>
                    <p>Ranked MDAs meet the completeness threshold. Every other row remains visible for transparency.</p>
                </div>
                <span class="civic-inline-note">{{ data.mdas.length }} MDAs evaluated</span>
            </div>

            <div class="civic-performance-table-wrap">
                <div class="civic-table-wrap">
                    <table class="civic-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>MDA</th>
                                <th>Achievement</th>
                                <th>Completeness</th>
                                <th>Evidence</th>
                                <th>Financial execution</th>
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
                                    <small>Revision {{ mda.revision_no }}</small>
                                </td>
                                <td>{{ percent(mda.official_score) }}</td>
                                <td>{{ percent(mda.reporting_completeness.ratio) }}</td>
                                <td>{{ percent(mda.evidence_coverage.ratio) }}</td>
                                <td>{{ percent(mda.financial_execution.ratio) }}</td>
                                <td>{{ mda.rank_status.replaceAll('_', ' ') }}</td>
                            </tr>
                            <tr v-if="!data.mdas.length">
                                <td colspan="7" class="civic-empty-cell">No eligible workplan data for this period.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</template>
