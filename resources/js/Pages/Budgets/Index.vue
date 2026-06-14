<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
import AppTable from '@/Components/AppTable.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    workbooks: Array,
    movementOptions: Array,
});

const form = useForm({
    movement_workbook_id: props.movementOptions?.[0]?.id ?? '',
});

const workbookRows = computed(() => (props.workbooks ?? []).map((workbook) => ([
    `${workbook.mda?.code ?? 'N/A'} ${workbook.year}`,
    workbook.status,
    workbook.approval_workflow?.status ?? 'draft',
    workbook.summary?.line_count ?? 0,
    workbook.summary?.staff_count ?? 0,
])));

const submit = () => form.post(route('budget-workbooks.store'));
</script>

<template>
    <Head title="Budget Workbooks" />

    <AppLayout>
        <AppPageHeader
            title="Budget Workbooks"
            subtitle="Generate recurrent budget snapshots from approved movement workbooks."
        />

        <div class="grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
            <div class="space-y-6">
                <AppTable
                    v-if="workbookRows.length"
                    :headers="['Workbook', 'Status', 'Approval', 'Lines', 'Staff']"
                    :rows="workbookRows"
                />
                <AppEmptyState
                    v-else
                    icon="wallet"
                    title="No budget workbooks yet"
                    message="Approve and lock a movement workbook, then generate a budget workbook from it."
                />

                <div
                    v-if="workbooks?.length"
                    class="grid gap-4"
                >
                    <AppCard
                        v-for="workbook in workbooks"
                        :key="workbook.id"
                    >
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <div class="text-xs font-medium text-ehrmis-muted">
                                    {{ workbook.mda?.code }} {{ workbook.year }}
                                </div>
                                <div class="mt-2 text-xl font-semibold text-ehrmis-text">
                                    Budget Workbook #{{ workbook.id }}
                                </div>
                                <div class="mt-2 flex items-center gap-2">
                                    <AppStatusBadge :status="workbook.status" />
                                    <AppStatusBadge :status="workbook.approval_workflow?.status ?? 'draft'" />
                                    <span class="text-sm text-ehrmis-muted">
                                        From movement workbook #{{ workbook.movement_workbook_id }}
                                    </span>
                                </div>
                            </div>

                            <div class="grid gap-3 text-sm text-ehrmis-muted md:grid-cols-2">
                                <div>
                                    <div class="text-xs font-medium text-ehrmis-muted">Lines</div>
                                    <div class="mt-1 font-semibold text-ehrmis-text">{{ workbook.summary?.line_count ?? 0 }}</div>
                                </div>
                                <div>
                                    <div class="text-xs font-medium text-ehrmis-muted">Staff</div>
                                    <div class="mt-1 font-semibold text-ehrmis-text">{{ workbook.summary?.staff_count ?? 0 }}</div>
                                </div>
                            </div>

                            <AppButton :href="route('budget-workbooks.show', workbook.id)" size="sm">
                                View Budget
                            </AppButton>
                        </div>
                    </AppCard>
                </div>
            </div>

            <div class="space-y-6">
                <AppCard title="Generate Budget Workbook">
                    <form
                        class="space-y-4"
                        @submit.prevent="submit"
                    >
                        <AppSelect v-model="form.movement_workbook_id" label="Approved Movement Workbook">
                            <option
                                v-for="option in movementOptions"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.label }}
                            </option>
                        </AppSelect>

                        <AppButton
                            type="submit"
                            :disabled="form.processing || !form.movement_workbook_id"
                        >
                            Generate Budget Workbook
                        </AppButton>
                    </form>
                </AppCard>
            </div>
        </div>
    </AppLayout>
</template>
