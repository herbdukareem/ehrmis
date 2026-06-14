<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppPagination from '@/Components/AppPagination.vue';
import StaffFilters from '@/Pages/Staff/Components/StaffFilters.vue';
import StaffTable from '@/Pages/Staff/Components/StaffTable.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    staff: Object,
    filters: Object,
    filterOptions: Object,
});
</script>

<template>
    <Head title="Staff" />

    <AppLayout>
        <AppPageHeader
            title="Staff Management"
            subtitle="Browse, search, filter, and maintain the imported staff registry."
        >
            <template #actions>
                <AppButton :href="route('staff.create')">Create Staff</AppButton>
            </template>
        </AppPageHeader>

        <div class="space-y-6">
            <StaffFilters
                :filters="filters"
                :options="filterOptions"
            />

            <StaffTable
                v-if="staff?.data?.length"
                :rows="staff.data"
            />
            <AppEmptyState
                v-else
                icon="users"
                title="No staff records matched"
                message="Try broadening your filters or create a new staff record."
            />

            <div class="flex items-center justify-between gap-4">
                <div class="text-sm text-ehrmis-muted">
                    Showing {{ staff?.from ?? 0 }} to {{ staff?.to ?? 0 }} of {{ staff?.total ?? 0 }} staff records.
                </div>

                <AppPagination :links="staff?.links ?? []" />
            </div>
        </div>
    </AppLayout>
</template>
