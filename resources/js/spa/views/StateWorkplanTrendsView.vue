<script setup>
import { onMounted, ref } from 'vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import { api, apiMessage } from '../lib/api';

const year = ref(String(new Date().getFullYear()));
const data = ref(null);
const loading = ref(false);
const error = ref('');

const percent = (value) => value == null ? 'N/A' : `${(Number(value) * 100).toFixed(1)}%`;

const load = async () => {
    loading.value = true;
    error.value = '';

    try {
        data.value = (await api.get('/workplan-performance/state/trends', {
            params: { year: year.value },
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
        title="State Performance Trends"
        description="Independent period-by-period statewide observations across MDAs without deriving trend scores, momentum ratings, or period averages."
    >
        <span class="civic-inline-note">Periods remain independent</span>
    </PageHeading>

    <div v-if="error" class="civic-error">{{ error }}</div>

    <div class="civic-page-stack">
        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Trend controls</span>
                    <h2>Review a reporting year</h2>
                    <p>Q1, Q2, Q3, Q4, and Annual remain independent observations. This view helps compare each period without collapsing them into a synthetic trend score.</p>
                </div>
            </div>

            <div class="civic-workspace-body civic-split-toolbar">
                <form class="civic-form-grid" @submit.prevent="load">
                    <label class="civic-field">
                        <span>Year</span>
                        <input v-model="year" type="number">
                    </label>

                    <div class="civic-field civic-form-action">
                        <span>Apply filters</span>
                        <button class="civic-button civic-button-primary" :disabled="loading">Apply</button>
                    </div>
                </form>

                <div class="civic-aside-note">
                    A missing cell means there was no governing workplan observation for that MDA and period, not that the score was zero.
                </div>
            </div>
        </section>

        <LoadingBlock v-if="loading" />

        <section v-else-if="data" class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Independent observations</span>
                    <h2>{{ data.year }} state view</h2>
                    <p>Use the period links to jump from the statewide grid into the corresponding MDA drill-down for that exact governing workplan revision.</p>
                </div>
                <span class="civic-inline-note">{{ data.mdas.length }} MDAs tracked</span>
            </div>

            <div class="civic-performance-table-wrap">
                <div class="civic-table-wrap">
                    <table class="civic-table">
                        <thead>
                            <tr>
                                <th>MDA</th>
                                <th v-for="periodKey in data.periods" :key="periodKey">{{ periodKey.toUpperCase() }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="mda in data.mdas" :key="mda.id">
                                <td>{{ mda.name }}</td>
                                <td v-for="periodKey in data.periods" :key="periodKey">
                                    <RouterLink
                                        v-if="mda.observations[periodKey]?.workplan_id"
                                        :to="{ path: '/workplan-performance', query: { workplan_id: mda.observations[periodKey].workplan_id, period: periodKey } }"
                                    >
                                        {{ percent(mda.observations[periodKey].official_score) }}
                                    </RouterLink>
                                    <template v-else>N/A</template>
                                        <small v-if="mda.observations[periodKey]">
                                            Rev {{ mda.observations[periodKey].revision_no ?? 'N/A' }} -
                                            {{ mda.observations[periodKey].rank_status.replaceAll('_', ' ') }}
                                        </small>
                                </td>
                            </tr>
                            <tr v-if="!data.mdas.length">
                                <td colspan="6" class="civic-empty-cell">No workplan observations for this year.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</template>
