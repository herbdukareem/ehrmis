<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
import StaffAllowanceCard from '@/Pages/Staff/Components/StaffAllowanceCard.vue';
import StaffBioCard from '@/Pages/Staff/Components/StaffBioCard.vue';
import StaffEmploymentCard from '@/Pages/Staff/Components/StaffEmploymentCard.vue';
import StaffQualificationCard from '@/Pages/Staff/Components/StaffQualificationCard.vue';
import StaffSalaryCard from '@/Pages/Staff/Components/StaffSalaryCard.vue';
import StaffStatusHistoryCard from '@/Pages/Staff/Components/StaffStatusHistoryCard.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    staff: Object,
});
</script>

<template>
    <Head :title="staff.full_name" />

    <AppLayout>
        <AppPageHeader
            :title="staff.full_name"
            :subtitle="`${staff.staff_number} • ${staff.mda?.name ?? 'Unknown MDA'}`"
            :breadcrumbs="[{ label: 'Staff', href: route('staff.index') }, { label: staff.full_name }]"
        >
            <template #actions>
                <div class="flex gap-3">
                    <AppButton :href="route('staff.index')" variant="secondary">Back to Staff</AppButton>
                    <AppButton :href="route('staff.edit', staff.id)">Edit Staff</AppButton>
                </div>
            </template>
        </AppPageHeader>

        <div class="mb-6 flex flex-wrap items-center gap-3">
            <AppStatusBadge :status="staff.status" />
            <span class="text-sm text-ehrmis-muted">Legacy CNO: {{ staff.legacy_cno ?? 'N/A' }}</span>
            <span class="text-sm text-ehrmis-muted">PSN: {{ staff.legacy_psn ?? 'N/A' }}</span>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1.3fr_0.7fr]">
            <div class="space-y-6">
                <StaffBioCard :staff="staff" />
                <StaffEmploymentCard :employment="staff.current_employment" />
                <StaffSalaryCard
                    :placement="staff.current_salary_placement"
                    :salary-summary="staff.salary_summary"
                />
                <StaffQualificationCard :qualifications="staff.qualifications" />
                <StaffAllowanceCard
                    :allowances="staff.allowance_assignments"
                    :import-metadata="staff.import_metadata"
                />
                <StaffStatusHistoryCard :histories="staff.status_histories" />
            </div>

            <div class="space-y-6">
                <AppCard>
                    <div class="text-sm font-semibold text-ehrmis-text">Import Metadata</div>
                    <div class="mt-4 space-y-3 text-sm text-ehrmis-muted">
                        <div class="flex items-center justify-between"><span>Legacy Staff ID</span><span class="font-semibold text-ehrmis-text">{{ staff.legacy_staff_id ?? 'N/A' }}</span></div>
                        <div class="flex items-center justify-between"><span>Legacy Master ID</span><span class="font-semibold text-ehrmis-text">{{ staff.legacy_master_staff_id ?? 'N/A' }}</span></div>
                        <div class="flex items-center justify-between"><span>Latest Batch</span><span class="font-semibold text-ehrmis-text">{{ staff.import_metadata?.latest_batch_id ?? 'N/A' }}</span></div>
                        <div class="flex items-center justify-between"><span>Batch Status</span><span class="font-semibold text-ehrmis-text">{{ staff.import_metadata?.latest_batch_status ?? 'N/A' }}</span></div>
                    </div>
                    <div
                        v-if="staff.import_metadata?.warnings?.length"
                        class="mt-4 space-y-2"
                    >
                        <div class="text-xs font-medium text-ehrmis-muted">Warnings</div>
                        <div
                            v-for="warning in staff.import_metadata.warnings"
                            :key="warning.code + warning.message"
                            class="rounded-ehrmis border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
                        >
                            <div class="font-semibold">{{ warning.code }}</div>
                            <div class="mt-1">{{ warning.message }}</div>
                        </div>
                    </div>
                </AppCard>

                <AppCard>
                    <div class="text-sm font-semibold text-ehrmis-text">Audit Summary</div>
                    <div class="mt-4 text-3xl font-semibold text-ehrmis-text">{{ staff.audit_summary?.count ?? 0 }}</div>
                    <div class="mt-1 text-sm text-ehrmis-muted">Tracked audit entries for this staff record</div>
                    <div class="mt-4 space-y-3">
                        <div
                            v-for="event in staff.audit_summary?.latest_events ?? []"
                            :key="event.event_code + event.occurred_at"
                            class="rounded-ehrmis border border-ehrmis-border px-4 py-3"
                        >
                            <div class="font-semibold text-ehrmis-text">{{ event.event_code }}</div>
                            <div class="mt-1 text-sm text-ehrmis-muted">{{ event.occurred_at }}</div>
                        </div>
                    </div>
                </AppCard>
            </div>
        </div>
    </AppLayout>
</template>
