<script setup>
import AppCard from '@/Components/AppCard.vue';
import IgnoreWarningButton from '@/Pages/LegacyStaffImports/Components/IgnoreWarningButton.vue';

defineProps({
    batchId: {
        type: Number,
        required: true,
    },
    row: {
        type: Object,
        required: true,
    },
    can: {
        type: Object,
        default: () => ({}),
    },
});
</script>

<template>
    <AppCard title="Issues">
        <div
            v-if="row.issue_summary?.unresolved_call_allowance"
            class="rounded-ehrmis border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800"
        >
            <div class="font-semibold">Call allowance requires resolution</div>
            <div class="mt-2">
                The legacy row indicates a call allowance, but the exact canonical target is still not confirmed.
            </div>
            <div class="mt-2 text-xs font-medium text-amber-700">
                Possible types: call_doctor, call_pharm_lab, call_opt_odd, call_nurse_others
            </div>
        </div>

        <div class="mt-5 space-y-4">
            <div>
                <div class="text-xs font-medium text-ehrmis-muted">Warnings</div>
                <div
                    v-if="row.warnings?.length"
                    class="mt-3 space-y-3"
                >
                    <div
                        v-for="warning in row.warnings"
                        :key="warning.id"
                        class="rounded-ehrmis border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900"
                    >
                        <div class="font-semibold">{{ warning.error_code }}</div>
                        <div class="mt-1">{{ warning.message }}</div>
                        <div
                            v-if="warning.ignored_at"
                            class="mt-2 text-xs text-amber-700"
                        >
                            Reviewed at {{ warning.ignored_at }}
                        </div>
                        <div
                            v-else-if="can.ignoreWarnings"
                            class="mt-3"
                        >
                            <IgnoreWarningButton
                                :batch-id="batchId"
                                :row-id="row.id"
                                :warning-id="warning.id"
                            />
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="mt-3 text-sm text-ehrmis-muted"
                >
                    No warnings on this row.
                </div>
            </div>

            <div>
                <div class="text-xs font-medium text-ehrmis-muted">Blocking Errors</div>
                <div
                    v-if="row.errors?.length"
                    class="mt-3 space-y-3"
                >
                    <div
                        v-for="error in row.errors"
                        :key="error.id"
                        class="rounded-ehrmis border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-900"
                    >
                        <div class="font-semibold">{{ error.error_code }}</div>
                        <div class="mt-1">{{ error.message }}</div>
                    </div>
                </div>
                <div
                    v-else
                    class="mt-3 text-sm text-ehrmis-muted"
                >
                    No blocking errors on this row.
                </div>
            </div>
        </div>
    </AppCard>
</template>
