<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
import AppTable from '@/Components/AppTable.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import MoneyDisplay from '@/Components/MoneyDisplay.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    workbook: Object,
    lines: Array,
});

const lineRows = computed(() => (props.lines ?? []).map((line) => ([
    line.department,
    `${line.scale ?? 'N/A'} ${line.level ?? '-'}`,
    line.staff_count,
    line.retiring_count,
    line.current_gross_total,
    line.proposed_gross_total,
    line.variance_total,
])));

const workflowForm = useForm({
    comment: '',
});

const submitApproval = () => router.post(route('budget-workbooks.submit-approval', props.workbook.id));
const approveWorkbook = () => router.post(route('budget-workbooks.approve', props.workbook.id));
const rejectWorkbook = () => workflowForm.post(route('budget-workbooks.reject', props.workbook.id), {
    preserveScroll: true,
});
const lockWorkbook = () => router.post(route('budget-workbooks.lock', props.workbook.id));
const reopenWorkbook = () => router.post(route('budget-workbooks.reopen', props.workbook.id));
</script>

<template>
    <Head :title="`Budget Workbook ${workbook.id}`" />

    <AppLayout>
        <AppPageHeader
            :title="`Budget Workbook #${workbook.id}`"
            :subtitle="`${workbook.mda?.name} for ${workbook.year}`"
            :breadcrumbs="[{ label: 'Budget Workbooks', href: route('budget-workbooks.index') }, { label: `Workbook #${workbook.id}` }]"
        >
            <template #actions>
                <div class="flex flex-wrap gap-2">
                    <AppButton :href="route('budget-workbooks.index')" variant="secondary">
                        Back to Budgets
                    </AppButton>
                    <AppButton
                        v-if="['draft', 'reopened', 'rejected'].includes(workbook.status)"
                        variant="secondary"
                        @click="submitApproval"
                    >
                        Submit for Approval
                    </AppButton>
                    <AppButton
                        v-if="['draft', 'submitted', 'reopened', 'rejected'].includes(workbook.status)"
                        variant="secondary"
                        @click="approveWorkbook"
                    >
                        Approve
                    </AppButton>
                    <AppButton
                        v-if="['submitted', 'approved'].includes(workbook.status)"
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
                        v-if="['submitted', 'approved', 'locked', 'rejected'].includes(workbook.status)"
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
                <div class="text-sm text-ehrmis-muted">Line Count</div>
                <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ workbook.summary?.line_count ?? 0 }}</div>
            </AppCard>

            <AppCard>
                <div class="text-sm text-ehrmis-muted">Staff Count</div>
                <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ workbook.summary?.staff_count ?? 0 }}</div>
            </AppCard>

            <AppCard>
                <div class="text-sm text-ehrmis-muted">Movement Source</div>
                <div class="mt-2 text-3xl font-semibold text-ehrmis-text">#{{ workbook.movement_workbook_id }}</div>
            </AppCard>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.35fr_0.65fr]">
            <div>
                <AppTable
                    v-if="lineRows.length"
                    :headers="['Department', 'Scale/Level', 'Staff', 'Retiring', 'Current Gross', 'Proposed Gross', 'Variance']"
                    :rows="lineRows"
                />
                <AppEmptyState
                    v-else
                    title="No budget lines generated"
                    message="This workbook does not yet contain summarized budget lines."
                />
            </div>

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
                    </div>
                </AppCard>

                <AppCard title="Budget Totals">
                    <div class="space-y-4 text-sm text-ehrmis-muted">
                        <div class="flex items-center justify-between">
                            <span>Current gross</span>
                            <MoneyDisplay :amount="workbook.summary?.current_gross_total ?? 0" />
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Proposed gross</span>
                            <MoneyDisplay :amount="workbook.summary?.proposed_gross_total ?? 0" />
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Variance</span>
                            <MoneyDisplay :amount="workbook.summary?.variance_total ?? 0" />
                        </div>
                    </div>
                </AppCard>
            </div>
        </div>
    </AppLayout>
</template>
