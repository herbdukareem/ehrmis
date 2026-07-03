<script setup>
import { computed, reactive, watch } from 'vue';
import StatusPill from '../StatusPill.vue';
import { titleCase } from '../../lib/serviceReporting';

const props = defineProps({
    template: { type: Object, required: true },
    mdas: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    isGlobalUser: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['save']);

const rows = reactive([]);

watch(() => props.template?.id, resetRows, { immediate: true });

const defaultMdaId = computed(() => props.mdas[0]?.id ?? '');

function resetRows() {
    rows.splice(0, rows.length, ...(props.template.assignments ?? []).map((assignment) => ({
        mda_id: assignment.mda_id,
        station_id: assignment.station_id ?? '',
        department_id: assignment.department_id ?? '',
        required_from: assignment.required_from ?? '',
        required_until: assignment.required_until ?? '',
        is_required: assignment.is_required !== false,
        status: assignment.status ?? 'active',
    })));

    if (!rows.length) {
        addRow();
    }
}

function addRow() {
    rows.push({
        mda_id: defaultMdaId.value,
        station_id: '',
        department_id: '',
        required_from: '',
        required_until: '',
        is_required: true,
        status: 'active',
    });
}

function removeRow(index) {
    rows.splice(index, 1);
}

function stationsFor(mdaId) {
    return props.stations.filter((station) => !mdaId || Number(station.mda_id) === Number(mdaId));
}

function departmentsFor(mdaId) {
    return props.departments.filter((department) => !mdaId || Number(department.mda_id) === Number(mdaId));
}

function save() {
    emit('save', rows.map((row) => ({
        mda_id: row.mda_id,
        station_id: row.station_id || null,
        department_id: row.department_id || null,
        required_from: row.required_from || null,
        required_until: row.required_until || null,
        is_required: row.is_required,
        status: row.status,
    })));
}
</script>

<template>
    <article class="civic-workspace civic-reporting-panel">
        <div class="civic-workspace-header">
            <div>
                <div class="civic-eyebrow">Template assignment</div>
                <h2>{{ template.name }}</h2>
                <p class="civic-section-note">Assign this template to visible MDAs, stations, facilities, and departments.</p>
            </div>
            <StatusPill :value="template.status" />
        </div>

        <div class="civic-reporting-assignment-list">
            <section v-for="(row, index) in rows" :key="index" class="civic-reporting-assignment-row">
                <label class="civic-field">
                    <span>MDA</span>
                    <select v-model="row.mda_id" :disabled="!isGlobalUser">
                        <option v-for="mda in mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Station / Facility</span>
                    <select v-model="row.station_id">
                        <option value="">MDA-level</option>
                        <option v-for="station in stationsFor(row.mda_id)" :key="station.id" :value="station.id">{{ station.name }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Department</span>
                    <select v-model="row.department_id">
                        <option value="">No department</option>
                        <option v-for="department in departmentsFor(row.mda_id)" :key="department.id" :value="department.id">{{ department.code }} - {{ department.name }}</option>
                    </select>
                </label>
                <label class="civic-field">
                    <span>Required From</span>
                    <input v-model="row.required_from" type="date">
                </label>
                <label class="civic-field">
                    <span>Required Until</span>
                    <input v-model="row.required_until" type="date">
                </label>
                <label class="civic-field">
                    <span>Status</span>
                    <select v-model="row.status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </label>
                <label class="civic-check civic-check-card">
                    <input v-model="row.is_required" type="checkbox">
                    <span class="civic-check-copy">
                        <strong>Required</strong>
                        <small>{{ titleCase(row.status) }} assignment</small>
                    </span>
                </label>
                <button class="civic-button" type="button" @click="removeRow(index)">Remove</button>
            </section>
        </div>

        <div class="civic-dialog-actions civic-reporting-panel-actions">
            <button class="civic-button" type="button" @click="addRow">Add Assignment</button>
            <button class="civic-button civic-button-primary" type="button" :disabled="busy || !rows.length" @click="save">Save Assignments</button>
        </div>
    </article>
</template>
