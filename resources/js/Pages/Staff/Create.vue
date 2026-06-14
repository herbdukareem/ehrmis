<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppDateInput from '@/Components/AppDateInput.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    formOptions: Object,
});

const form = useForm({
    mda_id: props.formOptions?.mdas?.[0]?.id ?? '',
    staff_number: '',
    legacy_cno: '',
    legacy_psn: '',
    surname: '',
    first_name: '',
    middle_name: '',
    full_name: '',
    sex: '',
    date_of_birth: '',
    status: 'active',
    personal_detail: {
        lga: '',
        state_of_origin: '',
        phone: '',
        email: '',
        address: '',
        marital_status: '',
        file_no: '',
    },
    employment: {
        mda_id: props.formOptions?.mdas?.[0]?.id ?? '',
        department_id: '',
        station_id: '',
        location_name: '',
        cadre_id: '',
        rank_id: '',
        staff_category: '',
        initial_rank: '',
        date_first_appointment: '',
        date_last_promotion: '',
        expected_retirement_date: '',
        next_promotion_date: '',
        employment_status: 'active',
        effective_from: '',
    },
    salary_placement: {
        salary_scale_id: '',
        level: '',
        step: '',
        effective_from: '',
    },
    qualification: {
        qualification_type_id: '',
        qualification_name: '',
        highest_qualification_name: '',
        specialization: '',
        is_highest: true,
    },
    allowances: [],
});

const submit = () => form.post(route('staff.store'));
</script>

<template>
    <Head title="Create Staff" />

    <AppLayout>
        <AppPageHeader
            title="Create Staff"
            subtitle="Add a new canonical staff record with optional employment and salary placement."
            :breadcrumbs="[{ label: 'Staff', href: route('staff.index') }, { label: 'Create' }]"
        >
            <template #actions>
                <AppButton :href="route('staff.index')" variant="secondary">Back to Staff</AppButton>
            </template>
        </AppPageHeader>

        <form
            class="space-y-6"
            @submit.prevent="submit"
        >
            <AppCard title="Core Identity">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <AppSelect v-model="form.mda_id" label="MDA" :error="form.errors.mda_id">
                        <option v-for="mda in formOptions.mdas ?? []" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </AppSelect>
                    <AppTextInput v-model="form.staff_number" label="Staff Number" :error="form.errors.staff_number" />
                    <AppSelect v-model="form.status" label="Status" :error="form.errors.status">
                        <option v-for="option in formOptions.status_options ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </AppSelect>
                    <AppTextInput v-model="form.surname" label="Surname" :error="form.errors.surname" />
                    <AppTextInput v-model="form.first_name" label="First Name" :error="form.errors.first_name" />
                    <AppTextInput v-model="form.middle_name" label="Middle Name" :error="form.errors.middle_name" />
                    <div class="md:col-span-2 xl:col-span-3">
                        <AppTextInput v-model="form.full_name" label="Full Name" :error="form.errors.full_name" />
                    </div>
                    <AppTextInput v-model="form.legacy_cno" label="Legacy CNO" :error="form.errors.legacy_cno" />
                    <AppTextInput v-model="form.legacy_psn" label="Legacy PSN" :error="form.errors.legacy_psn" />
                    <AppSelect v-model="form.sex" label="Sex" placeholder="Select" :error="form.errors.sex">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </AppSelect>
                    <AppDateInput v-model="form.date_of_birth" label="Date of Birth" :error="form.errors.date_of_birth" />
                </div>
            </AppCard>

            <AppCard title="Personal Detail">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <AppTextInput v-model="form.personal_detail.lga" label="LGA" />
                    <AppTextInput v-model="form.personal_detail.state_of_origin" label="State of Origin" />
                    <AppTextInput v-model="form.personal_detail.phone" label="Phone" />
                    <AppTextInput v-model="form.personal_detail.email" type="email" label="Email" />
                    <AppTextInput v-model="form.personal_detail.marital_status" label="Marital Status" />
                    <AppTextInput v-model="form.personal_detail.file_no" label="File No" />
                    <div class="md:col-span-2 xl:col-span-3">
                        <AppTextarea v-model="form.personal_detail.address" label="Address" :rows="3" />
                    </div>
                </div>
            </AppCard>

            <AppCard title="Current Employment">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <AppSelect v-model="form.employment.mda_id" label="Employment MDA">
                        <option v-for="mda in formOptions.mdas ?? []" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </AppSelect>
                    <AppSelect v-model="form.employment.department_id" label="Department" placeholder="Select">
                        <option v-for="department in formOptions.departments ?? []" :key="department.id" :value="department.id">{{ department.name }}</option>
                    </AppSelect>
                    <AppSelect v-model="form.employment.station_id" label="Station" placeholder="Select">
                        <option v-for="station in formOptions.stations ?? []" :key="station.id" :value="station.id">{{ station.name }}</option>
                    </AppSelect>
                    <AppSelect v-model="form.employment.cadre_id" label="Cadre" placeholder="Select">
                        <option v-for="cadre in formOptions.cadres ?? []" :key="cadre.id" :value="cadre.id">{{ cadre.name }}</option>
                    </AppSelect>
                    <AppSelect v-model="form.employment.rank_id" label="Rank" placeholder="Select">
                        <option v-for="rank in formOptions.ranks ?? []" :key="rank.id" :value="rank.id">{{ rank.name }}</option>
                    </AppSelect>
                    <AppSelect v-model="form.employment.employment_status" label="Employment Status">
                        <option value="active">Active</option>
                        <option value="retired">Retired</option>
                        <option value="inactive">Inactive</option>
                    </AppSelect>
                    <AppTextInput v-model="form.employment.location_name" label="Location" />
                    <AppDateInput v-model="form.employment.date_first_appointment" label="Date First Appointment" />
                    <AppDateInput v-model="form.employment.date_last_promotion" label="Date Last Promotion" />
                </div>
            </AppCard>

            <AppCard title="Salary Placement">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <AppSelect v-model="form.salary_placement.salary_scale_id" label="Salary Scale" placeholder="Select">
                        <option v-for="scale in formOptions.salary_scales ?? []" :key="scale.id" :value="scale.id">{{ scale.code }}</option>
                    </AppSelect>
                    <AppTextInput v-model="form.salary_placement.level" type="number" min="1" label="Level" />
                    <AppTextInput v-model="form.salary_placement.step" type="number" min="1" label="Step" />
                    <AppDateInput v-model="form.salary_placement.effective_from" label="Effective From" />
                </div>
            </AppCard>

            <div class="flex flex-wrap gap-3">
                <AppButton type="submit" :disabled="form.processing">Create Staff Record</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
