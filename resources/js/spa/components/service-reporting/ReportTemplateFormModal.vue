<script setup>
import { reactive, watch } from 'vue';
import AppModal from '../AppModal.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    mdas: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'save']);

const form = reactive({
    name: '',
    code: '',
    owner_mda_id: '',
    description: '',
    frequency: 'monthly',
    submission_deadline_day: '',
    requires_approval: true,
    allow_late_submission: true,
    status: 'draft',
});

watch(() => props.open, (open) => {
    if (!open) return;

    Object.assign(form, {
        name: '',
        code: '',
        owner_mda_id: props.mdas[0]?.id ?? '',
        description: '',
        frequency: 'monthly',
        submission_deadline_day: '',
        requires_approval: true,
        allow_late_submission: true,
        status: 'draft',
    });
});

function save() {
    emit('save', {
        ...form,
        owner_mda_id: form.owner_mda_id || null,
        submission_deadline_day: form.submission_deadline_day || null,
    });
}

function fieldErrors(name) {
    return props.errors[name] ?? [];
}
</script>

<template>
    <AppModal
        :open="open"
        eyebrow="Template management"
        title="Create Template"
        description="Create a generic service reporting template for any visible MDA."
        size="wide"
        @close="$emit('close')"
    >
        <form class="civic-dialog-form civic-form-grid" @submit.prevent="save">
            <label class="civic-field">
                <span>Template Name</span>
                <input v-model="form.name" type="text">
                <small v-for="message in fieldErrors('name')" :key="message" class="civic-field-error">{{ message }}</small>
            </label>
            <label class="civic-field">
                <span>Template Code</span>
                <input v-model="form.code" type="text" placeholder="MONTHLY_RETURN">
                <small v-for="message in fieldErrors('code')" :key="message" class="civic-field-error">{{ message }}</small>
            </label>
            <label class="civic-field">
                <span>Owner MDA</span>
                <select v-model="form.owner_mda_id">
                    <option value="">Platform</option>
                    <option v-for="mda in mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                </select>
                <small v-for="message in fieldErrors('owner_mda_id')" :key="message" class="civic-field-error">{{ message }}</small>
            </label>
            <label class="civic-field civic-field-wide">
                <span>Description</span>
                <textarea v-model="form.description" rows="3"></textarea>
            </label>
            <label class="civic-field">
                <span>Frequency</span>
                <select v-model="form.frequency">
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="yearly">Yearly</option>
                </select>
            </label>
            <label class="civic-field">
                <span>Submission Deadline Day</span>
                <input v-model="form.submission_deadline_day" type="number" min="1" max="28">
            </label>
            <label class="civic-field">
                <span>Status</span>
                <select v-model="form.status">
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                </select>
            </label>
            <label class="civic-check civic-check-card">
                <input v-model="form.requires_approval" type="checkbox">
                <span class="civic-check-copy">
                    <strong>Requires Approval</strong>
                    <small>Submissions must be approved before analytics use.</small>
                </span>
            </label>
            <label class="civic-check civic-check-card">
                <input v-model="form.allow_late_submission" type="checkbox">
                <span class="civic-check-copy">
                    <strong>Allow Late Submission</strong>
                    <small>Facilities may submit after the deadline day.</small>
                </span>
            </label>
        </form>

        <template #actions>
            <button class="civic-button" type="button" :disabled="busy" @click="$emit('close')">Cancel</button>
            <button class="civic-button civic-button-primary" type="button" :disabled="busy" @click="save">Create Template</button>
        </template>
    </AppModal>
</template>
