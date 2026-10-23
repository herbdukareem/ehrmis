<script setup>
import { reactive } from 'vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppButton from '@/Components/AppButton.vue';
import { officeFields, officeLabel, officeLocalDate } from '../../lib/executiveOffice';
const props = defineProps({ type: { type: String, required: true }, record: { type: Object, default: null }, context: { type: Object, required: true }, options: { type: Object, required: true }, busy: Boolean });
const emit = defineEmits(['save', 'cancel']);
const defaults = Object.fromEntries(officeFields[props.type].map(([key, , kind]) => [key, kind === 'boolean' ? false : (kind === 'users' ? [] : '')]));
if (props.type === 'correspondence') defaults.direction = 'incoming';
const initial = Object.fromEntries(['subject', 'body', 'priority', 'confidentiality', 'assigned_to', 'department_id', 'version'].filter(key => props.record?.[key] !== undefined).map(key => [key, props.record[key]]));
const details = Object.fromEntries(Object.keys(defaults).map(key => [key, props.record?.details?.[key] ?? defaults[key]]));
const form = reactive({ subject: '', body: '', priority: 'normal', confidentiality: 'standard', assigned_to: '', department_id: '', starts_at: '', ends_at: '', due_at: '', ...initial, type: props.type, details });
for (const key of ['starts_at', 'ends_at', 'due_at']) form[key] = officeLocalDate(props.record?.[key], props.context.office.timezone);
const choice = values => values.map(value => ({ value, label: officeLabel(value) }));
function save() { emit('save', { ...form, assigned_to: form.assigned_to || null, department_id: form.department_id || null, starts_at: form.starts_at || null, ends_at: form.ends_at || null, due_at: form.due_at || null }); }
</script>
<template>
    <form class="space-y-4" @submit.prevent="save">
        <fieldset :disabled="busy" class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-2">
            <AppTextInput v-model="form.subject" label="Subject" required class="md:col-span-2" />
            <AppSelect v-model="form.priority" label="Priority" :options="choice(['normal', 'high', 'urgent'])" />
            <AppSelect v-model="form.confidentiality" label="Confidentiality" :options="choice(context.executive_content ? ['standard', 'office_restricted', 'executive_only'] : (context.roles.includes('registry') ? ['standard'] : ['standard', 'office_restricted']))" />
            <template v-if="context.abilities.includes('edit')">
                <AppSelect v-model="form.assigned_to" label="Responsible officer" :options="options.users" option-value="id" option-label="name" placeholder="Choose officer" />
                <AppSelect v-model="form.department_id" label="Department" :options="options.departments" option-value="id" option-label="name" placeholder="Choose department" />
            </template>
            <template v-if="['appointment', 'meeting'].includes(type)">
                <AppTextInput v-model="form.starts_at" label="Starts (office time)" type="datetime-local" required />
                <AppTextInput v-model="form.ends_at" label="Ends (office time)" type="datetime-local" required />
            </template>
            <AppTextInput v-if="type === 'visitor'" v-model="form.starts_at" label="Expected arrival (office time)" type="datetime-local" :required="!form.details.walk_in" />
            <AppTextInput v-if="['directive', 'correspondence'].includes(type)" v-model="form.due_at" label="Response deadline (office time)" type="datetime-local" :required="type === 'directive'" />
            <template v-for="[key, title, kind, required] in officeFields[type]" :key="key">
                <AppTextarea v-if="kind === 'long'" v-model="form.details[key]" :label="title" :required="required" class="md:col-span-2" />
                <AppSelect v-else-if="kind === 'boolean'" v-model="form.details[key]" :label="title" :options="[{ value: false, label: 'No' }, { value: true, label: 'Yes' }]" :searchable="false" />
                <AppSelect v-else-if="kind === 'users'" v-model="form.details[key]" multiple :label="title" :options="options.users" option-value="id" option-label="name" placeholder="Choose participants" />
                <AppSelect v-else-if="kind === 'direction'" v-model="form.details[key]" :label="title" :disabled="!!record" :options="choice(context.roles.includes('registry') ? ['incoming'] : ['incoming', 'outgoing'])" />
                <AppTextInput v-else v-model="form.details[key]" :label="title" :type="kind === 'date' ? 'date' : 'text'" :required="required" />
            </template>
            <AppTextarea v-model="form.body" label="Document content or description" class="md:col-span-2" />
        </fieldset>
        <div class="flex flex-wrap gap-3"><AppButton type="submit" :disabled="busy">Save {{ officeLabel(type).toLowerCase() }}</AppButton><AppButton variant="outline" :disabled="busy" @click="emit('cancel')">Cancel</AppButton></div>
    </form>
</template>
