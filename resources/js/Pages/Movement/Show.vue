<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
import AppTable from '@/Components/AppTable.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppTabs from '@/Components/AppTabs.vue';
import MoneyDisplay from '@/Components/MoneyDisplay.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    workbook: Object,
    lines: Array,
    summaries: Array,
    filters: Object,
});

const lineTabs = computed(() => [
    { label: 'All Lines', href: route('movement-workbooks.show', props.workbook.id), active: !props.filters?.eligibility_status && !props.filters?.retirement_status && !props.filters?.selection_state },
    { label: 'Due', href: route('movement-workbooks.show', { workbook: props.workbook.id, eligibility_status: 'due' }), active: props.filters?.eligibility_status === 'due' },
    { label: 'Retiring', href: route('movement-workbooks.show', { workbook: props.workbook.id, retirement_status: 'retiring' }), active: props.filters?.retirement_status === 'retiring' },
    { label: 'Retired', href: route('movement-workbooks.show', { workbook: props.workbook.id, retirement_status: 'retired' }), active: props.filters?.retirement_status === 'retired' },
    { label: 'Blocked', href: route('movement-workbooks.show', { workbook: props.workbook.id, eligibility_status: 'blocked_by_policy' }), active: props.filters?.eligibility_status === 'blocked_by_policy' },
]);

const summaryRows = computed(() => (props.summaries ?? []).map((summary) => ([
    summary.department,
    `${summary.scale ?? 'N/A'} ${summary.level ?? '-'}`,
    summary.staff_count,
    summary.due_count,
    summary.retiring_count,
    summary.retired_count,
    summary.blocked_count,
])));

const lineRows = computed(() => (props.lines ?? []).map((line) => ([
    line.staff_number,
    line.full_name,
    line.department,
    `${line.scale ?? 'N/A'} ${line.current_level ?? '-'}`,
    line.proposed_level ?? '-',
    line.eligibility_status,
    line.retirement_status,
])));

const workflowForm = useForm({
    comment: '',
});

const reviewWorkbook = () => router.post(route('movement-workbooks.review', props.workbook.id));
const approveWorkbook = () => router.post(route('movement-workbooks.approve', props.workbook.id));
const rejectWorkbook = () => workflowForm.post(route('movement-workbooks.reject', props.workbook.id), {
    preserveScroll: true,
});
const lockWorkbook = () => router.post(route('movement-workbooks.lock', props.workbook.id));
const reopenWorkbook = () => router.post(route('movement-workbooks.reopen', props.workbook.id));
</script>

<template>
    <Head :title="`Movement Workbook ${workbook.id}`" />

    <AppLayout>
        <AppPageHeader
            :title="`Movement Workbook #${workbook.id}`"
            :subtitle="`${workbook.mda?.name} for ${workbook.year}`"
            :breadcrumbs="[{ label: 'Movement Workbooks', href: route('movement-workbooks.index') }, { label: `Workbook #${workbook.id}` }]"
        >
            <template #actions>
                <div class="flex flex-wrap gap-2">
                    <AppButton :href="route('movement-workbooks.index')" variant="secondary">
                        Back to Workbooks
                    </AppButton>
                    <AppButton
                        v-if="['draft', 'reopened'].includes(workbook.status)"
                        variant="secondary"
                        @click="reviewWorkbook"
                    >
                        Mark Reviewed
                    </AppButton>
                    <AppButton
                        v-if="['draft', 'reviewed', 'reopened'].includes(workbook.status)"
                        variant="secondary"
                        @click="approveWorkbook"
                    >
                        Approve
                    </AppButton>
                    <AppButton
                        v-if="workbook.status === 'approved'"
                        variant="secondary"
                        @click="lockWorkbook"
                    >
                        Lock
                    </AppButton>
                    <AppButton
                        v-if="['reviewed', 'approved', 'locked'].includes(workbook.status)"
                        variant="secondary"
                        @click="reopenWorkbook"
                    >
                        Reopen
                    </AppButton>
                </div>
            </template>
        </AppPageHeader>

        <div class="grid gap-6 lg:grid-cols-4">
            <AppCard>
                <div class="text-sm text-ehrmis-muted">Status</div>
                <div class="mt-3"><AppStatusBadge :status="workbook.status" /></div>
            </AppCard>

            <AppCard>
                <div class="text-sm text-ehrmis-muted">Due For Promotion</div>
                <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ workbook.summary?.due_for_promotion ?? 0 }}</div>
            </AppCard>

            <AppCard>
                <div class="text-sm text-ehrmis-muted">Retiring In Year</div>
                <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ workbook.summary?.retiring_in_year ?? 0 }}</div>
            </AppCard>

            <AppCard>
                <div class="text-sm text-ehrmis-muted">Blocked</div>
                <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ workbook.summary?.blocked ?? 0 }}</div>
            </AppCard>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.4fr_0.6fr]">
            <div class="space-y-6">
                <AppCard title="Approval Workflow">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <AppStatusBadge :status="workbook.approval_workflow?.status ?? 'draft'" />
                            <span class="text-sm text-ehrmis-muted">
                                Submitted: {{ workbook.approval_workflow?.submitted_at ?? 'Not yet submitted' }}
                            </span>
                        </div>

                        <div v-if="workbook.approval_workflow?.steps?.length" class="space-y-2">
                            <div
                                v-for="step in workbook.approval_workflow.steps"
                                :key="step.step_no"
                                class="rounded-ehrmis border border-ehrmis-line px-4 py-3"
                            >
                                <div class="flex items-center justify-between gap-4">
                                    <div class="text-sm font-semibold text-ehrmis-text">
                                        Step {{ step.step_no }}<span v-if="step.reviewer_role"> · {{ step.reviewer_role }}</span>
                                    </div>
                                    <AppStatusBadge :status="step.status" />
                                </div>
                                <div class="mt-1 text-sm text-ehrmis-muted">Acted at: {{ step.acted_at ?? 'Pending' }}</div>
                                <div v-if="step.comment" class="mt-2 text-sm text-ehrmis-text">{{ step.comment }}</div>
                            </div>
                        </div>

                        <div v-if="workbook.approval_workflow?.rejection_comment" class="text-sm text-red-600">
                            Rejection note: {{ workbook.approval_workflow.rejection_comment }}
                        </div>

                        <AppTextarea
                            v-model="workflowForm.comment"
                            label="Approval Comment"
                            rows="3"
                            placeholder="Required for rejection."
                            :error="workflowForm.errors.comment"
                        />

                        <div class="flex flex-wrap gap-2">
                            <AppButton
                                v-if="['draft', 'reopened', 'rejected'].includes(workbook.status)"
                                variant="secondary"
                                @click="reviewWorkbook"
                            >
                                Submit for Approval
                            </AppButton>
                            <AppButton
                                v-if="['draft', 'reviewed', 'reopened', 'rejected'].includes(workbook.status)"
                                variant="secondary"
                                @click="approveWorkbook"
                            >
                                Approve
                            </AppButton>
                            <AppButton
                                v-if="['reviewed', 'approved'].includes(workbook.status)"
                                variant="danger"
                                :disabled="workflowForm.processing"
                                @click="rejectWorkbook"
                            >
                                Reject
                            </AppButton>
                            <AppButton
                                v-if="workbook.status === 'approved'"
                                variant="secondary"
                                @click="lockWorkbook"
                            >
                                Lock
                            </AppButton>
                            <AppButton
                                v-if="['reviewed', 'approved', 'locked', 'rejected'].includes(workbook.status)"
                                variant="secondary"
                                @click="reopenWorkbook"
                            >
                                Reopen
                            </AppButton>
                        </div>
                    </div>
                </AppCard>

                <AppTabs :tabs="lineTabs" />

                <AppTable
                    v-if="lineRows.length"
                    :headers="['Staff No', 'Name', 'Department', 'Current', 'Proposed', 'Eligibility', 'Retirement']"
                    :rows="lineRows"
                />
                <AppEmptyState
                    v-else
                    title="No movement lines in this filter"
                    message="Try another movement status filter to inspect the workbook population."
                />

                <AppTable
                    v-if="summaryRows.length"
                    :headers="['Department', 'Scale/Level', 'Staff', 'Due', 'Retiring', 'Retired', 'Blocked']"
                    :rows="summaryRows"
                />
            </div>

            <div class="space-y-6">
                <AppCard title="Workbook Totals">
                    <div class="space-y-4 text-sm text-ehrmis-muted">
                        <div class="flex items-center justify-between">
                            <span>Staff considered</span>
                            <span class="font-semibold text-ehrmis-text">{{ workbook.summary?.staff_considered ?? 0 }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Lines generated</span>
                            <span class="font-semibold text-ehrmis-text">{{ workbook.summary?.lines_generated ?? 0 }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Already retired</span>
                            <span class="font-semibold text-ehrmis-text">{{ workbook.summary?.already_retired ?? 0 }}</span>
                        </div>
                    </div>
                </AppCard>

                <AppCard v-if="summaries?.length" title="First Summary Snapshot">
                    <div class="space-y-4 text-sm text-ehrmis-muted">
                        <div class="flex items-center justify-between">
                            <span>Department</span>
                            <span class="font-semibold text-ehrmis-text">{{ summaries[0].department }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Current gross</span>
                            <MoneyDisplay :amount="summaries[0].current_gross_total" />
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Proposed gross</span>
                            <MoneyDisplay :amount="summaries[0].proposed_gross_total" />
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Variance</span>
                            <MoneyDisplay :amount="summaries[0].variance_total" />
                        </div>
                    </div>
                </AppCard>
            </div>
        </div>
    </AppLayout>
</template>
