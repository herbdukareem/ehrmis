<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import ImportBatchStatusBadge from '@/Pages/LegacyStaffImports/Components/ImportBatchStatusBadge.vue';
import ImportRowIssuePanel from '@/Pages/LegacyStaffImports/Components/ImportRowIssuePanel.vue';
import ImportRowPayloadViewer from '@/Pages/LegacyStaffImports/Components/ImportRowPayloadViewer.vue';
import ResolveMappingModal from '@/Pages/LegacyStaffImports/Components/ResolveMappingModal.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    batch: Object,
    row: Object,
    summary: Object,
    mappingOptions: Object,
    can: Object,
});

const publishForm = useForm({});

const publishRow = () => publishForm.post(route('legacy-staff-imports.rows.publish', [props.batch.id, props.row.id]));
</script>

<template>
    <Head :title="row.full_name ?? `Import Row #${row.id}`" />

    <AppLayout>
        <AppPageHeader
            :title="row.full_name ?? `Import Row #${row.id}`"
            :subtitle="`${row.staff_number ?? row.dedupe_key} | Batch #${batch.id}`"
            :breadcrumbs="[{ label: 'Legacy Staff Imports', href: route('legacy-staff-imports.index') }, { label: `Batch #${batch.id}`, href: route('legacy-staff-imports.show', batch.id) }, { label: row.full_name ?? `Row #${row.id}` }]"
        >
            <template #actions>
                <div class="flex flex-wrap gap-3">
                    <AppButton :href="route('legacy-staff-imports.show', batch.id)" variant="secondary">Back to Batch</AppButton>
                    <AppButton
                        v-if="can.publish"
                        :disabled="publishForm.processing || row.publication_status?.is_published"
                        @click="publishRow"
                    >
                        {{ row.publication_status?.is_published ? 'Already Published' : 'Publish Row' }}
                    </AppButton>
                </div>
            </template>
        </AppPageHeader>

        <div class="space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <ImportBatchStatusBadge :status="row.status" />
                <span class="text-sm text-ehrmis-muted">{{ row.mda?.name ?? 'Unresolved MDA' }}</span>
                <span class="text-sm text-ehrmis-muted">{{ row.salary_scale?.code ?? 'N/A' }} L{{ row.level ?? 'N/A' }} S{{ row.step ?? 'N/A' }}</span>
            </div>

            <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                <div class="space-y-6">
                    <AppCard title="Staff Summary">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <div class="text-xs font-medium text-ehrmis-muted">Department</div>
                                <div class="mt-1 font-semibold text-ehrmis-text">{{ row.department?.name ?? 'Unresolved' }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium text-ehrmis-muted">Station</div>
                                <div class="mt-1 font-semibold text-ehrmis-text">{{ row.station?.name ?? 'Unresolved' }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium text-ehrmis-muted">Cadre</div>
                                <div class="mt-1 font-semibold text-ehrmis-text">{{ row.cadre?.name ?? 'Unresolved' }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium text-ehrmis-muted">Rank</div>
                                <div class="mt-1 font-semibold text-ehrmis-text">{{ row.rank?.name ?? 'Unresolved' }}</div>
                            </div>
                        </div>
                    </AppCard>

                    <ImportRowIssuePanel
                        :batch-id="batch.id"
                        :row="row"
                        :can="can"
                    />

                    <ImportRowPayloadViewer
                        title="Normalized Payload"
                        :payload="row.normalized_payload"
                    />
                    <ImportRowPayloadViewer
                        title="Raw Payload"
                        :payload="row.raw_payload"
                    />
                </div>

                <div class="space-y-6">
                    <AppCard title="Publication Status">
                        <div class="space-y-3 text-sm text-ehrmis-muted">
                            <div class="flex items-center justify-between">
                                <span>Published</span>
                                <span class="font-semibold text-ehrmis-text">{{ row.publication_status?.is_published ? 'Yes' : 'No' }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Matched Staff</span>
                                <span class="font-semibold text-ehrmis-text">{{ row.publication_status?.matched_staff?.staff_number ?? 'None' }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Published Staff</span>
                                <span class="font-semibold text-ehrmis-text">{{ row.publication_status?.published_staff?.staff_number ?? 'None' }}</span>
                            </div>
                        </div>
                    </AppCard>

                    <ResolveMappingModal
                        v-if="can.resolve"
                        :batch-id="batch.id"
                        :row-id="row.id"
                        :options="mappingOptions"
                    />

                    <AppCard title="Audit History">
                        <div
                            v-if="row.audit_history?.length"
                            class="space-y-3"
                        >
                            <div
                                v-for="audit in row.audit_history"
                                :key="`${audit.event_code}-${audit.occurred_at}`"
                                class="rounded-ehrmis border border-ehrmis-border px-4 py-3"
                            >
                                <div class="font-semibold text-ehrmis-text">{{ audit.event_code }}</div>
                                <div class="mt-1 text-sm text-ehrmis-muted">{{ audit.occurred_at }}</div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="text-sm text-ehrmis-muted"
                        >
                            No audit history for this row yet.
                        </div>
                    </AppCard>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
