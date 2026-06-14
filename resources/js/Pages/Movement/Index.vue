<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
import AppTable from '@/Components/AppTable.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    workbooks: Array,
    mdaOptions: Array,
    defaultYear: Number,
    defaultMdaId: Number,
});

const form = useForm({
    mda_id: props.defaultMdaId ?? props.mdaOptions?.[0]?.id ?? '',
    year: props.defaultYear ?? new Date().getFullYear(),
});

const workbookRows = computed(() => (props.workbooks ?? []).map((workbook) => ([
    `${workbook.mda?.code ?? 'N/A'} ${workbook.year}`,
    workbook.status,
    workbook.approval_workflow?.status ?? 'draft',
    workbook.summary?.lines_generated ?? 0,
    workbook.summary?.due_for_promotion ?? 0,
    workbook.summary?.retiring_in_year ?? 0,
])));

const totals = computed(() => (props.workbooks ?? []).reduce((carry, workbook) => {
    carry.workbooks += 1;
    carry.lines += Number(workbook.summary?.lines_generated ?? 0);
    carry.due += Number(workbook.summary?.due_for_promotion ?? 0);

    return carry;
}, {
    workbooks: 0,
    lines: 0,
    due: 0,
}));

const submit = () => form.post(route('movement-workbooks.store'));
</script>

<template>
    <Head title="Movement Workbooks" />

    <AppLayout>
        <AppPageHeader
            title="Movement Workbooks"
            subtitle="Generate, review, approve, and lock movement snapshots before budget generation."
        >
            <template #actions>
                <AppButton
                    v-if="workbooks?.[0]"
                    :href="route('movement-workbooks.show', workbooks[0].id)"
                    variant="secondary"
                >
                    Open Latest Workbook
                </AppButton>
            </template>
        </AppPageHeader>

        <div class="grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
            <div class="space-y-6">
                <div class="grid gap-6 md:grid-cols-3">
                    <AppCard>
                        <div class="text-sm text-ehrmis-muted">Workbooks</div>
                        <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ totals.workbooks }}</div>
                    </AppCard>

                    <AppCard>
                        <div class="text-sm text-ehrmis-muted">Movement Lines</div>
                        <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ totals.lines }}</div>
                    </AppCard>

                    <AppCard>
                        <div class="text-sm text-ehrmis-muted">Promotion Due</div>
                        <div class="mt-2 text-3xl font-semibold text-ehrmis-text">{{ totals.due }}</div>
                    </AppCard>
                </div>

                <AppTable
                    v-if="workbookRows.length"
                    :headers="['Workbook', 'Status', 'Approval', 'Lines', 'Due', 'Retiring']"
                    :rows="workbookRows"
                />
                <AppEmptyState
                    v-else
                    icon="arrowsRightLeft"
                    title="No movement workbooks yet"
                    message="Generate the first workbook for an MDA and year to begin the review workflow."
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
                                    Workbook #{{ workbook.id }}
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <AppStatusBadge :status="workbook.status" />
                                    <AppStatusBadge :status="workbook.approval_workflow?.status ?? 'draft'" />
                                    <span class="text-sm text-ehrmis-muted">
                                        {{ workbook.summary?.lines_generated ?? 0 }} lines
                                    </span>
                                </div>
                            </div>

                            <div class="grid gap-3 text-sm text-ehrmis-muted md:grid-cols-3">
                                <div>
                                    <div class="text-xs font-medium text-ehrmis-muted">Due</div>
                                    <div class="mt-1 font-semibold text-ehrmis-text">{{ workbook.summary?.due_for_promotion ?? 0 }}</div>
                                </div>
                                <div>
                                    <div class="text-xs font-medium text-ehrmis-muted">Retiring</div>
                                    <div class="mt-1 font-semibold text-ehrmis-text">{{ workbook.summary?.retiring_in_year ?? 0 }}</div>
                                </div>
                                <div>
                                    <div class="text-xs font-medium text-ehrmis-muted">Blocked</div>
                                    <div class="mt-1 font-semibold text-ehrmis-text">{{ workbook.summary?.blocked ?? 0 }}</div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <AppButton :href="route('movement-workbooks.show', workbook.id)" size="sm">
                                    View Workbook
                                </AppButton>
                            </div>
                        </div>
                    </AppCard>
                </div>
            </div>

            <div class="space-y-6">
                <AppCard title="Generate Workbook">
                    <form
                        class="space-y-4"
                        @submit.prevent="submit"
                    >
                        <AppSelect v-model="form.mda_id" label="MDA">
                            <option
                                v-for="mda in mdaOptions"
                                :key="mda.id"
                                :value="mda.id"
                            >
                                {{ mda.code }} - {{ mda.name }}
                            </option>
                        </AppSelect>

                        <AppTextInput v-model="form.year" type="number" min="2020" max="2100" label="Year" />

                        <AppButton
                            type="submit"
                            :disabled="form.processing"
                        >
                            Generate Workbook
                        </AppButton>
                    </form>
                </AppCard>

                <AppCard v-if="workbooks?.[0]" title="Latest Snapshot">
                    <div class="text-2xl font-semibold text-ehrmis-text">
                        {{ workbooks[0].mda?.code }} {{ workbooks[0].year }}
                    </div>
                    <div class="mt-3 flex items-center gap-2">
                        <AppStatusBadge :status="workbooks[0].status" />
                    </div>
                    <div class="mt-5 space-y-3 text-sm text-ehrmis-muted">
                        <div class="flex items-center justify-between">
                            <span>Due for promotion</span>
                            <span class="font-semibold text-ehrmis-text">{{ workbooks[0].summary?.due_for_promotion ?? 0 }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Retiring in year</span>
                            <span class="font-semibold text-ehrmis-text">{{ workbooks[0].summary?.retiring_in_year ?? 0 }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Lines generated</span>
                            <span class="font-semibold text-ehrmis-text">{{ workbooks[0].summary?.lines_generated ?? 0 }}</span>
                        </div>
                    </div>
                </AppCard>
            </div>
        </div>
    </AppLayout>
</template>
