<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppFilterBar from '@/Components/AppFilterBar.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppDateInput from '@/Components/AppDateInput.vue';
import ImportBatchStatusBadge from '@/Pages/LegacyStaffImports/Components/ImportBatchStatusBadge.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    batches: Object,
    filters: Object,
    filterOptions: Object,
});

const form = reactive({
    status: props.filters?.status ?? '',
    source_table: props.filters?.source_table ?? '',
    date_from: props.filters?.date_from ?? '',
    date_to: props.filters?.date_to ?? '',
    per_page: props.filters?.per_page ?? 15,
});

const submit = () => {
    router.get(route('legacy-staff-imports.index'), { ...form }, {
        preserveScroll: true,
        preserveState: true,
    });
};
</script>

<template>
    <Head title="Legacy Staff Imports" />

    <AppLayout>
        <AppPageHeader
            title="Legacy Staff Import Management"
            subtitle="Review staged legacy staff batches, inspect issues, and publish approved records safely."
        />

        <div class="space-y-6">
            <AppFilterBar title="Filters" subtitle="Narrow the list of import batches.">
                <AppSelect v-model="form.status" label="Status" placeholder="All statuses">
                    <option v-for="status in filterOptions.statuses ?? []" :key="status" :value="status">{{ status }}</option>
                </AppSelect>

                <AppSelect v-model="form.source_table" label="Source Table" placeholder="All sources">
                    <option v-for="source in filterOptions.source_tables ?? []" :key="source" :value="source">{{ source }}</option>
                </AppSelect>

                <AppDateInput v-model="form.date_from" label="Date From" />
                <AppDateInput v-model="form.date_to" label="Date To" />

                <template #actions>
                    <AppButton @click="submit">Apply Filters</AppButton>
                </template>
            </AppFilterBar>

            <div
                v-if="batches?.data?.length"
                class="grid gap-4"
            >
                <AppCard
                    v-for="batch in batches.data"
                    :key="batch.id"
                >
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <div class="text-xs font-medium text-ehrmis-muted">{{ batch.source_database }}</div>
                            <div class="mt-2 text-xl font-semibold text-ehrmis-text">Batch #{{ batch.id }}</div>
                            <div class="mt-1 text-sm text-ehrmis-muted">{{ batch.source_table }}</div>
                            <div class="mt-3">
                                <ImportBatchStatusBadge :status="batch.status" />
                            </div>
                        </div>

                        <div class="grid gap-3 text-sm text-ehrmis-muted md:grid-cols-4">
                            <div>
                                <div class="text-xs font-medium text-ehrmis-muted">Started</div>
                                <div class="mt-1 font-semibold text-ehrmis-text">{{ batch.started_at ?? 'N/A' }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium text-ehrmis-muted">Rows Staged</div>
                                <div class="mt-1 font-semibold text-ehrmis-text">{{ batch.rows_staged }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium text-ehrmis-muted">Rows Published</div>
                                <div class="mt-1 font-semibold text-ehrmis-text">{{ batch.rows_published }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium text-ehrmis-muted">Warnings / Errors</div>
                                <div class="mt-1 font-semibold text-ehrmis-text">{{ batch.warnings_count }} / {{ batch.errors_count }}</div>
                            </div>
                        </div>

                        <div class="flex items-center">
                            <AppButton :href="route('legacy-staff-imports.show', batch.id)" size="sm">
                                View Batch
                            </AppButton>
                        </div>
                    </div>
                </AppCard>
            </div>
            <AppEmptyState
                v-else
                icon="upload"
                title="No import batches found"
                message="Run the legacy staff import command or broaden the current filters."
            />

            <div class="flex items-center justify-between gap-4">
                <div class="text-sm text-ehrmis-muted">
                    Showing {{ batches?.from ?? 0 }} to {{ batches?.to ?? 0 }} of {{ batches?.total ?? 0 }} batches.
                </div>

                <AppPagination :links="batches?.links ?? []" />
            </div>
        </div>
    </AppLayout>
</template>
