<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppDateInput from '@/Components/AppDateInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import { api, apiMessage } from '../../lib/api';
import { auth } from '../../stores/auth';

const props = defineProps({ show: Boolean, options: { type: Object, default: () => ({}) } });
const emit = defineEmits(['close', 'created']);
const saving = ref(false);
const error = ref('');
const errors = ref({});
const formElement = ref();
const form = reactive({});
const today = new Date().toLocaleDateString('en-CA');
const fullName = computed(() => [form.surname, form.first_name, form.middle_name].filter(Boolean).map(value => value.trim()).filter(Boolean).join(' '));
const departmentScoped = computed(() => !auth.user?.has_global_access && (auth.user?.access_scopes ?? []).some(scope => scope.scope_type === 'department'));
const departments = computed(() => (props.options.departments ?? []).filter(row => Number(row.mda_id) === Number(form.mda_id)));
const stations = computed(() => (props.options.stations ?? []).filter(row => Number(row.mda_id) === Number(form.mda_id) && (!auth.user?.assigned_station || row.id === auth.user.assigned_station.id)));
const cadres = computed(() => (props.options.cadres ?? []).filter(row => Number(row.department_id) === Number(form.department_id)));
const ranks = computed(() => (props.options.ranks ?? []).filter(row => Number(row.cadre_id) === Number(form.cadre_id)));
const choices = rows => rows.map(row => ({ value: row.id, label: row.name ?? row.code }));
const fieldError = key => errors.value[key]?.[0] ?? '';
const nullable = value => value === '' ? null : value;
const contactFields = [
    { key: 'phone', label: 'Phone', type: 'tel' }, { key: 'email', label: 'Email', type: 'email' },
    { key: 'state_of_origin', label: 'State of origin' }, { key: 'lga', label: 'LGA' }, { key: 'file_no', label: 'File number' },
];

function reset() {
    const mda = auth.user?.assigned_station?.mda_id || auth.user?.assigned_mda?.id || (props.options.mdas?.length === 1 ? props.options.mdas[0].id : '');
    const availableDepartments = (props.options.departments ?? []).filter(row => Number(row.mda_id) === Number(mda));
    Object.assign(form, {
        mda_id: mda, staff_number: '', surname: '', first_name: '', middle_name: '',
        legacy_cno: '', legacy_psn: '', sex: '', date_of_birth: '', status: 'active', is_contract_staff: false,
        phone: '', email: '', address: '', state_of_origin: '', lga: '', marital_status: '', file_no: '',
        department_id: availableDepartments.length === 1 ? availableDepartments[0].id : '',
        station_id: auth.user?.assigned_station?.id ?? '', cadre_id: '', rank_id: '',
        date_first_appointment: '', date_last_promotion: '', effective_from: '', staff_category: '', location_name: '',
        qualification_type_id: '', specialization: '', include_salary: false, salary_scale_id: '', level: '', step: '',
    });
    error.value = '';
    errors.value = {};
}
watch(() => form.mda_id, () => {
    form.department_id = '';
    form.station_id = auth.user?.assigned_station?.mda_id === Number(form.mda_id) ? auth.user.assigned_station.id : '';
}, { flush: 'sync' });
watch(() => form.department_id, () => { form.cadre_id = ''; }, { flush: 'sync' });
watch(() => form.cadre_id, () => { form.rank_id = ''; }, { flush: 'sync' });
watch(() => props.show, show => { if (show) reset(); });

async function submit() {
    if (saving.value) return;
    saving.value = true;
    error.value = '';
    errors.value = {};
    const employment = Object.fromEntries(['department_id', 'station_id', 'cadre_id', 'rank_id', 'date_first_appointment', 'date_last_promotion', 'effective_from', 'staff_category', 'location_name'].map(key => [key, nullable(form[key])]));
    const payload = {
        ...Object.fromEntries(['mda_id', 'staff_number', 'surname', 'first_name', 'middle_name', 'legacy_cno', 'legacy_psn', 'sex', 'date_of_birth', 'status', 'is_contract_staff'].map(key => [key, nullable(form[key])])),
        full_name: fullName.value,
        personal_detail: Object.fromEntries(['phone', 'email', 'address', 'state_of_origin', 'lga', 'marital_status', 'file_no'].map(key => [key, nullable(form[key])])),
    };
    if (Object.values(employment).some(value => value !== null)) payload.employment = employment;
    if (form.qualification_type_id) payload.qualification = { qualification_type_id: form.qualification_type_id, specialization: nullable(form.specialization), is_highest: true };
    if (form.include_salary) payload.salary_placement = { salary_scale_id: nullable(form.salary_scale_id), level: nullable(form.level), step: nullable(form.step), effective_from: nullable(form.effective_from) };
    try {
        const response = await api.post('/staff', payload);
        emit('created', response.data.data);
    } catch (failure) {
        errors.value = failure.response?.data?.errors ?? {};
        error.value = apiMessage(failure);
        await nextTick();
        formElement.value?.querySelector('[role="alert"]')?.scrollIntoView({ block: 'start' });
    } finally { saving.value = false; }
}
</script>

<template>
    <AppModal :show="show" title="New staff" max-width="3xl" :closeable="!saving" @close="emit('close')">
        <form id="staff-create-form" ref="formElement" class="space-y-6" @submit.prevent="submit">
            <AppAlert v-if="error" tone="danger" title="Staff record was not created" role="alert">{{ error }}</AppAlert>
            <fieldset class="min-w-0">
                <legend class="mb-4 text-base font-semibold">Identity</legend>
                <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                    <AppSelect v-model="form.mda_id" label="MDA *" :options="(options.mdas ?? []).map(row => ({ value: row.id, label: `${row.code} - ${row.name}` }))" placeholder="Select MDA" :error="fieldError('mda_id')" :disabled="Boolean(auth.user?.assigned_station)" />
                    <AppTextInput v-model="form.staff_number" label="Staff number *" maxlength="255" required :error="fieldError('staff_number')" />
                    <AppTextInput v-model="form.surname" label="Surname *" maxlength="255" required autocomplete="family-name" :error="fieldError('surname')" />
                    <AppTextInput v-model="form.first_name" label="First name *" maxlength="255" required autocomplete="given-name" :error="fieldError('first_name')" />
                    <AppTextInput v-model="form.middle_name" label="Middle name" maxlength="255" autocomplete="additional-name" :error="fieldError('middle_name')" />
                    <AppTextInput :model-value="fullName" label="Full name" readonly :error="fieldError('full_name')" />
                    <AppSelect v-model="form.sex" label="Sex" :options="[{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }]" placeholder="Select" :error="fieldError('sex')" />
                    <AppDateInput v-model="form.date_of_birth" label="Date of birth" :max="today" :error="fieldError('date_of_birth')" />
                    <AppTextInput v-model="form.legacy_cno" label="CNO" maxlength="50" :error="fieldError('legacy_cno')" />
                    <AppTextInput v-model="form.legacy_psn" label="PSN" maxlength="50" :error="fieldError('legacy_psn')" />
                    <AppSelect v-model="form.status" label="Status *" :options="(options.statuses ?? ['active', 'inactive', 'retired', 'duplicate']).map(value => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))" :error="fieldError('status')" />
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_contract_staff" type="checkbox" class="rounded border-ehrmis-border text-ehrmis-primary-600">Contract staff</label>
                </div>
            </fieldset>
            <fieldset class="min-w-0 border-t border-ehrmis-border pt-4">
                <legend class="text-base font-semibold">Contact and personal details</legend>
                <div class="mt-3 grid min-w-0 gap-4 sm:grid-cols-2">
                    <AppTextInput v-for="field in contactFields" :key="field.key" v-model="form[field.key]" :label="field.label" :type="field.type ?? 'text'" :error="fieldError(`personal_detail.${field.key}`)" />
                    <AppSelect v-model="form.marital_status" label="Marital status" :options="['single', 'married', 'divorced', 'widowed'].map(value => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))" placeholder="Select" :error="fieldError('personal_detail.marital_status')" />
                    <AppTextarea v-model="form.address" label="Address" class="sm:col-span-2" :rows="2" :error="fieldError('personal_detail.address')" />
                </div>
            </fieldset>
            <fieldset class="min-w-0 border-t border-ehrmis-border pt-4">
                <legend class="text-base font-semibold">Appointment</legend>
                <div class="mt-3 grid min-w-0 gap-4 sm:grid-cols-2">
                    <AppSelect v-model="form.department_id" :label="departmentScoped ? 'Department *' : 'Department'" :options="choices(departments)" placeholder="Select department" :error="fieldError('employment.department_id')" />
                    <AppSelect v-model="form.station_id" :label="auth.user?.assigned_station ? 'Station / facility *' : 'Station / facility'" :options="choices(stations)" placeholder="Select station" :error="fieldError('employment.station_id')" :disabled="Boolean(auth.user?.assigned_station)" />
                    <AppSelect v-model="form.cadre_id" label="Cadre" :options="choices(cadres)" placeholder="Select cadre" :disabled="!form.department_id" :error="fieldError('employment.cadre_id')" />
                    <AppSelect v-model="form.rank_id" label="Rank" :options="choices(ranks)" placeholder="Select rank" :disabled="!form.cadre_id" :error="fieldError('employment.rank_id')" />
                    <AppDateInput v-model="form.date_first_appointment" label="First appointment date" :max="today" :error="fieldError('employment.date_first_appointment')" />
                    <AppDateInput v-model="form.date_last_promotion" label="Last promotion date" :max="today" :error="fieldError('employment.date_last_promotion')" />
                    <AppTextInput v-model="form.staff_category" label="Staff category" :error="fieldError('employment.staff_category')" />
                    <AppTextInput v-model="form.location_name" label="Location" :error="fieldError('employment.location_name')" />
                    <AppDateInput v-model="form.effective_from" label="Effective from" :error="fieldError('employment.effective_from')" />
                    <AppSelect v-model="form.qualification_type_id" label="Highest qualification" :options="choices(options.qualification_types ?? [])" placeholder="Select qualification" :error="fieldError('qualification.qualification_type_id')" />
                    <AppTextInput v-if="form.qualification_type_id" v-model="form.specialization" label="Specialization" :error="fieldError('qualification.specialization')" />
                </div>
            </fieldset>
            <fieldset class="min-w-0 border-t border-ehrmis-border pt-4">
                <legend class="text-base font-semibold">Salary placement</legend>
                <label class="mt-3 flex items-center gap-2 text-sm"><input v-model="form.include_salary" type="checkbox" class="rounded border-ehrmis-border text-ehrmis-primary-600">Record salary placement</label>
                <div v-if="form.include_salary" class="mt-4 grid min-w-0 gap-4 sm:grid-cols-3">
                    <AppSelect v-model="form.salary_scale_id" label="Salary scale *" :options="(options.salary_scales ?? []).map(row => ({ value: row.id, label: row.code }))" placeholder="Select scale" :error="fieldError('salary_placement.salary_scale_id')" />
                    <AppTextInput v-model="form.level" label="Level *" type="number" min="1" max="30" required :error="fieldError('salary_placement.level')" />
                    <AppTextInput v-model="form.step" label="Step *" type="number" min="1" max="30" required :error="fieldError('salary_placement.step')" />
                </div>
            </fieldset>
        </form>
        <template #footer>
            <AppButton variant="outline" :disabled="saving" @click="emit('close')">Cancel</AppButton>
            <AppButton type="submit" form="staff-create-form" :disabled="saving"><AppIcon name="checkCircle" class="h-4 w-4" />{{ saving ? 'Creating...' : 'Create staff' }}</AppButton>
        </template>
    </AppModal>
</template>
