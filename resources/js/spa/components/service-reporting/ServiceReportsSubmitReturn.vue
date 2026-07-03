<script setup>
import StatusPill from '../StatusPill.vue';
import {
    dimensionTotal,
    formatNumber,
    primaryDimension,
    sectionHasDimensions,
    sectionPrimaryDimension,
    titleCase,
    valueKey,
} from '../../lib/serviceReporting';

const props = defineProps({
    draftForm: { type: Object, required: true },
    selectedDraftSubmission: { type: Object, default: null },
    draftCanEdit: { type: Boolean, default: true },
    selectedTemplate: { type: Object, default: null },
    templates: { type: Array, default: () => [] },
    isGlobalUser: { type: Boolean, default: false },
    mdas: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    formErrors: { type: Object, default: () => ({}) },
});

defineEmits(['save-draft', 'submit-return', 'select-template']);

function showFieldErrors(name) {
    return props.formErrors[name] ?? [];
}

function sectionColspan(section) {
    return sectionHasDimensions(section) ? ((sectionPrimaryDimension(section)?.dimension_values?.length ?? 0) + 1) : 1;
}
</script>

<template>
    <form class="civic-reporting-stack" @submit.prevent="$emit('save-draft')">
        <article class="civic-workspace civic-reporting-panel">
            <div class="civic-workspace-header">
                <div>
                    <div class="civic-eyebrow">Monthly return</div>
                    <h2>{{ selectedDraftSubmission ? 'Continue monthly return' : 'Submit monthly return' }}</h2>
                    <p class="civic-section-note">Select the reporting context, then enter values by section. Totals are calculated where a template uses dimensions.</p>
                </div>
                <StatusPill v-if="selectedDraftSubmission" :value="selectedDraftSubmission.status" />
            </div>

            <div v-if="!draftCanEdit" class="civic-error">This submission is {{ selectedDraftSubmission.status }} and can no longer be edited.</div>

            <div class="civic-reporting-step-grid">
                <label class="civic-field">
                    <span>Step 1: Select Template</span>
                    <select v-model="draftForm.template_id" :disabled="Boolean(selectedDraftSubmission) || !draftCanEdit" @change="$emit('select-template', draftForm.template_id)">
                        <option value="">Choose template</option>
                        <option v-for="template in templates" :key="template.id" :value="template.id">{{ template.name }}</option>
                    </select>
                    <small v-for="message in showFieldErrors('template_id')" :key="message" class="civic-field-error">{{ message }}</small>
                </label>

                <label class="civic-field">
                    <span>Step 2: Reporting Period</span>
                    <input v-model="draftForm.period" type="month" :disabled="Boolean(selectedDraftSubmission) || !draftCanEdit">
                    <small v-for="message in showFieldErrors('period')" :key="message" class="civic-field-error">{{ message }}</small>
                </label>

                <label v-if="isGlobalUser" class="civic-field">
                    <span>Step 3: MDA</span>
                    <select v-model="draftForm.mda_id" :disabled="Boolean(selectedDraftSubmission) || !draftCanEdit">
                        <option v-for="mda in mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </select>
                    <small v-for="message in showFieldErrors('mda_id')" :key="message" class="civic-field-error">{{ message }}</small>
                </label>

                <label class="civic-field">
                    <span>Step 4: Station / Facility</span>
                    <select v-model="draftForm.station_id" :disabled="Boolean(selectedDraftSubmission) || !draftCanEdit">
                        <option value="">MDA-level</option>
                        <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
                    </select>
                    <small v-for="message in showFieldErrors('station_id')" :key="message" class="civic-field-error">{{ message }}</small>
                </label>
            </div>
        </article>

        <div v-if="!selectedTemplate" class="civic-reporting-empty">
            <strong>No report template selected.</strong>
            <span>Choose a template before entering return values.</span>
        </div>

        <template v-else>
            <details v-for="(section, index) in selectedTemplate.sections" :key="section.id" class="civic-workspace civic-summary-panel civic-reporting-section" :open="index < 2">
                <summary>
                    <div>
                        <div class="civic-eyebrow">Step 5: Report values</div>
                        <h2>{{ section.title }}</h2>
                        <p v-if="section.description" class="civic-section-note">{{ section.description }}</p>
                    </div>
                    <span class="civic-summary-toggle"></span>
                </summary>

                <div class="civic-reporting-section-body">
                    <table class="civic-table civic-reporting-entry-table">
                        <thead>
                            <tr>
                                <th>Indicator</th>
                                <template v-if="sectionHasDimensions(section)">
                                    <th v-for="dimensionValue in sectionPrimaryDimension(section)?.dimension_values ?? []" :key="dimensionValue">
                                        {{ titleCase(dimensionValue) }}
                                    </th>
                                    <th>Total</th>
                                </template>
                                <th v-else>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="indicator in section.indicators" :key="indicator.id">
                                <td class="civic-primary-cell">
                                    {{ indicator.label }}
                                    <span v-if="indicator.is_required" class="civic-required">*</span>
                                    <small v-if="indicator.unit">{{ indicator.unit }}</small>
                                </td>

                                <template v-if="primaryDimension(indicator)">
                                    <td v-for="dimensionValue in primaryDimension(indicator).dimension_values" :key="dimensionValue">
                                        <input v-model="draftForm.values[valueKey(indicator, primaryDimension(indicator).dimension_key, dimensionValue)]" class="civic-reporting-value-input" type="number" min="0" :disabled="!draftCanEdit">
                                    </td>
                                    <td><strong>{{ formatNumber(dimensionTotal(indicator, draftForm.values)) }}</strong></td>
                                </template>

                                <template v-else>
                                    <td :colspan="sectionColspan(section)">
                                        <input v-model="draftForm.values[valueKey(indicator)]" class="civic-reporting-value-input civic-reporting-value-input-wide" :type="indicator.value_type === 'text' ? 'text' : 'number'" min="0" :disabled="!draftCanEdit">
                                    </td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
        </template>

        <div class="civic-reporting-form-actions">
            <button class="civic-button" type="submit" :disabled="busy || !draftCanEdit || !selectedTemplate">Save Draft</button>
            <button class="civic-button civic-button-primary" type="button" :disabled="busy || !draftCanEdit || !selectedTemplate" @click="$emit('submit-return')">Submit</button>
        </div>
    </form>
</template>
