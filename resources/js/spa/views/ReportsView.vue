<script setup>
import { onMounted } from 'vue';
import PageHeading from '../components/PageHeading.vue';
import { hasAnyPermission } from '../stores/auth';
import { setPageError } from '../stores/app';

const canAccessReports = () => hasAnyPermission(['view-reports', 'export-reports']);

onMounted(() => {
    if (!canAccessReports()) {
        setPageError('This action is unauthorized.');
    }
});
</script>

<template>
    <template v-if="canAccessReports()">
        <PageHeading eyebrow="Statutory reporting" title="Reports register" description="A controlled catalogue for operational and approved snapshot reports." />
        <section class="civic-workspace civic-prose">
            <div class="civic-eyebrow">Design stage</div>
            <h2>Report catalogue is the next operational module.</h2>
            <p>The SPA foundation is ready for staff rosters, retirement schedules, payroll comparisons, movement summaries, recurrent estimates, and audit exports.</p>
        </section>
    </template>
</template>
