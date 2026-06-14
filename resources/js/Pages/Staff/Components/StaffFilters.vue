<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppFilterBar from '@/Components/AppFilterBar.vue';
import AppSearchInput from '@/Components/AppSearchInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    options: {
        type: Object,
        default: () => ({}),
    },
});

const form = reactive({
    search: props.filters?.search ?? '',
    mda_id: props.filters?.mda_id ?? '',
    department_id: props.filters?.department_id ?? '',
    station_id: props.filters?.station_id ?? '',
    cadre_id: props.filters?.cadre_id ?? '',
    rank_id: props.filters?.rank_id ?? '',
    salary_scale_id: props.filters?.salary_scale_id ?? '',
    level: props.filters?.level ?? '',
    status: props.filters?.status ?? '',
    retirement_state: props.filters?.retirement_state ?? '',
    per_page: props.filters?.per_page ?? 15,
});

watch(() => props.filters, (value) => {
    Object.assign(form, {
        search: value?.search ?? '',
        mda_id: value?.mda_id ?? '',
        department_id: value?.department_id ?? '',
        station_id: value?.station_id ?? '',
        cadre_id: value?.cadre_id ?? '',
        rank_id: value?.rank_id ?? '',
        salary_scale_id: value?.salary_scale_id ?? '',
        level: value?.level ?? '',
        status: value?.status ?? '',
        retirement_state: value?.retirement_state ?? '',
        per_page: value?.per_page ?? 15,
    });
});

const submit = () => {
    router.get(route('staff.index'), { ...form }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    Object.assign(form, {
        search: '',
        mda_id: '',
        department_id: '',
        station_id: '',
        cadre_id: '',
        rank_id: '',
        salary_scale_id: '',
        level: '',
        status: '',
        retirement_state: '',
        per_page: 15,
    });

    submit();
};
</script>

<template>
    <AppFilterBar
        title="Filters"
        subtitle="Search and narrow the imported staff roster."
    >
        <div class="md:col-span-2 xl:col-span-2">
            <label class="ehrmis-label mb-1.5 block">Search</label>
            <AppSearchInput v-model="form.search" placeholder="Name, staff no, CNO, PSN" />
        </div>

        <AppSelect v-model="form.mda_id" label="MDA" placeholder="All MDAs">
            <option v-for="mda in options.mdas ?? []" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.department_id" label="Department" placeholder="All Departments">
            <option v-for="department in options.departments ?? []" :key="department.id" :value="department.id">{{ department.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.station_id" label="Station" placeholder="All Stations">
            <option v-for="station in options.stations ?? []" :key="station.id" :value="station.id">{{ station.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.cadre_id" label="Cadre" placeholder="All Cadres">
            <option v-for="cadre in options.cadres ?? []" :key="cadre.id" :value="cadre.id">{{ cadre.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.rank_id" label="Rank" placeholder="All Ranks">
            <option v-for="rank in options.ranks ?? []" :key="rank.id" :value="rank.id">{{ rank.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.salary_scale_id" label="Salary Scale" placeholder="All Scales">
            <option v-for="scale in options.salary_scales ?? []" :key="scale.id" :value="scale.id">{{ scale.code }}</option>
        </AppSelect>

        <AppTextInput v-model="form.level" label="Level" type="number" min="1" />

        <AppSelect v-model="form.status" label="Status" placeholder="All Statuses">
            <option v-for="option in options.status_options ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
        </AppSelect>

        <AppSelect v-model="form.retirement_state" label="Retirement State" placeholder="All">
            <option v-for="option in options.retirement_options ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
        </AppSelect>

        <template #actions>
            <AppButton @click="submit">Apply Filters</AppButton>
            <AppButton variant="secondary" @click="clearFilters">Clear</AppButton>
        </template>
    </AppFilterBar>
</template>
