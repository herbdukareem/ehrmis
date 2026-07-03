<script setup>
import { ref } from 'vue';
import DataTable from '../DataTable.vue';
import { api, apiMessage } from '../../lib/api';
import { assignmentSummary, titleCase } from '../../lib/serviceReporting';
import { pushToast } from '../../stores/app';
import ReportTemplateAssignmentPanel from './ReportTemplateAssignmentPanel.vue';
import ReportTemplateBuilder from './ReportTemplateBuilder.vue';
import ReportTemplateDetailPanel from './ReportTemplateDetailPanel.vue';
import ReportTemplateFormModal from './ReportTemplateFormModal.vue';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    selectedTemplate: { type: Object, default: null },
    mdas: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    isGlobalUser: { type: Boolean, default: false },
    canManageTemplates: { type: Boolean, default: false },
    canAssignTemplates: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['view-template', 'activate-template', 'refresh-templates', 'template-created', 'refresh-template']);

const activeMode = ref('detail');
const createOpen = ref(false);
const createBusy = ref(false);
const createErrors = ref({});

const templateColumns = [
    { key: 'name', label: 'Template Name' },
    { key: 'code', label: 'Code' },
    { key: 'owner', label: 'Owner MDA' },
    { key: 'frequency', label: 'Frequency' },
    { key: 'status', label: 'Status' },
    { key: 'assignments', label: 'Assigned Facilities / Stations' },
    { key: 'actions', label: 'Actions' },
];

function openTemplate(id, mode = 'detail') {
    activeMode.value = mode;
    emit('view-template', id);
}

function confirmActivate(template, active) {
    if (!active && !window.confirm('Deactivate this report template? Facilities will no longer submit new returns using it.')) {
        return;
    }

    emit('activate-template', template, active);
}

async function createTemplate(payload) {
    createBusy.value = true;
    createErrors.value = {};

    try {
        const response = await api.post('/service-reports/templates', payload);
        createOpen.value = false;
        pushToast(response.data.message);
        activeMode.value = 'builder';
        emit('template-created', response.data.data.id);
    } catch (error) {
        createErrors.value = error.response?.data?.errors ?? {};
        pushToast(apiMessage(error), 'error', 4200);
    } finally {
        createBusy.value = false;
    }
}

async function saveAssignments(rows) {
    if (!props.selectedTemplate) return;
    createBusy.value = true;

    try {
        const response = await api.put(`/service-reports/templates/${props.selectedTemplate.id}/assignments`, { assignments: rows });
        pushToast(response.data.message);
        emit('refresh-templates');
        emit('refresh-template', props.selectedTemplate.id);
    } catch (error) {
        pushToast(apiMessage(error), 'error', 4200);
    } finally {
        createBusy.value = false;
    }
}
</script>

<template>
    <section class="civic-reporting-stack">
        <article class="civic-workspace civic-reporting-panel">
            <div class="civic-workspace-header">
                <div>
                    <div class="civic-eyebrow">Template library</div>
                    <h2>Report templates</h2>
                    <p class="civic-section-note">Reusable service reporting templates assigned to your visible MDA workspace.</p>
                </div>
                <div class="civic-page-actions">
                    <button v-if="canManageTemplates" class="civic-button civic-button-primary" type="button" @click="createOpen = true">Create Template</button>
                    <button class="civic-button" type="button" @click="$emit('refresh-templates')">Refresh</button>
                </div>
            </div>

            <DataTable v-if="templates.length" :columns="templateColumns" :rows="templates">
                <template #name="{ row }">
                    <strong>{{ row.name }}</strong>
                    <small>{{ row.description }}</small>
                </template>
                <template #owner="{ row }">{{ row.owner_mda?.code ?? 'Platform' }}</template>
                <template #frequency="{ row }">{{ titleCase(row.frequency) }}</template>
                <template #status="{ row }"><StatusPill :value="row.status" /></template>
                <template #assignments="{ row }">{{ assignmentSummary(row) }}</template>
                <template #actions="{ row }">
                    <div class="civic-table-actions">
                        <button class="civic-button civic-button-compact" type="button" @click="openTemplate(row.id, 'detail')">View</button>
                        <button v-if="canManageTemplates" class="civic-button civic-button-compact" type="button" @click="openTemplate(row.id, 'builder')">Builder</button>
                        <button v-if="canAssignTemplates" class="civic-button civic-button-compact" type="button" @click="openTemplate(row.id, 'assign')">Assign</button>
                        <button v-if="canManageTemplates && row.status !== 'active'" class="civic-button civic-button-compact" type="button" :disabled="busy" @click="confirmActivate(row, true)">Activate</button>
                        <button v-if="canManageTemplates && row.status === 'active'" class="civic-button civic-button-compact" type="button" :disabled="busy" @click="confirmActivate(row, false)">Deactivate</button>
                    </div>
                </template>
            </DataTable>

            <div v-else class="civic-reporting-empty">
                <strong>No report templates available for your MDA.</strong>
                <span>Assigned templates will appear here once enabled for this MDA.</span>
            </div>
        </article>

        <ReportTemplateDetailPanel v-if="selectedTemplate && activeMode === 'detail'" :template="selectedTemplate" />

        <ReportTemplateBuilder
            v-if="selectedTemplate && activeMode === 'builder' && canManageTemplates"
            :template="selectedTemplate"
            @changed="$emit('refresh-template', selectedTemplate.id)"
        />

        <ReportTemplateAssignmentPanel
            v-if="selectedTemplate && activeMode === 'assign' && canAssignTemplates"
            :template="selectedTemplate"
            :mdas="mdas"
            :stations="stations"
            :departments="departments"
            :is-global-user="isGlobalUser"
            :busy="busy || createBusy"
            @save="saveAssignments"
        />

        <ReportTemplateFormModal
            :open="createOpen"
            :mdas="mdas"
            :busy="createBusy"
            :errors="createErrors"
            @close="createOpen = false"
            @save="createTemplate"
        />
    </section>
</template>
