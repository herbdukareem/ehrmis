<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppModal from '../components/AppModal.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();
const router = useRouter();
const workplan = ref(null);
const progressMatrix = ref([]);
const error = ref('');
const busy = ref(false);
const modal = ref(null);
const comment = ref('');

const title = computed(() => ({
    return: 'Return for correction',
    reject: 'Reject workplan',
    amendment: 'Create amendment',
}[modal.value] ?? ''));

const workflowCycles = computed(() => [
    ...(workplan.value?.workflow?.history ?? []),
    workplan.value?.workflow ? {
        status: workplan.value.workflow.status,
        submitted_at: workplan.value.submitted_at,
        steps: workplan.value.workflow.steps,
    } : null,
].filter(Boolean));

const formatMoney = (value) => {
    if (value === null || value === undefined || value === '') {
        return 'Not stated';
    }

    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        maximumFractionDigits: 2,
    }).format(Number(value));
};

const formatTargets = (targets = []) => targets
    .map((target) => `${target.period?.toUpperCase()}: ${target.target_value ?? '-'}`)
    .join(' | ');

const load = async () => {
    try {
        workplan.value = (await api.get(`/workplans/${route.params.id}`)).data.data;
        progressMatrix.value = workplan.value.status === 'active'
            ? ((await api.get(`/workplans/${route.params.id}/progress-matrix`)).data.data ?? [])
            : [];
    } catch (e) {
        error.value = apiMessage(e);
    }
};

const createProgress = async (activity, period) => {
    busy.value = true;
    error.value = '';

    try {
        const response = await api.post(`/workplan-activities/${activity.id}/progress-reports`, { period });
        await router.push(`/workplan-progress/${response.data.data.id}`);
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        busy.value = false;
    }
};

const action = async (path, payload = null) => {
    busy.value = true;
    error.value = '';

    try {
        const response = await api.post(`/workplans/${workplan.value.id}/${path}`, payload);

        if (path === 'amendments') {
            await router.push(`/workplans/${response.data.data.id}/edit`);
        } else {
            await load();
        }

        modal.value = null;
        comment.value = '';
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        busy.value = false;
    }
};

const submitModal = () => {
    if (! comment.value.trim()) {
        error.value = 'A reason is required.';
        return;
    }

    action(
        modal.value === 'amendment' ? 'amendments' : modal.value,
        modal.value === 'amendment' ? { amendment_reason: comment.value } : { comment: comment.value },
    );
};

onMounted(load);
</script>

<template>
    <PageHeading eyebrow="Annual workplan" :title="workplan?.title ?? 'Workplan'">
        <RouterLink v-if="workplan?.can?.edit" class="civic-button civic-button-primary" :to="`/workplans/${route.params.id}/edit`">
            Edit Workplan
        </RouterLink>
    </PageHeading>

    <div v-if="error" class="civic-error">{{ error }}</div>
    <LoadingBlock v-else-if="!workplan" />

    <section v-else class="civic-workspace civic-workplan-show-summary">
        <div class="civic-section-heading">
            <div>
                <span class="civic-kicker">{{ workplan.mda?.name }} &middot; {{ workplan.year }}</span>
                <h2>
                    Revision {{ workplan.revision_no }}
                    <span v-if="workplan.status === 'draft' && workplan.supersedes_workplan_id"> - Draft amendment</span>
                </h2>
            </div>
            <StatusPill :status="workplan.status" />
        </div>

        <div class="civic-workplan-show-body">
            <div class="civic-workplan-status-note">
                <p v-if="workplan.status === 'approved'">This Workplan has completed approval and is awaiting activation.</p>
                <p v-if="workplan.status === 'active'"><strong>Active official Workplan.</strong> This revision is the operational baseline. Structural changes require an amendment revision.</p>
                <p v-if="workplan.status === 'returned'"><strong>Returned for correction:</strong> {{ workplan.latest_return_reason }}</p>
                <p v-if="workplan.status === 'rejected'"><strong>Rejected:</strong> {{ workplan.latest_rejection_reason }}</p>
                <p v-if="workplan.status === 'superseded'">
                    Superseded {{ workplan.superseded_at }}
                    <RouterLink v-if="workplan.superseded_by_workplan_id" :to="`/workplans/${workplan.superseded_by_workplan_id}`">View replacement revision</RouterLink>
                </p>
                <p v-if="workplan.amendment_reason"><strong>Amendment reason:</strong> {{ workplan.amendment_reason }}</p>
            </div>

            <div class="civic-workplan-description">
                <span class="civic-kicker">Plan brief</span>
                <p>{{ workplan.description || 'No description has been provided for this Workplan.' }}</p>
            </div>
        </div>

        <div class="civic-workplan-show-body">
            <div class="civic-workplan-description">
                <span class="civic-kicker">Document classification</span>
                <p>{{ workplan.document_classification || 'Not stated' }}</p>
            </div>
            <div class="civic-workplan-description">
                <span class="civic-kicker">Prepared by</span>
                <p>{{ workplan.prepared_by_label || workplan.prepared_by?.name || 'Not stated' }}</p>
            </div>
        </div>

        <div class="civic-workplan-description">
            <span class="civic-kicker">Overall goal</span>
            <p>{{ workplan.overall_goal || 'No overall goal has been recorded for this Workplan.' }}</p>
        </div>

        <div class="civic-workplan-show-body">
            <div class="civic-workplan-description">
                <span class="civic-kicker">Strategic directions</span>
                <ul v-if="workplan.strategic_directions?.length">
                    <li v-for="direction in workplan.strategic_directions" :key="direction">{{ direction }}</li>
                </ul>
                <p v-else>No strategic directions have been recorded.</p>
            </div>

            <div class="civic-workplan-description">
                <span class="civic-kicker">Planning assumptions</span>
                <ul v-if="workplan.planning_assumptions?.length">
                    <li v-for="assumption in workplan.planning_assumptions" :key="assumption">{{ assumption }}</li>
                </ul>
                <p v-else>No planning assumptions have been recorded.</p>
            </div>
        </div>

        <div class="civic-button-row civic-workplan-actions">
            <button v-if="workplan.can.submit" class="civic-button civic-button-primary" :disabled="busy" @click="action('submit')">
                {{ workplan.status === 'returned' ? 'Resubmit for Approval' : 'Submit for Approval' }}
            </button>
            <button v-if="workplan.can.approve" class="civic-button civic-button-primary" :disabled="busy" @click="action('approve')">Approve</button>
            <button v-if="workplan.can.return" class="civic-button" @click="modal = 'return'">Return for Correction</button>
            <button v-if="workplan.can.reject" class="civic-button" @click="modal = 'reject'">Reject</button>
            <button v-if="workplan.can.activate" class="civic-button civic-button-primary" :disabled="busy" @click="action('activate')">Activate Workplan</button>
            <button v-if="workplan.can.close" class="civic-button" :disabled="busy" @click="action('close')">Close Workplan</button>
            <button v-if="workplan.can.amend" class="civic-button civic-button-primary" @click="modal = 'amendment'">Create Amendment</button>
        </div>
    </section>

    <section v-if="workplan" class="civic-workspace civic-workplan-show-card">
        <div class="civic-workplan-card-heading">
            <div>
                <span class="civic-kicker">Workflow record</span>
                <h3>Approval timeline</h3>
            </div>
        </div>
        <div v-if="!workflowCycles.length" class="civic-workplan-empty">No approval actions have been recorded yet.</div>
        <article v-for="(cycle, index) in workflowCycles" :key="`${index}-${cycle.recorded_at}`" class="civic-workplan-cycle">
            <div class="civic-workplan-cycle-heading">
                <strong>{{ index ? 'Previous review cycle' : 'Current review cycle' }}</strong>
                <StatusPill :status="cycle.status" />
            </div>
            <ol class="civic-workplan-step-list">
                <li v-for="step in cycle.steps" :key="`${index}-${step.step_no}`">
                    <div>
                        <strong>Step {{ step.step_no }}</strong>
                        <span>{{ step.reviewer_permission || step.reviewer_role || 'Review' }}</span>
                    </div>
                    <StatusPill :status="step.status" />
                    <p>
                        <span v-if="step.acted_at">{{ step.acted_at }}</span>
                        <span v-if="step.acted_by?.name"> by {{ step.acted_by.name }}</span>
                        <br v-if="step.comment">
                        <em v-if="step.comment">{{ step.comment }}</em>
                    </p>
                </li>
            </ol>
        </article>
    </section>

    <section v-if="workplan" class="civic-workspace civic-workplan-show-card">
        <div class="civic-workplan-card-heading">
            <div>
                <span class="civic-kicker">Revision control</span>
                <h3>Revision history</h3>
            </div>
        </div>
        <div class="civic-workplan-revision-list">
            <RouterLink v-for="revision in workplan.revisions" :key="revision.id" :to="`/workplans/${revision.id}`">
                <span>
                    <strong>Revision {{ revision.revision_no }}</strong>
                    <small v-if="revision.id === workplan.id">Currently viewing</small>
                </span>
                <StatusPill :status="revision.status" />
            </RouterLink>
        </div>
    </section>

    <section v-if="workplan?.status === 'active'" class="civic-workspace civic-workplan-show-card">
        <div class="civic-workplan-card-heading">
            <div>
                <span class="civic-kicker">Operational reporting</span>
                <h3>Progress reporting</h3>
                <p>Quarterly actuals are cumulative totals as at the end of each reporting period.</p>
            </div>
        </div>
        <div v-if="!progressMatrix.length" class="civic-workplan-empty">There are no activities available for reporting in this revision.</div>
        <article v-for="activity in progressMatrix" :key="activity.id" class="civic-workplan-progress-row">
            <div>
                <strong>{{ activity.code }} &middot; {{ activity.title }}</strong>
                <small>Choose a period to create or open its progress report.</small>
            </div>
            <div class="civic-workplan-period-grid">
                <template v-for="period in ['q1', 'q2', 'q3', 'q4', 'annual']" :key="period">
                    <RouterLink v-if="activity.reports?.[period]" class="civic-workplan-period" :to="`/workplan-progress/${activity.reports[period].id}`">
                        <span>{{ period.toUpperCase() }}</span>
                        <small>{{ activity.reports[period].status }}</small>
                    </RouterLink>
                    <button v-else class="civic-workplan-period" :disabled="busy" @click="createProgress(activity, period)">
                        <span>{{ period.toUpperCase() }}</span>
                        <small>Not started</small>
                    </button>
                </template>
            </div>
        </article>
    </section>

    <section v-if="workplan" class="civic-workspace civic-workplan-show-card">
        <div class="civic-workplan-card-heading">
            <div>
                <span class="civic-kicker">Approved plan detail</span>
                <h3>Planning structure</h3>
            </div>
        </div>

        <div v-if="!workplan.objectives?.length" class="civic-workplan-empty">No objectives have been added to this Workplan yet.</div>

        <article v-for="objective in workplan.objectives" :key="objective.id" class="civic-workplan-objective-card">
            <div class="civic-workplan-objective-heading">
                <div>
                    <span class="civic-kicker">{{ objective.code }}</span>
                    <h4>{{ objective.title }}</h4>
                    <p>{{ objective.department?.name || 'MDA-wide objective' }}</p>
                </div>
                <span class="civic-workplan-indicator-count">{{ formatMoney(objective.planned_cost) }}</span>
            </div>

            <p v-if="objective.lead_scope"><strong>Lead scope:</strong> {{ objective.lead_scope }}</p>
            <p v-if="objective.description">{{ objective.description }}</p>

            <div v-if="!objective.activities?.length" class="civic-workplan-empty">No activities have been added under this objective.</div>

            <div v-for="activity in objective.activities" :key="activity.id" class="civic-workplan-activity-row">
                <div>
                    <strong>{{ activity.activity_code }} &middot; {{ activity.title }}</strong>
                    <small>{{ activity.responsible_staff?.full_name || 'No accountable officer' }} &middot; {{ activity.start_date }} to {{ activity.end_date }}</small>
                    <p v-if="activity.description">{{ activity.description }}</p>
                    <p v-if="activity.expected_output"><strong>Expected output:</strong> {{ activity.expected_output }}</p>
                    <p><strong>Planned cost:</strong> {{ formatMoney(activity.planned_cost) }}</p>
                    <p v-if="activity.funding_source"><strong>Funding source:</strong> {{ activity.funding_source }}</p>

                    <div v-if="activity.indicators.length" class="civic-workplan-description">
                        <span class="civic-kicker">Indicators</span>
                        <ul>
                            <li v-for="indicator in activity.indicators" :key="indicator.id">
                                <strong>{{ indicator.code }} - {{ indicator.indicator }}</strong>
                                <span v-if="indicator.unit"> ({{ indicator.unit }})</span>
                                <span v-if="indicator.description">: {{ indicator.description }}</span>
                                <br>
                                <small>{{ formatTargets(indicator.targets) }}</small>
                            </li>
                        </ul>
                    </div>
                </div>
                <span class="civic-workplan-indicator-count">{{ activity.indicators.length }} {{ activity.indicators.length === 1 ? 'indicator' : 'indicators' }}</span>
            </div>
        </article>
    </section>

    <AppModal :open="Boolean(modal)" eyebrow="Workplan workflow" :title="title" @close="modal = null">
        <label class="civic-field">
            <span>{{ modal === 'amendment' ? 'Amendment reason' : modal === 'reject' ? 'Rejection reason' : 'Correction reason' }}</span>
            <textarea v-model="comment" rows="4" required></textarea>
        </label>
        <template #actions>
            <button class="civic-button" @click="modal = null">Cancel</button>
            <button class="civic-button civic-button-primary" :disabled="busy" @click="submitModal">Confirm</button>
        </template>
    </AppModal>
</template>
