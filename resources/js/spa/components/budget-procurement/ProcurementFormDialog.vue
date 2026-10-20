<script setup>
import { computed, reactive, watch } from 'vue';
import AppButton from '../../../Components/AppButton.vue';
import AppIcon from '../../../Components/AppIcon.vue';
import AppModal from '../../../Components/AppModal.vue';
import AppSelect from '../../../Components/AppSelect.vue';
import AppTextInput from '../../../Components/AppTextInput.vue';

const props = defineProps({ show: Boolean, schema: Object, busy: Boolean, errors: Object, options: Object });
const emit = defineEmits(['close', 'submit']);
const values = reactive({});
const localErrors = reactive({});
const fields = computed(() => props.schema?.fields ?? []);
const errorFor = (key) => localErrors[key] || props.errors?.[key]?.[0] || '';

watch(() => props.schema, (schema) => {
    Object.keys(values).forEach((key) => delete values[key]);
    Object.keys(localErrors).forEach((key) => delete localErrors[key]);
    for (const field of schema?.fields ?? []) values[field.key] = field.default ?? '';
    fillReceiptMatch(values.procurement_receipt_item_id);
});

watch(() => values.procurement_plan_id, (id) => {
    const plan = props.options?.plans?.find((row) => String(row.id) === String(id));
    if (plan?.budget_cycle_id) values.budget_cycle_id = plan.budget_cycle_id;
});
watch(() => values.procurement_plan_line_id, (id) => {
    const line = props.options?.planLines?.find((row) => String(row.id) === String(id));
    if (line) {
        for (const key of ['item_description', 'quantity', 'unit', 'estimated_amount', 'estimated_unit_cost', 'budget_allocation_id', 'department_id', 'specification']) {
            if (line[key] !== null && line[key] !== undefined) values[key] = line[key];
        }
    }
});
watch(() => values.year, (year) => {
    const cycle = props.options?.cycles?.find((row) => String(row.id) === String(values.budget_cycle_id));
    if (cycle && cycle.year !== Number(year)) values.budget_cycle_id = '';
});
function fillReceiptMatch(id) {
    const row = props.schema?.matchingReceipts?.find((item) => String(item.id) === String(id));
    const existing = props.schema?.existingItems?.find((item) => String(item.procurement_receipt_item_id) === String(id));
    if (row) {
        values.quantity = existing?.quantity ?? row.available_quantity;
        values.unit_price = existing?.unit_price ?? row.unit_price;
    }
}
watch(() => values.procurement_receipt_item_id, fillReceiptMatch);

function fieldOptions(field) {
    let options = field.options ?? [];
    if (field.key === 'budget_cycle_id') {
        const plan = props.options?.plans?.find((row) => String(row.id) === String(values.procurement_plan_id));
        const year = values.year || plan?.year;
        const ids = new Set(props.options?.cycles?.filter((row) => (!year || row.year === Number(year)) && (!plan?.budget_cycle_id || row.id === plan.budget_cycle_id)).map((row) => row.id));
        options = options.filter((option) => ids.has(option.value));
    }
    return field.required ? options : [{ value: '', label: 'None' }, ...options];
}

function submit() {
    if (props.busy) return;
    Object.keys(localErrors).forEach((key) => delete localErrors[key]);
    for (const field of fields.value) {
        if (field.required && (values[field.key] === '' || values[field.key] === null)) localErrors[field.key] = `${field.label} is required.`;
    }
    if (Object.keys(localErrors).length) return;
    const entries = Object.entries(values);
    emit('submit', Object.fromEntries(props.schema?.edit ? entries.map(([key, value]) => [key, value === '' ? null : value]) : entries.filter(([, value]) => value !== '' && value !== null && value !== undefined)));
}
</script>

<template>
    <AppModal :show="show" :title="schema?.title" :closeable="!busy" overflow-visible @close="emit('close')">
        <form id="bp-record-form" class="bp-form" @submit.prevent="submit">
            <div v-for="field in fields" :key="field.key" :class="{ 'bp-form-wide': field.type === 'textarea' }">
                <AppSelect v-if="field.type === 'select'" v-model="values[field.key]" :label="`${field.label}${field.required ? ' *' : ''}`" :options="fieldOptions(field)" placeholder="Select..." :error="errorFor(field.key)" />
                <label v-else-if="field.type === 'textarea'" class="bp-textarea-label">
                    <span class="ehrmis-label">{{ field.label }}{{ field.required ? ' *' : '' }}</span>
                    <textarea v-model="values[field.key]" class="ehrmis-input" rows="3" :required="field.required" maxlength="10000" />
                    <span v-if="errorFor(field.key)" class="bp-field-error">{{ errorFor(field.key) }}</span>
                </label>
                <AppTextInput v-else v-model="values[field.key]" :label="`${field.label}${field.required ? ' *' : ''}`" :type="field.type" :min="field.min" :max="field.max" :step="field.step" :required="field.required" :maxlength="field.type === 'text' ? 255 : undefined" :error="errorFor(field.key)" />
            </div>
            <p v-if="errors?._general" class="bp-error bp-form-wide" role="alert">{{ errors._general[0] }}</p>
        </form>
        <template #footer>
            <AppButton variant="outline" :disabled="busy" @click="emit('close')">Cancel</AppButton>
            <AppButton type="submit" form="bp-record-form" :disabled="busy"><AppIcon name="checkCircle" class="h-4 w-4" />{{ busy ? 'Saving...' : 'Save' }}</AppButton>
        </template>
    </AppModal>
</template>
