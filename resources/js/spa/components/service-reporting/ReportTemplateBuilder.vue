<script setup>
import { reactive, ref } from 'vue';
import AppModal from '../AppModal.vue';
import { api, apiMessage } from '../../lib/api';
import { titleCase } from '../../lib/serviceReporting';
import { pushToast } from '../../stores/app';

const props = defineProps({
    template: { type: Object, required: true },
});

const emit = defineEmits(['changed']);

const busy = ref(false);
const sectionModalOpen = ref(false);
const indicatorModalOpen = ref(false);
const editingSection = ref(null);
const editingIndicator = ref(null);
const targetSection = ref(null);

const sectionForm = reactive({
    title: '',
    code: '',
    description: '',
    sort_order: 10,
});

const indicatorForm = reactive({
    label: '',
    code: '',
    description: '',
    value_type: 'integer',
    unit: '',
    is_required: true,
    is_computed: false,
    sort_order: 10,
    status: 'active',
    dimensions: [],
});

function resetSectionForm() {
    editingSection.value = null;
    Object.assign(sectionForm, { title: '', code: '', description: '', sort_order: 10 });
}

function openSectionModal(section = null) {
    resetSectionForm();
    sectionModalOpen.value = true;

    if (section) {
        editSection(section);
    }
}

function closeSectionModal() {
    sectionModalOpen.value = false;
    resetSectionForm();
}

function editSection(section) {
    editingSection.value = section;
    Object.assign(sectionForm, {
        title: section.title,
        code: section.code,
        description: section.description ?? '',
        sort_order: section.sort_order ?? 10,
    });
}

async function saveSection() {
    busy.value = true;

    try {
        const endpoint = editingSection.value
            ? api.put(`/service-reports/templates/${props.template.id}/sections/${editingSection.value.id}`, sectionForm)
            : api.post(`/service-reports/templates/${props.template.id}/sections`, sectionForm);
        const response = await endpoint;
        pushToast(response.data.message);
        closeSectionModal();
        emit('changed');
    } catch (error) {
        pushToast(apiMessage(error), 'error', 4200);
    } finally {
        busy.value = false;
    }
}

async function deleteSection(section) {
    if (!window.confirm(`Delete section "${section.title}" and its indicators?`)) return;
    busy.value = true;

    try {
        const response = await api.delete(`/service-reports/templates/${props.template.id}/sections/${section.id}`);
        pushToast(response.data.message);
        emit('changed');
    } catch (error) {
        pushToast(apiMessage(error), 'error', 4200);
    } finally {
        busy.value = false;
    }
}

function resetIndicatorForm(section = null) {
    editingIndicator.value = null;
    targetSection.value = section;
    Object.assign(indicatorForm, {
        label: '',
        code: '',
        description: '',
        value_type: 'integer',
        unit: '',
        is_required: true,
        is_computed: false,
        sort_order: 10,
        status: 'active',
        dimensions: [],
    });
}

function openIndicatorModal(section = null, indicator = null) {
    resetIndicatorForm(section);
    indicatorModalOpen.value = true;

    if (section && indicator) {
        editIndicator(section, indicator);
    }
}

function closeIndicatorModal() {
    indicatorModalOpen.value = false;
    resetIndicatorForm(null);
}

function editIndicator(section, indicator) {
    targetSection.value = section;
    editingIndicator.value = indicator;
    Object.assign(indicatorForm, {
        label: indicator.label,
        code: indicator.code,
        description: indicator.description ?? '',
        value_type: indicator.value_type,
        unit: indicator.unit ?? '',
        is_required: Boolean(indicator.is_required),
        is_computed: Boolean(indicator.is_computed),
        sort_order: indicator.sort_order ?? 10,
        status: indicator.status ?? 'active',
        dimensions: indicator.dimensions.map((dimension) => ({
            dimension_key: dimension.dimension_key,
            dimension_label: dimension.dimension_label,
            values_text: dimension.dimension_values.join(', '),
            is_required: Boolean(dimension.is_required),
            total_strategy: dimension.total_strategy ?? 'none',
            sort_order: dimension.sort_order ?? 10,
        })),
    });
}

function addDimension() {
    indicatorForm.dimensions.push({
        dimension_key: '',
        dimension_label: '',
        values_text: '',
        is_required: true,
        total_strategy: 'sum_values',
        sort_order: (indicatorForm.dimensions.length + 1) * 10,
    });
}

function removeDimension(index) {
    indicatorForm.dimensions.splice(index, 1);
}

function indicatorPayload() {
    return {
        label: indicatorForm.label,
        code: indicatorForm.code,
        description: indicatorForm.description || null,
        value_type: indicatorForm.value_type,
        unit: indicatorForm.unit || null,
        is_required: indicatorForm.is_required,
        is_computed: indicatorForm.is_computed,
        sort_order: indicatorForm.sort_order || 10,
        status: indicatorForm.status,
        dimensions: indicatorForm.dimensions
            .filter((dimension) => dimension.dimension_key && dimension.dimension_label && dimension.values_text)
            .map((dimension) => ({
                dimension_key: dimension.dimension_key,
                dimension_label: dimension.dimension_label,
                dimension_values: dimension.values_text.split(',').map((value) => value.trim()).filter(Boolean),
                is_required: dimension.is_required,
                total_strategy: dimension.total_strategy,
                sort_order: dimension.sort_order || 10,
            })),
    };
}

async function saveIndicator() {
    if (!targetSection.value) return;
    busy.value = true;

    try {
        const payload = indicatorPayload();
        const endpoint = editingIndicator.value
            ? api.put(`/service-reports/templates/${props.template.id}/sections/${targetSection.value.id}/indicators/${editingIndicator.value.id}`, payload)
            : api.post(`/service-reports/templates/${props.template.id}/sections/${targetSection.value.id}/indicators`, payload);
        const response = await endpoint;
        pushToast(response.data.message);
        closeIndicatorModal();
        emit('changed');
    } catch (error) {
        pushToast(apiMessage(error), 'error', 4200);
    } finally {
        busy.value = false;
    }
}

async function deleteIndicator(section, indicator) {
    if (!window.confirm(`Delete indicator "${indicator.label}"?`)) return;
    busy.value = true;

    try {
        const response = await api.delete(`/service-reports/templates/${props.template.id}/sections/${section.id}/indicators/${indicator.id}`);
        pushToast(response.data.message);
        emit('changed');
    } catch (error) {
        pushToast(apiMessage(error), 'error', 4200);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <article class="civic-workspace civic-reporting-panel">
        <div class="civic-workspace-header">
            <div>
                <div class="civic-eyebrow">Template builder</div>
                <h2>{{ template.name }}</h2>
                <p class="civic-section-note">Build sections, indicators, and optional dimensions for this generic reporting template.</p>
            </div>
            <div class="civic-page-actions">
                <button class="civic-button civic-button-primary" type="button" @click="openSectionModal()">Add Section</button>
            </div>
        </div>

        <div class="civic-reporting-builder">
            <section>
                <h3>Current structure</h3>
                <div class="civic-reporting-builder-list">
                    <details v-for="section in template.sections" :key="section.id" class="civic-reporting-builder-card" open>
                        <summary class="civic-reporting-builder-row">
                            <span>
                                <strong>{{ section.title }}</strong>
                                <small>{{ section.code }} / {{ section.indicators.length }} indicators</small>
                            </span>
                            <span class="civic-table-actions">
                                <button class="civic-button civic-button-compact" type="button" @click.stop="openSectionModal(section)">Edit</button>
                                <button class="civic-button civic-button-compact" type="button" @click.stop="openIndicatorModal(section)">Add Indicator</button>
                                <button class="civic-button civic-button-compact civic-button-danger" type="button" @click.stop="deleteSection(section)">Delete</button>
                            </span>
                        </summary>

                        <ul v-if="section.indicators.length">
                            <li v-for="indicator in section.indicators" :key="indicator.id">
                                <span>
                                    {{ indicator.label }}
                                    <em>{{ titleCase(indicator.value_type) }}</em>
                                </span>
                                <span class="civic-table-actions">
                                    <button class="civic-button civic-button-compact" type="button" @click="openIndicatorModal(section, indicator)">Edit</button>
                                    <button class="civic-button civic-button-compact civic-button-danger" type="button" @click="deleteIndicator(section, indicator)">Delete</button>
                                </span>
                            </li>
                        </ul>

                        <div v-else class="civic-reporting-builder-empty">
                            No indicators in this section yet.
                        </div>
                    </details>

                    <div v-if="!template.sections.length" class="civic-reporting-empty">
                        <strong>No sections yet.</strong>
                        <span>Add a section to start building this report template.</span>
                    </div>
                </div>
            </section>
        </div>
    </article>

    <AppModal
        :open="sectionModalOpen"
        eyebrow="Template builder"
        :title="editingSection ? 'Edit Section' : 'Add Section'"
        description="Sections group related indicators in the reporting form."
        @close="closeSectionModal"
    >
        <form class="civic-form-grid civic-dialog-form" @submit.prevent="saveSection">
            <label class="civic-field"><span>Title</span><input v-model="sectionForm.title" type="text"></label>
            <label class="civic-field"><span>Code</span><input v-model="sectionForm.code" type="text"></label>
            <label class="civic-field"><span>Sort Order</span><input v-model="sectionForm.sort_order" type="number" min="0"></label>
            <label class="civic-field civic-field-wide"><span>Description</span><textarea v-model="sectionForm.description" rows="3"></textarea></label>
        </form>

        <template #actions>
            <button class="civic-button" type="button" :disabled="busy" @click="closeSectionModal">Cancel</button>
            <button class="civic-button civic-button-primary" type="button" :disabled="busy" @click="saveSection">{{ editingSection ? 'Save Section' : 'Add Section' }}</button>
        </template>
    </AppModal>

    <AppModal
        :open="indicatorModalOpen"
        eyebrow="Template builder"
        :title="editingIndicator ? 'Edit Indicator' : 'Add Indicator'"
        description="Indicators define the data fields captured under a section."
        size="wide"
        @close="closeIndicatorModal"
    >
        <form class="civic-form-grid civic-dialog-form" @submit.prevent="saveIndicator">
            <label class="civic-field">
                <span>Section</span>
                <select v-model="targetSection">
                    <option :value="null">Choose section</option>
                    <option v-for="section in template.sections" :key="section.id" :value="section">{{ section.title }}</option>
                </select>
            </label>
            <label class="civic-field"><span>Label</span><input v-model="indicatorForm.label" type="text"></label>
            <label class="civic-field"><span>Code</span><input v-model="indicatorForm.code" type="text"></label>
            <label class="civic-field">
                <span>Value Type</span>
                <select v-model="indicatorForm.value_type">
                    <option value="integer">Integer</option>
                    <option value="decimal">Decimal</option>
                    <option value="percentage">Percentage</option>
                    <option value="text">Text</option>
                    <option value="boolean">Boolean</option>
                </select>
            </label>
            <label class="civic-field"><span>Unit</span><input v-model="indicatorForm.unit" type="text"></label>
            <label class="civic-field"><span>Sort Order</span><input v-model="indicatorForm.sort_order" type="number" min="0"></label>
            <label class="civic-field">
                <span>Status</span>
                <select v-model="indicatorForm.status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </label>
            <label class="civic-check civic-check-card"><input v-model="indicatorForm.is_required" type="checkbox"><span class="civic-check-copy"><strong>Required</strong></span></label>
            <label class="civic-check civic-check-card"><input v-model="indicatorForm.is_computed" type="checkbox"><span class="civic-check-copy"><strong>Computed</strong></span></label>

            <div class="civic-field-wide civic-reporting-dimensions">
                <div class="civic-section-heading">
                    <h2>Dimensions</h2>
                    <button class="civic-button" type="button" @click="addDimension">Add Dimension</button>
                </div>
                <div v-for="(dimension, index) in indicatorForm.dimensions" :key="index" class="civic-reporting-dimension-row">
                    <label class="civic-field"><span>Key</span><input v-model="dimension.dimension_key" type="text" placeholder="sex"></label>
                    <label class="civic-field"><span>Label</span><input v-model="dimension.dimension_label" type="text" placeholder="Sex"></label>
                    <label class="civic-field"><span>Values</span><input v-model="dimension.values_text" type="text" placeholder="male, female"></label>
                    <label class="civic-field">
                        <span>Total Strategy</span>
                        <select v-model="dimension.total_strategy">
                            <option value="none">None</option>
                            <option value="sum_values">Sum values</option>
                            <option value="manual">Manual</option>
                        </select>
                    </label>
                    <button class="civic-button" type="button" @click="removeDimension(index)">Remove</button>
                </div>
            </div>
        </form>

        <template #actions>
            <button class="civic-button" type="button" :disabled="busy" @click="closeIndicatorModal">Cancel</button>
            <button class="civic-button civic-button-primary" type="button" :disabled="busy || !targetSection" @click="saveIndicator">{{ editingIndicator ? 'Save Indicator' : 'Add Indicator' }}</button>
        </template>
    </AppModal>
</template>
