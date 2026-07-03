<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppTabs from '../components/AppTabs.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import ServiceReportsAnalytics from '../components/service-reporting/ServiceReportsAnalytics.vue';
import ServiceReportsDashboard from '../components/service-reporting/ServiceReportsDashboard.vue';
import ServiceReportsSubmissionDetail from '../components/service-reporting/ServiceReportsSubmissionDetail.vue';
import ServiceReportsSubmissions from '../components/service-reporting/ServiceReportsSubmissions.vue';
import ServiceReportsSubmitReturn from '../components/service-reporting/ServiceReportsSubmitReturn.vue';
import ServiceReportsTemplates from '../components/service-reporting/ServiceReportsTemplates.vue';
import { api, apiMessage } from '../lib/api';
import { titleCase, valueKey } from '../lib/serviceReporting';
import { auth, can } from '../stores/auth';
import { pushToast } from '../stores/app';

const route = useRoute();
const router = useRouter();

const loading = ref(true);
const busy = ref(false);
const error = ref('');
const dashboard = ref(null);
const templates = ref([]);
const submissions = ref([]);
const selectedTemplate = ref(null);
const selectedSubmission = ref(null);
const selectedDraftSubmission = ref(null);
const indicators = ref([]);
const analytics = ref(null);
const formErrors = ref({});

const today = new Date();
const currentYear = today.getFullYear();
const defaultMonth = `${currentYear}-${String(today.getMonth() + 1).padStart(2, '0')}`;

const draftForm = reactive({
    template_id: '',
    mda_id: '',
    station_id: '',
    period: defaultMonth,
    values: {},
});

const submissionFilters = reactive({
    template_id: '',
    status: '',
    month: '',
    year: '',
    mda_id: '',
    station_id: '',
});

const analyticsForm = reactive({
    template_code: 'HMB_MONTHLY_STATISTICS',
    indicator_code: '',
    from: `${currentYear - 2}-01`,
    to: `${currentYear - 1}-12`,
    mda_id: '',
    station_id: '',
    status: 'approved,locked',
});

const currentView = computed(() => {
    if (route.path.includes('/templates')) return 'templates';
    if (route.path.includes('/submissions/')) return 'submission-detail';
    if (route.path.includes('/submissions')) return 'submissions';
    if (route.path.includes('/submit')) return 'submit';
    if (route.path.includes('/analytics')) return 'analytics';

    return 'dashboard';
});

const activeTab = computed(() => (currentView.value === 'submission-detail' ? 'submissions' : currentView.value));

const navItems = computed(() => [
    { id: 'dashboard', label: 'Dashboard', to: '/service-reports' },
    { id: 'templates', label: 'Templates', to: '/service-reports/templates', count: templates.value.length },
    { id: 'submissions', label: 'Submissions', to: '/service-reports/submissions', count: dashboard.value?.pending_submissions?.length ?? undefined },
    { id: 'submit', label: 'Submit Return', to: '/service-reports/submit' },
    { id: 'analytics', label: 'Analytics', to: '/service-reports/analytics' },
]);

const monthOptions = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

const yearOptions = Array.from({ length: 7 }, (_, index) => currentYear - 4 + index);

const isGlobalUser = computed(() => Boolean(auth.user?.has_global_access));
const canManageTemplates = computed(() => can('manage-report-templates'));
const canAssignTemplates = computed(() => can('assign-report-templates'));
const canApprove = computed(() => can('approve-service-reports'));
const canLock = computed(() => can('lock-service-reports'));
const canReview = computed(() => can('review-service-reports') || can('return-service-reports'));
const canExport = computed(() => can('export-service-reports'));

const mdas = computed(() => dashboard.value?.mdas ?? []);
const allStations = computed(() => dashboard.value?.stations ?? []);
const departments = computed(() => dashboard.value?.departments ?? []);
const pendingSubmissions = computed(() => dashboard.value?.pending_submissions ?? []);
const dashboardSummary = computed(() => dashboard.value?.summary ?? {});

const complianceTotals = computed(() => {
    const rows = dashboard.value?.compliance ?? [];

    return rows.reduce((totals, row) => ({
        expected: totals.expected + Number(row.expected ?? 0),
        missing: totals.missing + Number(row.missing ?? 0),
    }), { expected: 0, missing: 0 });
});

const statCards = computed(() => [
    { key: 'draft', label: 'Draft', value: dashboardSummary.value.draft ?? 0, hint: 'Saved returns not yet submitted' },
    { key: 'submitted', label: 'Submitted', value: dashboardSummary.value.submitted ?? 0, hint: 'Awaiting review or approval' },
    { key: 'returned', label: 'Returned', value: dashboardSummary.value.returned ?? 0, hint: 'Sent back for correction' },
    { key: 'approved', label: 'Approved', value: dashboardSummary.value.approved ?? 0, hint: 'Cleared for reporting' },
    { key: 'locked', label: 'Locked', value: dashboardSummary.value.locked ?? 0, hint: 'Finalized records' },
    { key: 'missing', label: 'Missing', value: complianceTotals.value.missing, hint: `${complianceTotals.value.expected} expected reports` },
]);

const quickActions = computed(() => [
    { label: 'Submit Monthly Return', description: 'Start or continue a facility return for an open reporting period.', to: '/service-reports/submit', visible: can('create-service-reports') },
    { label: 'View Submissions', description: 'Track drafts, returned reports, approvals, and locked records.', to: '/service-reports/submissions', visible: true },
    { label: 'Manage Templates', description: 'Review report structure, sections, indicators, and facility assignments.', to: '/service-reports/templates', visible: canManageTemplates.value },
    { label: 'View Analytics', description: 'Aggregate approved and locked service statistics for leadership review.', to: '/service-reports/analytics', visible: true },
].filter((action) => action.visible));

const selectedTemplateForDraft = computed(() => {
    if (selectedDraftSubmission.value?.template_detail) {
        return selectedDraftSubmission.value.template_detail;
    }

    return templates.value.find((template) => Number(template.id) === Number(draftForm.template_id)) ?? null;
});

const draftStations = computed(() => stationsForMda(draftForm.mda_id));
const filterStations = computed(() => stationsForMda(submissionFilters.mda_id));
const analyticsStations = computed(() => stationsForMda(analyticsForm.mda_id));
const draftCanEdit = computed(() => !selectedDraftSubmission.value || ['draft', 'returned'].includes(selectedDraftSubmission.value.status));

function stationsForMda(mdaId) {
    return allStations.value.filter((station) => !mdaId || Number(station.mda_id) === Number(mdaId));
}

function goToTab(tabId) {
    const item = navItems.value.find((candidate) => candidate.id === tabId);
    if (item) router.push(item.to);
}

async function loadDashboard() {
    dashboard.value = (await api.get('/service-reports')).data.data;
}

async function loadTemplates() {
    templates.value = (await api.get('/service-reports/templates')).data.data;

    if (!draftForm.template_id && templates.value[0]) {
        selectTemplateForDraft(templates.value[0].id);
    }

    if (!analyticsForm.template_code && templates.value[0]) {
        analyticsForm.template_code = templates.value[0].code;
    }
}

async function loadSubmissions() {
    const params = Object.fromEntries(Object.entries(submissionFilters).filter(([, value]) => value !== '' && value !== null && value !== undefined));
    submissions.value = (await api.get('/service-reports/submissions', { params })).data.data;
}

async function loadSubmission(id) {
    selectedSubmission.value = (await api.get(`/service-reports/submissions/${id}`)).data.data;
}

async function loadTemplate(id) {
    selectedTemplate.value = (await api.get(`/service-reports/templates/${id}`)).data.data;
}

async function loadIndicators() {
    if (!analyticsForm.template_code) return;

    indicators.value = (await api.get('/service-reports/analytics/indicators', { params: { template_code: analyticsForm.template_code } })).data.data;

    if (!indicators.value.some((indicator) => indicator.code === analyticsForm.indicator_code)) {
        analyticsForm.indicator_code = indicators.value[0]?.code ?? '';
    }
}

async function loadDraftSubmission(id) {
    selectedDraftSubmission.value = (await api.get(`/service-reports/submissions/${id}`)).data.data;
    const submission = selectedDraftSubmission.value;

    draftForm.template_id = submission.template_id;
    draftForm.mda_id = submission.mda_id;
    draftForm.station_id = submission.station_id ?? '';
    draftForm.period = `${submission.period.year}-${String(submission.period.month).padStart(2, '0')}`;
    draftForm.values = {};

    for (const value of submission.values ?? []) {
        draftForm.values[valueKey(value.indicator_code, value.dimension_key ?? '', value.dimension_value ?? '')] = value.value;
    }
}

async function load() {
    loading.value = true;
    error.value = '';
    formErrors.value = {};
    analytics.value = currentView.value === 'analytics' ? analytics.value : null;
    selectedTemplate.value = null;
    selectedSubmission.value = null;

    try {
        await loadDashboard();
        await loadTemplates();

        if (currentView.value === 'submissions') await loadSubmissions();
        if (currentView.value === 'submission-detail') await loadSubmission(route.params.id);
        if (currentView.value === 'templates' && route.params.id) await loadTemplate(route.params.id);
        if (currentView.value === 'submit' && route.query.submission) {
            await loadDraftSubmission(route.query.submission);
        } else if (currentView.value === 'submit') {
            selectedDraftSubmission.value = null;
        }
        if (currentView.value === 'analytics') {
            await loadIndicators();
            if (analyticsForm.indicator_code) await runAnalytics(false);
        }
    } catch (requestError) {
        error.value = apiMessage(requestError, 'Service reporting is unavailable.');
    } finally {
        loading.value = false;
    }
}

function selectTemplateForDraft(id) {
    selectedDraftSubmission.value = null;
    draftForm.template_id = id;
    draftForm.values = {};

    const template = templates.value.find((candidate) => Number(candidate.id) === Number(id));
    const assignment = template?.assignments?.[0];
    draftForm.mda_id = assignment?.mda_id ?? dashboard.value?.mdas?.[0]?.id ?? '';
    draftForm.station_id = assignment?.station_id ?? '';
}

function clearSubmissionFilters() {
    Object.assign(submissionFilters, {
        template_id: '',
        status: '',
        month: '',
        year: '',
        mda_id: '',
        station_id: '',
    });
    loadSubmissions();
}

function buildValuesPayload() {
    const template = selectedTemplateForDraft.value;
    if (!template) return [];

    return template.sections.flatMap((section) => section.indicators.map((indicator) => {
        if (indicator.dimensions?.length) {
            const dimensions = {};
            for (const dimension of indicator.dimensions) {
                dimensions[dimension.dimension_key] = {};
                for (const dimensionValue of dimension.dimension_values) {
                    dimensions[dimension.dimension_key][dimensionValue] = draftForm.values[valueKey(indicator, dimension.dimension_key, dimensionValue)] ?? null;
                }
            }

            return { indicator_code: indicator.code, dimensions };
        }

        return { indicator_code: indicator.code, value: draftForm.values[valueKey(indicator)] ?? null };
    }));
}

async function createOrSaveDraft(submitAfterSave = false) {
    busy.value = true;
    formErrors.value = {};

    try {
        let submission = selectedDraftSubmission.value;

        if (!submission) {
            submission = (await api.post('/service-reports/submissions', {
                template_id: draftForm.template_id,
                mda_id: draftForm.mda_id,
                station_id: draftForm.station_id || null,
                period: draftForm.period,
            })).data.data;
        }

        const saved = await api.put(`/service-reports/submissions/${submission.id}/draft`, { values: buildValuesPayload() });

        if (submitAfterSave) {
            await api.post(`/service-reports/submissions/${submission.id}/submit`, { comment: 'Submitted from Service Reporting workspace.' });
            pushToast('Report submitted.');
        } else {
            pushToast(saved.data.message);
        }

        await router.push(`/service-reports/submissions/${submission.id}`);
    } catch (requestError) {
        formErrors.value = requestError.response?.data?.errors ?? {};
        pushToast(apiMessage(requestError), 'error', 4200);
    } finally {
        busy.value = false;
    }
}

async function workflow(action) {
    if (!selectedSubmission.value) return;

    busy.value = true;

    try {
        const payload = action === 'return' ? { reason: 'Returned for correction.' } : { comment: `${titleCase(action)} from Service Reporting workspace.` };
        const response = await api.post(`/service-reports/submissions/${selectedSubmission.value.id}/${action}`, payload);
        selectedSubmission.value = response.data.data;
        pushToast(response.data.message);
    } catch (requestError) {
        pushToast(apiMessage(requestError), 'error', 4200);
    } finally {
        busy.value = false;
    }
}

async function activateTemplate(template, active) {
    busy.value = true;

    try {
        const endpoint = active ? 'activate' : 'deactivate';
        const response = await api.post(`/service-reports/templates/${template.id}/${endpoint}`);
        pushToast(response.data.message);
        await loadTemplates();
        if (selectedTemplate.value?.id === template.id) {
            await loadTemplate(template.id);
        }
    } catch (requestError) {
        pushToast(apiMessage(requestError), 'error', 4200);
    } finally {
        busy.value = false;
    }
}

async function runAnalytics(showBusy = true) {
    if (!analyticsForm.template_code || !analyticsForm.indicator_code) return;

    if (showBusy) busy.value = true;

    try {
        analytics.value = (await api.get('/service-reports/analytics/trends', { params: analyticsForm })).data.data;
    } catch (requestError) {
        pushToast(apiMessage(requestError), 'error', 4200);
    } finally {
        if (showBusy) busy.value = false;
    }
}

function continueDraft(submission) {
    router.push({ path: '/service-reports/submit', query: { submission: submission.id } });
}

async function refreshTemplate(id) {
    await loadTemplates();
    await loadTemplate(id);
}

watch(() => route.fullPath, load);
watch(() => analyticsForm.template_code, async () => {
    if (currentView.value !== 'analytics') return;
    await loadIndicators();
});

onMounted(load);
</script>

<template>
    <PageHeading
        eyebrow="Service reporting"
        title="MDA Service Reporting and Returns"
        description="Manage monthly service reports, facility returns, approvals, and reporting analytics."
    >
        <RouterLink v-if="can('create-service-reports')" class="civic-button civic-button-primary" to="/service-reports/submit">Submit return</RouterLink>
    </PageHeading>

    <LoadingBlock v-if="loading" />

    <section v-else class="civic-reporting-space">
        <div v-if="error" class="civic-error">{{ error }}</div>

        <template v-else>
            <AppTabs :tabs="navItems" :model-value="activeTab" @update:model-value="goToTab" />

            <ServiceReportsDashboard
                v-if="currentView === 'dashboard'"
                :stat-cards="statCards"
                :quick-actions="quickActions"
                :pending-submissions="pendingSubmissions"
            />

            <ServiceReportsTemplates
                v-if="currentView === 'templates'"
                :templates="templates"
                :selected-template="selectedTemplate"
                :mdas="mdas"
                :stations="allStations"
                :departments="departments"
                :is-global-user="isGlobalUser"
                :can-manage-templates="canManageTemplates"
                :can-assign-templates="canAssignTemplates"
                :busy="busy"
                @view-template="loadTemplate"
                @activate-template="activateTemplate"
                @refresh-templates="loadTemplates"
                @refresh-template="refreshTemplate"
                @template-created="refreshTemplate"
            />

            <ServiceReportsSubmissions
                v-if="currentView === 'submissions'"
                :filters="submissionFilters"
                :templates="templates"
                :month-options="monthOptions"
                :year-options="yearOptions"
                :is-global-user="isGlobalUser"
                :mdas="mdas"
                :stations="filterStations"
                :submissions="submissions"
                :busy="busy"
                :can-review="canReview"
                :can-approve="canApprove"
                :can-lock="canLock"
                @apply-filters="loadSubmissions"
                @clear-filters="clearSubmissionFilters"
                @continue-draft="continueDraft"
            />

            <ServiceReportsSubmissionDetail
                v-if="currentView === 'submission-detail' && selectedSubmission"
                :submission="selectedSubmission"
                :busy="busy"
                :can-review="canReview"
                :can-approve="canApprove"
                :can-lock="canLock"
                :can-export="canExport"
                @workflow="workflow"
                @continue-draft="continueDraft"
            />

            <ServiceReportsSubmitReturn
                v-if="currentView === 'submit'"
                :draft-form="draftForm"
                :selected-draft-submission="selectedDraftSubmission"
                :draft-can-edit="draftCanEdit"
                :selected-template="selectedTemplateForDraft"
                :templates="templates"
                :is-global-user="isGlobalUser"
                :mdas="mdas"
                :stations="draftStations"
                :busy="busy"
                :form-errors="formErrors"
                @save-draft="createOrSaveDraft(false)"
                @submit-return="createOrSaveDraft(true)"
                @select-template="selectTemplateForDraft"
            />

            <ServiceReportsAnalytics
                v-if="currentView === 'analytics'"
                :analytics-form="analyticsForm"
                :analytics="analytics"
                :templates="templates"
                :indicators="indicators"
                :is-global-user="isGlobalUser"
                :mdas="mdas"
                :stations="analyticsStations"
                :busy="busy"
                @run-analytics="runAnalytics"
            />
        </template>
    </section>
</template>
