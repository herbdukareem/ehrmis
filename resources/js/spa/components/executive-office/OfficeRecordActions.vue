<script setup>
import { reactive, watch } from 'vue';
import AppCard from '@/Components/AppCard.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppButton from '@/Components/AppButton.vue';
import { officeLabel as label } from '../../lib/executiveOffice';
const props = defineProps({ record: { type: Object, required: true }, busy: Boolean, options: { type: Object, required: true } });
const emit = defineEmits(['act']);
const form = reactive({ action: '', comment: '', starts_at: '', ends_at: '', outcome: '', received_by_executive: false, assigned_to: '', department_id: '', due_at: '', dispatch_method: '', dispatch_recipient: '', dispatch_evidence: '', agenda: '', attended_user_ids: [], external_attendance: '' });
watch(() => props.record, () => { form.action = ''; form.comment = ''; });
</script>
<template>
    <AppCard v-if="record.actions?.length" title="Record action" subtitle="Actions are checked against your current assignment and the record version.">
        <form class="space-y-4" @submit.prevent="emit('act', { ...form, version: record.version })">
            <AppSelect v-model="form.action" label="Action" :options="record.actions.map(value => ({ value, label: label(value) }))" placeholder="Choose action" :disabled="busy" />
            <AppTextarea v-if="form.action === 'convert_to_meeting'" v-model="form.agenda" label="Meeting agenda" required />
            <template v-if="form.action === 'record_attendance'"><AppSelect v-model="form.attended_user_ids" multiple label="Participants present" :options="record.participants" option-value="id" option-label="name" placeholder="Choose attendees" /><AppTextarea v-model="form.external_attendance" label="External attendees" /></template>
            <div v-if="form.action === 'reschedule'" class="grid grid-cols-1 gap-4 md:grid-cols-2"><AppTextInput v-model="form.starts_at" type="datetime-local" label="New start (office time)" required /><AppTextInput v-model="form.ends_at" type="datetime-local" label="New end (office time)" required /></div>
            <template v-if="form.action === 'depart'"><AppTextarea v-model="form.outcome" label="Visit outcome" required /><AppSelect v-model="form.received_by_executive" label="Received by the executive" :options="[{ value: true, label: 'Yes' }, { value: false, label: 'No' }]" :searchable="false" /></template>
            <template v-if="['refer', 'clarify'].includes(form.action)"><AppSelect v-model="form.assigned_to" label="Responsible officer" :options="options.users" option-value="id" option-label="name" placeholder="Choose officer" /><AppSelect v-model="form.department_id" label="Department" :options="options.departments" option-value="id" option-label="name" placeholder="Choose department" /></template>
            <AppTextInput v-if="['refer', 'clarify', 'defer'].includes(form.action)" v-model="form.due_at" label="Response deadline (office time)" type="datetime-local" required />
            <template v-if="form.action === 'dispatch'"><AppTextInput v-model="form.dispatch_method" label="Dispatch method" placeholder="Hand delivery, courier, email…" required /><AppTextInput v-model="form.dispatch_recipient" label="Delivered or sent to" required /><AppTextarea v-model="form.dispatch_evidence" label="Delivery evidence or acknowledgement (if available)" /></template>
            <AppTextarea v-model="form.comment" label="Decision, response, or reason" :required="['return', 'reject', 'refer', 'clarify', 'defer', 'respond', 'decline', 'cancel', 'reschedule', 'submit_response', 'return_response', 'report_blocker', 'request_clarification', 'return_minutes', 'reopen', 'update_progress'].includes(form.action)" />
            <AppButton type="submit" :disabled="busy || !form.action">Apply action</AppButton>
        </form>
    </AppCard>
</template>
