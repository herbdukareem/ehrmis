<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import ImportBatchStatusBadge from '@/Pages/LegacyStaffImports/Components/ImportBatchStatusBadge.vue';
import ImportBatchSummaryCards from '@/Pages/LegacyStaffImports/Components/ImportBatchSummaryCards.vue';
import ImportPublicationSummary from '@/Pages/LegacyStaffImports/Components/ImportPublicationSummary.vue';
import ImportRowFilters from '@/Pages/LegacyStaffImports/Components/ImportRowFilters.vue';
import ImportRowTable from '@/Pages/LegacyStaffImports/Components/ImportRowTable.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    batch: Object,
    summary: Object,
    rows: Object,
    filters: Object,
    filterOptions: Object,
    can: Object,
    latestPublication: Object,
});

const publishForm = useForm({});
const submitApprovalForm = useForm({});
const approvalDecisionForm = useForm({
    comment: '',
});

const publishBatch = () => publishForm.post(route('legacy-staff-imports.publish', props.batch.id));
const submitForApproval = () => submitApprovalForm.post(route('legacy-staff-imports.submit-approval', props.batch.id));
const approveBatch = () => approvalDecisionForm.post(route('legacy-staff-imports.approve', props.batch.id), {
    preserveScroll: true,
});
const rejectBatch = () => approvalDecisionForm.post(route('legacy-staff-imports.reject', props.batch.id), {
    preserveScroll: true,
});
</script>

<template>
    <Head :title="`Import Batch #${batch.id}`" />

    <AppLayout>
        <AppPageHeader
            :title="`Import Batch #${batch.id}`"
            :subtitle="`${batch.source_database} | ${batch.source_table}`"
            :breadcrumbs="[{ label: 'Legacy Staff Imports', href: route('legacy-staff-imports.index') }, { label: `Batch #${batch.id}` }]"
        >
            <template #actions>
                <div class="flex flex-wrap gap-3">
                    <AppButton :href="route('legacy-staff-imports.index')" variant="secondary">Back to Batches</AppButton>
                    <AppButton
                        v-if="can.publish"
                        :disabled="publishForm.processing"
                        @click="publishBatch"
                    >
                        Publish Batch
                    </AppButton>
                </div>
            </template>
        </AppPageHeader>

        <div class="space-y-6">
            <div class="flex items-center gap-3">
                <ImportBatchStatusBadge :status="batch.status" />
                <span class="text-sm text-ehrmis-muted">Started: {{ batch.started_at ?? 'N/A' }}</span>
                <span class="text-sm text-ehrmis-muted">Completed: {{ batch.completed_at ?? 'N/A' }}</span>
            </div>

            <ImportBatchSummaryCards :batch="batch" :summary="summary" />
            <AppCard>
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="space-y-3">
                        <div>
                            <div class="text-xs font-medium text-ehrmis-muted">Approval Workflow</div>
                            <div class="mt-2 flex items-center gap-3">
                                <ImportBatchStatusBadge :status="batch.approval_workflow?.status ?? 'draft'" />
                                <span class="text-sm text-ehrmis-muted">
                                    Submitted: {{ batch.approval_workflow?.submitted_at ?? 'Not yet submitted' }}
                                </span>
                            </div>
                        </div>

                        <div v-if="batch.approval_workflow?.steps?.length" class="space-y-2">
                            <div
                                v-for="step in batch.approval_workflow.steps"
                                :key="step.step_no"
                                class="rounded-ehrmis border border-ehrmis-line px-4 py-3"
                            >
                                <div class="flex items-center justify-between gap-4">
                                    <div class="text-sm font-semibold text-ehrmis-text">
                                        Step {{ step.step_no }}<span v-if="step.reviewer_role"> · {{ step.reviewer_role }}</span>
                                    </div>
                                    <ImportBatchStatusBadge :status="step.status" />
                                </div>
                                <div class="mt-1 text-sm text-ehrmis-muted">
                                    Acted at: {{ step.acted_at ?? 'Pending' }}
                                </div>
                                <div v-if="step.comment" class="mt-2 text-sm text-ehrmis-text">
                                    {{ step.comment }}
                                </div>
                            </div>
                        </div>

                        <div v-if="batch.approval_workflow?.rejection_comment" class="text-sm text-red-600">
                            Rejection note: {{ batch.approval_workflow.rejection_comment }}
                        </div>
                    </div>

                    <div class="w-full max-w-xl space-y-3">
                        <AppTextarea
                            v-model="approvalDecisionForm.comment"
                            label="Approval Comment"
                            rows="3"
                            placeholder="Optional approval note or required rejection reason."
                            :error="approvalDecisionForm.errors.comment"
                        />

                        <div class="flex flex-wrap gap-3">
                            <AppButton
                                v-if="can.submitApproval && !batch.approval_workflow"
                                :disabled="submitApprovalForm.processing"
                                @click="submitForApproval"
                            >
                                Submit for Approval
                            </AppButton>

                            <AppButton
                                v-if="can.submitApproval && batch.approval_workflow?.status === 'rejected'"
                                :disabled="submitApprovalForm.processing"
                                @click="submitForApproval"
                            >
                                Resubmit for Approval
                            </AppButton>

                            <AppButton
                                v-if="can.approveApproval && ['submitted', 'under_review'].includes(batch.approval_workflow?.status)"
                                :disabled="approvalDecisionForm.processing"
                                @click="approveBatch"
                            >
                                Approve Batch
                            </AppButton>

                            <AppButton
                                v-if="can.rejectApproval && ['submitted', 'under_review'].includes(batch.approval_workflow?.status)"
                                variant="danger"
                                :disabled="approvalDecisionForm.processing"
                                @click="rejectBatch"
                            >
                                Reject Batch
                            </AppButton>
                        </div>
                    </div>
                </div>
            </AppCard>
            <ImportPublicationSummary :summary="latestPublication" />

            <ImportRowFilters
                :batch-id="batch.id"
                :filters="filters"
                :options="filterOptions"
            />

            <ImportRowTable
                :batch-id="batch.id"
                :rows="rows?.data ?? []"
            />

            <div class="flex items-center justify-between gap-4">
                <div class="text-sm text-ehrmis-muted">
                    Showing {{ rows?.from ?? 0 }} to {{ rows?.to ?? 0 }} of {{ rows?.total ?? 0 }} rows.
                </div>

                <AppPagination :links="rows?.links ?? []" />
            </div>
        </div>
    </AppLayout>
</template>
