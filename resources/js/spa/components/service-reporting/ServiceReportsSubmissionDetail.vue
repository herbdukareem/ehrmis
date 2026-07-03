<script setup>
import StatusPill from '../StatusPill.vue';
import { formatNumber, titleCase } from '../../lib/serviceReporting';

defineProps({
    submission: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    canReview: { type: Boolean, default: false },
    canApprove: { type: Boolean, default: false },
    canLock: { type: Boolean, default: false },
    canExport: { type: Boolean, default: false },
});

defineEmits(['workflow', 'continue-draft']);
</script>

<template>
    <section class="civic-reporting-stack">
        <article class="civic-workspace civic-reporting-panel">
            <div class="civic-workspace-header">
                <div>
                    <div class="civic-eyebrow">{{ submission.period?.label }}</div>
                    <h2>{{ submission.template?.name }}</h2>
                    <p class="civic-section-note">{{ submission.mda?.name }} / {{ submission.station?.name ?? 'MDA-level' }}</p>
                </div>
                <StatusPill :value="submission.status" />
            </div>

            <div class="civic-reporting-detail-actions">
                <button v-if="['draft', 'returned'].includes(submission.status)" class="civic-button" type="button" @click="$emit('continue-draft', submission)">Continue draft</button>
                <button v-if="['draft', 'returned'].includes(submission.status)" class="civic-button civic-button-primary" :disabled="busy" @click="$emit('workflow', 'submit')">Submit</button>
                <button v-if="canReview && submission.status === 'submitted'" class="civic-button" :disabled="busy" @click="$emit('workflow', 'review')">Mark review</button>
                <button v-if="canReview && ['submitted', 'under_review'].includes(submission.status)" class="civic-button" :disabled="busy" @click="$emit('workflow', 'return')">Return</button>
                <button v-if="canApprove && ['submitted', 'under_review'].includes(submission.status)" class="civic-button civic-button-primary" :disabled="busy" @click="$emit('workflow', 'approve')">Approve</button>
                <button v-if="canLock && submission.status === 'approved'" class="civic-button civic-button-primary" :disabled="busy" @click="$emit('workflow', 'lock')">Lock</button>
                <a v-if="canExport" class="civic-button" :href="`/api/service-reports/submissions/${submission.id}/export`" target="_blank" rel="noopener">Export</a>
            </div>
        </article>

        <article v-for="section in submission.template_detail.sections" :key="section.id" class="civic-workspace civic-reporting-panel">
            <div class="civic-section-heading">
                <div>
                    <div class="civic-eyebrow">Return values</div>
                    <h2>{{ section.title }}</h2>
                </div>
            </div>
            <table class="civic-table">
                <thead>
                    <tr>
                        <th>Indicator</th>
                        <th>Values</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="indicator in section.indicators" :key="indicator.id">
                        <td class="civic-primary-cell">{{ indicator.label }}</td>
                        <td>
                            <span v-for="value in submission.values.filter((row) => row.indicator_code === indicator.code)" :key="`${value.indicator_code}-${value.dimension_value ?? 'value'}`" class="civic-value-token">
                                {{ value.dimension_value ? `${titleCase(value.dimension_value)}: ` : '' }}{{ formatNumber(value.value) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </article>
    </section>
</template>
