<script setup>
import StatusPill from '../StatusPill.vue';
import {
    dimensionTotal,
    formatNumber,
    hasMatrixDimensions,
    matrixCellKey,
    matrixColumnDimension,
    matrixGrandTotal,
    matrixRowDimension,
    matrixRowTotal,
    primaryDimension,
    valueKey,
} from '../../lib/serviceReporting';

const props = defineProps({
    draftForm: { type: Object, required: true },
    selectedDraftSubmission: { type: Object, default: null },
    draftCanEdit: { type: Boolean, default: true },
    selectedTemplate: { type: Object, default: null },
    templates: { type: Array, default: () => [] },
    isGlobalUser: { type: Boolean, default: false },
    assignedStation: { type: Object, default: null },
    mdas: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    formErrors: { type: Object, default: () => ({}) },
});

defineEmits(['save-draft', 'submit-return', 'select-template']);

function showFieldErrors(name) {
    return props.formErrors[name] ?? [];
}

function scalarInputType(indicator) {
    if (indicator.field_type === 'date') return 'date';
    if (['text', 'select', 'radio', 'multi_select'].includes(indicator.field_type)) return 'text';

    return 'number';
}

function isInputDisabled(indicator) {
    return !props.draftCanEdit || Boolean(indicator.is_read_only);
}

function toggleMultiOption(indicator, optionValue) {
    const key = valueKey(indicator);
    const values = Array.isArray(props.draftForm.values[key]) ? [...props.draftForm.values[key]] : [];

    props.draftForm.values[key] = values.includes(optionValue)
        ? values.filter((value) => value !== optionValue)
        : [...values, optionValue];
}

function multiOptionChecked(indicator, optionValue) {
    const values = props.draftForm.values[valueKey(indicator)];

    return Array.isArray(values) && values.includes(optionValue);
}

function ruleMatches(rule) {
    if (!rule?.indicator_code || rule.value === undefined || rule.value === null) return true;

    return String(props.draftForm.values[valueKey(rule.indicator_code)] ?? '') === String(rule.value);
}

function indicatorVisible(indicator) {
    return !indicator.display_rules?.indicator_code || ruleMatches(indicator.display_rules);
}

function indicatorRequired(indicator) {
    return Boolean(indicator.is_required) || (indicator.required_rules?.indicator_code && ruleMatches(indicator.required_rules));
}

</script>

<template>
    <form class="civic-reporting-stack" @submit.prevent="$emit('save-draft')">
        <article class="civic-workspace civic-reporting-panel">
            <div class="civic-workspace-header">
                <div>
                    <div class="civic-eyebrow">Monthly return</div>
                    <h2>{{ selectedDraftSubmission ? 'Continue Report' : 'Create New Report' }}</h2>
                    <p class="civic-section-note">Select the reporting context, then enter values by section. Totals are calculated from each indicator’s configured dimensions.</p>
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
                    <select v-model="draftForm.mda_id" :disabled="Boolean(selectedDraftSubmission) || !draftCanEdit || Boolean(assignedStation)">
                        <option v-for="mda in mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </select>
                    <small v-for="message in showFieldErrors('mda_id')" :key="message" class="civic-field-error">{{ message }}</small>
                </label>

                <label class="civic-field">
                    <span>Step 4: Station / Facility</span>
                    <select v-model="draftForm.station_id" :disabled="Boolean(selectedDraftSubmission) || !draftCanEdit || Boolean(assignedStation)">
                        <option value="">MDA-level</option>
                        <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
                    </select>
                    <small v-for="message in showFieldErrors('station_id')" :key="message" class="civic-field-error">{{ message }}</small>
                </label>
            </div>

            <p v-if="assignedStation" class="civic-section-note">
                Reporting is locked to {{ assignedStation.code ? `${assignedStation.code} - ` : '' }}{{ assignedStation.name }} for this account.
            </p>
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
                                <th>Values</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="indicator in section.indicators" v-show="indicatorVisible(indicator)" :key="indicator.id">
                                <td class="civic-primary-cell">
                                    {{ indicator.label }}
                                    <span v-if="indicatorRequired(indicator)" class="civic-required">*</span>
                                    <small v-if="indicator.unit">{{ indicator.unit }}</small>
                                    <small v-if="indicator.help_text">{{ indicator.help_text }}</small>
                                </td>

                                <template v-if="hasMatrixDimensions(indicator)">
                                    <td colspan="2">
                                        <div class="civic-reporting-matrix-wrap">
                                            <table class="civic-reporting-matrix-table">
                                                <thead>
                                                    <tr>
                                                        <th>{{ matrixRowDimension(indicator).dimension_label }}</th>
                                                        <th v-for="columnValue in matrixColumnDimension(indicator).dimension_values" :key="columnValue">{{ columnValue }}</th>
                                                        <th>Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="rowValue in matrixRowDimension(indicator).dimension_values" :key="rowValue">
                                                        <th>{{ rowValue }}</th>
                                                        <td v-for="columnValue in matrixColumnDimension(indicator).dimension_values" :key="columnValue">
                                                            <input
                                                                v-model="draftForm.values[matrixCellKey(indicator, rowValue, columnValue)]"
                                                                class="civic-reporting-value-input"
                                                                type="number"
                                                                min="0"
                                                                :disabled="isInputDisabled(indicator)"
                                                            >
                                                        </td>
                                                        <td><strong>{{ formatNumber(matrixRowTotal(indicator, rowValue, draftForm.values)) }}</strong></td>
                                                    </tr>
                                                </tbody>
                                                <tfoot>
                                                    <tr>
                                                        <th>Total</th>
                                                        <td :colspan="matrixColumnDimension(indicator).dimension_values.length"></td>
                                                        <td><strong>{{ formatNumber(matrixGrandTotal(indicator, draftForm.values)) }}</strong></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </td>
                                </template>

                                <template v-else-if="primaryDimension(indicator)">
                                    <td>
                                        <div class="civic-reporting-dimension-inputs">
                                            <label v-for="dimensionValue in primaryDimension(indicator).dimension_values" :key="dimensionValue" class="civic-field">
                                                <span>{{ dimensionValue }}</span>
                                                <input v-model="draftForm.values[valueKey(indicator, primaryDimension(indicator).dimension_key, dimensionValue)]" class="civic-reporting-value-input" type="number" min="0" :disabled="isInputDisabled(indicator)">
                                            </label>
                                        </div>
                                    </td>
                                    <td><strong>{{ formatNumber(dimensionTotal(indicator, draftForm.values)) }}</strong></td>
                                </template>

                                <template v-else>
                                    <td>
                                        <select v-if="indicator.field_type === 'select'" v-model="draftForm.values[valueKey(indicator)]" class="civic-reporting-value-input civic-reporting-value-input-wide" :disabled="isInputDisabled(indicator)">
                                            <option value="">Choose option</option>
                                            <option v-for="option in indicator.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                                        </select>

                                        <div v-else-if="indicator.field_type === 'radio'" class="civic-reporting-choice-list">
                                            <label v-for="option in indicator.options" :key="option.value" class="civic-check">
                                                <input v-model="draftForm.values[valueKey(indicator)]" type="radio" :value="option.value" :disabled="isInputDisabled(indicator)">
                                                <span>{{ option.label }}</span>
                                            </label>
                                        </div>

                                        <div v-else-if="indicator.field_type === 'multi_select'" class="civic-reporting-choice-list">
                                            <label v-for="option in indicator.options" :key="option.value" class="civic-check">
                                                <input type="checkbox" :checked="multiOptionChecked(indicator, option.value)" :disabled="isInputDisabled(indicator)" @change="toggleMultiOption(indicator, option.value)">
                                                <span>{{ option.label }}</span>
                                            </label>
                                        </div>

                                        <label v-else-if="['boolean', 'checkbox'].includes(indicator.field_type)" class="civic-check">
                                            <input v-model="draftForm.values[valueKey(indicator)]" type="checkbox" :disabled="isInputDisabled(indicator)">
                                            <span>{{ indicator.placeholder || 'Checked' }}</span>
                                        </label>

                                        <input v-else v-model="draftForm.values[valueKey(indicator)]" class="civic-reporting-value-input civic-reporting-value-input-wide" :type="scalarInputType(indicator)" min="0" :placeholder="indicator.placeholder" :disabled="isInputDisabled(indicator)">
                                    </td>
                                    <td>—</td>
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
