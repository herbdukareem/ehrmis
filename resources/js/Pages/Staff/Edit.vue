<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppDateInput from '@/Components/AppDateInput.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    staff: Object,
    formOptions: Object,
});

const coreForm = useForm({
    mda_id: props.staff.mda?.id ?? '',
    staff_number: props.staff.staff_number ?? '',
    legacy_cno: props.staff.legacy_cno ?? '',
    legacy_psn: props.staff.legacy_psn ?? '',
    surname: props.staff.surname ?? '',
    first_name: props.staff.first_name ?? '',
    middle_name: props.staff.middle_name ?? '',
    full_name: props.staff.full_name ?? '',
    sex: props.staff.sex ?? '',
    date_of_birth: props.staff.date_of_birth ?? '',
    status: props.staff.status ?? 'active',
    status_reason: '',
    status_effective_from: '',
    personal_detail: {
        lga: props.staff.personal_detail?.lga ?? '',
        state_of_origin: props.staff.personal_detail?.state_of_origin ?? '',
        phone: props.staff.personal_detail?.phone ?? '',
        email: props.staff.personal_detail?.email ?? '',
        address: props.staff.personal_detail?.address ?? '',
        marital_status: props.staff.personal_detail?.marital_status ?? '',
        file_no: props.staff.personal_detail?.file_no ?? '',
    },
});

const employmentForm = useForm({
    mda_id: props.staff.current_employment?.mda_id ?? props.staff.mda?.id ?? '',
    department_id: props.staff.current_employment?.department_id ?? '',
    station_id: props.staff.current_employment?.station_id ?? '',
    location_name: props.staff.current_employment?.location_name ?? '',
    cadre_id: props.staff.current_employment?.cadre_id ?? '',
    rank_id: props.staff.current_employment?.rank_id ?? '',
    staff_category: props.staff.current_employment?.staff_category ?? '',
    initial_rank: props.staff.current_employment?.initial_rank ?? '',
    date_first_appointment: props.staff.current_employment?.date_first_appointment ?? '',
    date_last_promotion: props.staff.current_employment?.date_last_promotion ?? '',
    expected_retirement_date: props.staff.current_employment?.expected_retirement_date ?? '',
    next_promotion_date: props.staff.current_employment?.next_promotion_date ?? '',
    employment_status: props.staff.current_employment?.employment_status ?? 'active',
    effective_from: '',
});

const salaryForm = useForm({
    salary_scale_id: props.staff.current_salary_placement?.salary_scale_id ?? '',
    level: props.staff.current_salary_placement?.level ?? '',
    step: props.staff.current_salary_placement?.step ?? '',
    effective_from: '',
});

const qualificationForm = useForm({
    qualification_type_id: '',
    qualification_name: '',
    highest_qualification_name: '',
    specialization: '',
    is_highest: true,
});

const allowancesForm = useForm({
    assignments: (props.formOptions.allowance_types ?? []).map((type) => {
        const existing = (props.staff.allowance_assignments ?? []).find((assignment) => assignment.allowance_type_id === type.id);

        return {
            allowance_type_id: type.id,
            allowance_name: type.name,
            is_eligible: existing?.is_eligible ?? false,
            effective_from: existing?.effective_from ?? '',
            effective_to: existing?.effective_to ?? '',
            source: existing?.source ?? 'staff_management',
        };
    }),
});

const statusForm = useForm({
    status: props.staff.status ?? 'active',
    reason: '',
    effective_from: '',
});

const submitCore = () => coreForm.put(route('staff.update', props.staff.id));
const submitEmployment = () => employmentForm.post(route('staff.employment.store', props.staff.id));
const submitSalary = () => salaryForm.post(route('staff.salary-placement.store', props.staff.id));
const submitQualification = () => qualificationForm.post(route('staff.qualifications.store', props.staff.id));
const submitAllowances = () => allowancesForm.post(route('staff.allowances.sync', props.staff.id));
const submitStatus = () => statusForm.post(route('staff.status-history.store', props.staff.id));
</script>

<template>
    <Head :title="`Edit ${staff.full_name}`" />

    <AppLayout>
        <AppPageHeader
            :title="`Edit ${staff.full_name}`"
            subtitle="Maintain identity, employment, salary placement, qualification, allowances, and status with audit coverage."
            :breadcrumbs="[{ label: 'Staff', href: route('staff.index') }, { label: staff.full_name, href: route('staff.show', staff.id) }, { label: 'Edit' }]"
        >
            <template #actions>
                <AppButton :href="route('staff.show', staff.id)" variant="secondary">Back to Detail</AppButton>
            </template>
        </AppPageHeader>

        <div class="space-y-6">
            <AppCard title="Core Staff Record">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <AppSelect v-model="coreForm.mda_id" label="MDA" :error="coreForm.errors.mda_id">
                        <option v-for="mda in formOptions.mdas ?? []" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </AppSelect>
                    <AppTextInput v-model="coreForm.staff_number" label="Staff Number" :error="coreForm.errors.staff_number" />
                    <AppSelect v-model="coreForm.status" label="Status" :error="coreForm.errors.status">
                        <option v-for="option in formOptions.status_options ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </AppSelect>
                    <AppTextInput v-model="coreForm.surname" label="Surname" :error="coreForm.errors.surname" />
                    <AppTextInput v-model="coreForm.first_name" label="First Name" :error="coreForm.errors.first_name" />
                    <AppTextInput v-model="coreForm.middle_name" label="Middle Name" :error="coreForm.errors.middle_name" />
                    <div class="md:col-span-2 xl:col-span-3">
                        <AppTextInput v-model="coreForm.full_name" label="Full Name" :error="coreForm.errors.full_name" />
                    </div>
                    <AppTextInput v-model="coreForm.legacy_cno" label="Legacy CNO" :error="coreForm.errors.legacy_cno" />
                    <AppTextInput v-model="coreForm.legacy_psn" label="Legacy PSN" :error="coreForm.errors.legacy_psn" />
                    <AppDateInput v-model="coreForm.date_of_birth" label="Date of Birth" :error="coreForm.errors.date_of_birth" />
                    <AppTextInput v-model="coreForm.personal_detail.phone" label="Phone" />
                    <AppTextInput v-model="coreForm.personal_detail.email" type="email" label="Email" />
                </div>
                <div class="mt-5">
                    <AppButton :disabled="coreForm.processing" @click="submitCore">Save Core Record</AppButton>
                </div>
            </AppCard>

            <div class="grid gap-6 xl:grid-cols-2">
                <AppCard title="New Current Employment">
                    <div class="grid gap-4 md:grid-cols-2">
                        <AppSelect v-model="employmentForm.department_id" label="Department" placeholder="Select">
                            <option v-for="department in formOptions.departments ?? []" :key="department.id" :value="department.id">{{ department.name }}</option>
                        </AppSelect>
                        <AppSelect v-model="employmentForm.station_id" label="Station" placeholder="Select">
                            <option v-for="station in formOptions.stations ?? []" :key="station.id" :value="station.id">{{ station.name }}</option>
                        </AppSelect>
                        <AppSelect v-model="employmentForm.cadre_id" label="Cadre" placeholder="Select">
                            <option v-for="cadre in formOptions.cadres ?? []" :key="cadre.id" :value="cadre.id">{{ cadre.name }}</option>
                        </AppSelect>
                        <AppSelect v-model="employmentForm.rank_id" label="Rank" placeholder="Select">
                            <option v-for="rank in formOptions.ranks ?? []" :key="rank.id" :value="rank.id">{{ rank.name }}</option>
                        </AppSelect>
                        <AppSelect v-model="employmentForm.employment_status" label="Employment Status">
                            <option value="active">Active</option>
                            <option value="retired">Retired</option>
                            <option value="inactive">Inactive</option>
                        </AppSelect>
                        <AppDateInput v-model="employmentForm.effective_from" label="Effective From" />
                    </div>
                    <div class="mt-5"><AppButton :disabled="employmentForm.processing" @click="submitEmployment">Save Employment</AppButton></div>
                </AppCard>

                <AppCard title="New Current Salary Placement">
                    <div class="grid gap-4 md:grid-cols-2">
                        <AppSelect v-model="salaryForm.salary_scale_id" label="Salary Scale" placeholder="Select">
                            <option v-for="scale in formOptions.salary_scales ?? []" :key="scale.id" :value="scale.id">{{ scale.code }}</option>
                        </AppSelect>
                        <AppTextInput v-model="salaryForm.level" type="number" min="1" label="Level" />
                        <AppTextInput v-model="salaryForm.step" type="number" min="1" label="Step" />
                        <AppDateInput v-model="salaryForm.effective_from" label="Effective From" />
                    </div>
                    <div class="mt-5"><AppButton :disabled="salaryForm.processing" @click="submitSalary">Save Salary Placement</AppButton></div>
                </AppCard>

                <AppCard title="Qualification Update">
                    <div class="grid gap-4 md:grid-cols-2">
                        <AppSelect v-model="qualificationForm.qualification_type_id" label="Qualification Type" placeholder="Select">
                            <option v-for="qualification in formOptions.qualification_types ?? []" :key="qualification.id" :value="qualification.id">{{ qualification.name }}</option>
                        </AppSelect>
                        <AppTextInput v-model="qualificationForm.highest_qualification_name" label="Highest Qualification Name" />
                        <div class="md:col-span-2">
                            <AppTextInput v-model="qualificationForm.specialization" label="Specialization" />
                        </div>
                    </div>
                    <div class="mt-5"><AppButton :disabled="qualificationForm.processing" @click="submitQualification">Add Qualification</AppButton></div>
                </AppCard>

                <AppCard title="Status History Update">
                    <div class="grid gap-4 md:grid-cols-2">
                        <AppSelect v-model="statusForm.status" label="Status">
                            <option v-for="option in formOptions.status_options ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </AppSelect>
                        <AppDateInput v-model="statusForm.effective_from" label="Effective From" />
                        <div class="md:col-span-2">
                            <AppTextInput v-model="statusForm.reason" label="Reason" />
                        </div>
                    </div>
                    <div class="mt-5"><AppButton :disabled="statusForm.processing" @click="submitStatus">Add Status History</AppButton></div>
                </AppCard>
            </div>

            <AppCard title="Allowance Assignments">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <label
                        v-for="assignment in allowancesForm.assignments"
                        :key="assignment.allowance_type_id"
                        class="flex items-center gap-3 rounded-ehrmis border border-ehrmis-border px-4 py-3"
                    >
                        <input
                            v-model="assignment.is_eligible"
                            type="checkbox"
                            class="h-4 w-4 rounded border-ehrmis-border text-ehrmis-primary-600 focus:ring-ehrmis-primary-500"
                        >
                        <span class="text-sm font-medium text-ehrmis-text">{{ assignment.allowance_name }}</span>
                    </label>
                </div>
                <div class="mt-5"><AppButton :disabled="allowancesForm.processing" @click="submitAllowances">Save Allowances</AppButton></div>
            </AppCard>
        </div>
    </AppLayout>
</template>
