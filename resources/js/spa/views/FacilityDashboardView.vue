<script setup>
import { computed, onMounted, ref } from 'vue';
import DonutChart from '../components/DonutChart.vue';
import HorizontalBarChart from '../components/HorizontalBarChart.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import { api } from '../lib/api';
import { can } from '../stores/auth';
import { setPageError } from '../stores/app';

const data = ref(null);
const reporting = computed(() => data.value?.reporting ?? {});

onMounted(async () => {
    try {
        data.value = (await api.get('/facility-dashboard')).data.data;
    } catch (error) {
        setPageError(error.response?.data?.message ?? 'The facility dashboard could not be loaded.');
    }
});
</script>

<template>
    <PageHeading
        eyebrow="Facility operations"
        :title="data ? `${data.facility.name} dashboard` : 'Facility dashboard'"
        :description="data ? `${data.facility.mda?.name ?? 'Assigned MDA'} · A focused view of your facility workforce and reporting position.` : 'A focused view of your facility workforce and reporting position.'"
    >
        <RouterLink v-if="can('create-service-reports')" class="civic-button civic-button-primary" to="/service-reports/submit">Create New Report</RouterLink>
    </PageHeading>

    <LoadingBlock v-if="!data" />
    <template v-else>
        <section class="civic-facility-banner">
            <div><span>Assigned facility</span><strong>{{ data.facility.code }} · {{ data.facility.name }}</strong></div>
            <div><span>Reporting MDA</span><strong>{{ data.facility.mda?.code ?? 'Not recorded' }}</strong></div>
            <RouterLink v-if="can('view-service-reports')" class="civic-text-action" to="/service-reports">Open service reports</RouterLink>
        </section>

        <section class="civic-metric-band civic-metric-band-wide civic-metric-band-five">
            <div><span>Facility staff</span><strong>{{ data.counts.staff.toLocaleString() }}</strong></div>
            <div><span>Active staff</span><strong>{{ data.counts.active_staff.toLocaleString() }}</strong></div>
            <div><span>Retired staff</span><strong>{{ data.counts.retired_staff.toLocaleString() }}</strong></div>
            <div><span>Contract staff</span><strong>{{ data.counts.contract_staff.toLocaleString() }}</strong></div>
            <div><span>Retiring this year</span><strong>{{ data.retirement_windows.this_year.toLocaleString() }}</strong></div>
        </section>

        <section class="civic-retirement-strip">
            <div class="civic-retirement-lead"><span>Retirement watch</span><strong>Plan staffing cover early</strong></div>
            <div><span>This month</span><strong>{{ data.retirement_windows.this_month }}</strong></div>
            <div><span>Next month</span><strong>{{ data.retirement_windows.next_month }}</strong></div>
            <div><span>This year</span><strong>{{ data.retirement_windows.this_year }}</strong></div>
        </section>

        <section v-if="can('view-service-reports')" class="civic-facility-reporting-grid">
            <article><span>Draft reports</span><strong>{{ reporting.draft ?? 0 }}</strong><small>Saved and awaiting submission</small></article>
            <article><span>Returned reports</span><strong>{{ reporting.returned ?? 0 }}</strong><small>Require correction</small></article>
            <article><span>Approved reports</span><strong>{{ reporting.approved ?? 0 }}</strong><small>Cleared for reporting</small></article>
            <article><span>Locked reports</span><strong>{{ reporting.locked ?? 0 }}</strong><small>Finalized records</small></article>
        </section>

        <section class="civic-facility-dashboard-grid">
            <article class="civic-workspace civic-facility-dashboard-panel">
                <div class="civic-analysis-heading"><div><div class="civic-eyebrow">Workforce composition</div><h2>Staff by department</h2></div></div>
                <HorizontalBarChart :rows="data.distributions.departments" />
            </article>
            <article class="civic-workspace civic-facility-dashboard-panel">
                <div class="civic-analysis-heading"><div><div class="civic-eyebrow">Workforce composition</div><h2>Gender distribution</h2></div></div>
                <DonutChart :rows="data.distributions.gender" />
            </article>
        </section>

        <section class="civic-workspace civic-facility-dashboard-panel">
            <div class="civic-analysis-heading"><div><div class="civic-eyebrow">Establishment profile</div><h2>Staff by cadre</h2></div><span>Top 12 cadres shown</span></div>
            <HorizontalBarChart :rows="data.distributions.cadres" />
        </section>
    </template>
</template>
