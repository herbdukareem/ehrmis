<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppFilterBar from '@/Components/AppFilterBar.vue';
import AppSearchInput from '@/Components/AppSearchInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    batchId: {
        type: Number,
        required: true,
    },
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
    status: props.filters?.status ?? '',
    warning_code: props.filters?.warning_code ?? '',
    error_code: props.filters?.error_code ?? '',
    severity: props.filters?.severity ?? '',
    mda_id: props.filters?.mda_id ?? '',
    department_id: props.filters?.department_id ?? '',
    station_id: props.filters?.station_id ?? '',
    salary_scale_id: props.filters?.salary_scale_id ?? '',
    cadre_id: props.filters?.cadre_id ?? '',
    rank_id: props.filters?.rank_id ?? '',
    level: props.filters?.level ?? '',
    missing_mda: Boolean(props.filters?.missing_mda ?? false),
    missing_department: Boolean(props.filters?.missing_department ?? false),
    missing_station: Boolean(props.filters?.missing_station ?? false),
    missing_cadre: Boolean(props.filters?.missing_cadre ?? false),
    missing_rank: Boolean(props.filters?.missing_rank ?? false),
    unresolved_call_allowance: Boolean(props.filters?.unresolved_call_allowance ?? false),
    published: props.filters?.published ?? '',
    per_page: props.filters?.per_page ?? 20,
});

watch(() => props.filters, (value) => {
    Object.assign(form, {
        search: value?.search ?? '',
        status: value?.status ?? '',
        warning_code: value?.warning_code ?? '',
        error_code: value?.error_code ?? '',
        severity: value?.severity ?? '',
        mda_id: value?.mda_id ?? '',
        department_id: value?.department_id ?? '',
        station_id: value?.station_id ?? '',
        salary_scale_id: value?.salary_scale_id ?? '',
        cadre_id: value?.cadre_id ?? '',
        rank_id: value?.rank_id ?? '',
        level: value?.level ?? '',
        missing_mda: Boolean(value?.missing_mda ?? false),
        missing_department: Boolean(value?.missing_department ?? false),
        missing_station: Boolean(value?.missing_station ?? false),
        missing_cadre: Boolean(value?.missing_cadre ?? false),
        missing_rank: Boolean(value?.missing_rank ?? false),
        unresolved_call_allowance: Boolean(value?.unresolved_call_allowance ?? false),
        published: value?.published ?? '',
        per_page: value?.per_page ?? 20,
    });
});

const submit = () => {
    router.get(route('legacy-staff-imports.show', props.batchId), { ...form }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const clearFilters = () => {
    Object.assign(form, {
        search: '',
        status: '',
        warning_code: '',
        error_code: '',
        severity: '',
        mda_id: '',
        department_id: '',
        station_id: '',
        salary_scale_id: '',
        cadre_id: '',
        rank_id: '',
        level: '',
        missing_mda: false,
        missing_department: false,
        missing_station: false,
        missing_cadre: false,
        missing_rank: false,
        unresolved_call_allowance: false,
        published: '',
        per_page: 20,
    });

    submit();
};
</script>

<template>
    <AppFilterBar
        title="Row Filters"
        subtitle="Search staged rows and isolate unresolved import issues."
    >
        <div class="md:col-span-2 xl:col-span-2">
            <label class="ehrmis-label mb-1.5 block">Search</label>
            <AppSearchInput v-model="form.search" placeholder="Name, CNO, PSN, staff no" />
        </div>

        <AppSelect v-model="form.status" label="Row Status" placeholder="All statuses">
            <option v-for="status in options.row_statuses ?? []" :key="status" :value="status">{{ status }}</option>
        </AppSelect>

        <AppSelect v-model="form.warning_code" label="Warning Type" placeholder="All warnings">
            <option v-for="warningCode in options.warning_codes ?? []" :key="warningCode" :value="warningCode">{{ warningCode }}</option>
        </AppSelect>

        <AppSelect v-model="form.error_code" label="Error Type" placeholder="All errors">
            <option v-for="errorCode in options.error_codes ?? []" :key="errorCode" :value="errorCode">{{ errorCode }}</option>
        </AppSelect>

        <AppSelect v-model="form.mda_id" label="MDA" placeholder="All MDAs">
            <option v-for="mda in options.mdas ?? []" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.department_id" label="Department" placeholder="All departments">
            <option v-for="department in options.departments ?? []" :key="department.id" :value="department.id">{{ department.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.station_id" label="Station" placeholder="All stations">
            <option v-for="station in options.stations ?? []" :key="station.id" :value="station.id">{{ station.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.salary_scale_id" label="Salary Scale" placeholder="All scales">
            <option v-for="scale in options.salary_scales ?? []" :key="scale.id" :value="scale.id">{{ scale.code }}</option>
        </AppSelect>

        <AppSelect v-model="form.cadre_id" label="Cadre" placeholder="All cadres">
            <option v-for="cadre in options.cadres ?? []" :key="cadre.id" :value="cadre.id">{{ cadre.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.rank_id" label="Rank" placeholder="All ranks">
            <option v-for="rank in options.ranks ?? []" :key="rank.id" :value="rank.id">{{ rank.name }}</option>
        </AppSelect>

        <AppTextInput v-model="form.level" label="Level" type="number" min="1" />

        <AppSelect v-model="form.published" label="Published" placeholder="All">
            <option v-for="option in options.published_options ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
        </AppSelect>

        <template #actions>
            <div class="grid w-full gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <label class="flex items-center gap-2 rounded-ehrmis border border-ehrmis-border px-3 py-3 text-sm text-ehrmis-text">
                    <input v-model="form.missing_mda" type="checkbox" class="h-4 w-4 rounded border-ehrmis-border text-ehrmis-primary-600 focus:ring-ehrmis-primary-500">
                    Missing MDA
                </label>
                <label class="flex items-center gap-2 rounded-ehrmis border border-ehrmis-border px-3 py-3 text-sm text-ehrmis-text">
                    <input v-model="form.missing_department" type="checkbox" class="h-4 w-4 rounded border-ehrmis-border text-ehrmis-primary-600 focus:ring-ehrmis-primary-500">
                    Missing Department
                </label>
                <label class="flex items-center gap-2 rounded-ehrmis border border-ehrmis-border px-3 py-3 text-sm text-ehrmis-text">
                    <input v-model="form.missing_station" type="checkbox" class="h-4 w-4 rounded border-ehrmis-border text-ehrmis-primary-600 focus:ring-ehrmis-primary-500">
                    Missing Station
                </label>
                <label class="flex items-center gap-2 rounded-ehrmis border border-ehrmis-border px-3 py-3 text-sm text-ehrmis-text">
                    <input v-model="form.missing_cadre" type="checkbox" class="h-4 w-4 rounded border-ehrmis-border text-ehrmis-primary-600 focus:ring-ehrmis-primary-500">
                    Missing Cadre
                </label>
                <label class="flex items-center gap-2 rounded-ehrmis border border-ehrmis-border px-3 py-3 text-sm text-ehrmis-text">
                    <input v-model="form.missing_rank" type="checkbox" class="h-4 w-4 rounded border-ehrmis-border text-ehrmis-primary-600 focus:ring-ehrmis-primary-500">
                    Missing Rank
                </label>
                <label class="flex items-center gap-2 rounded-ehrmis border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-800">
                    <input v-model="form.unresolved_call_allowance" type="checkbox" class="h-4 w-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                    Unresolved Call
                </label>
            </div>

            <div class="flex flex-wrap gap-3">
                <AppButton @click="submit">Apply Filters</AppButton>
                <AppButton variant="secondary" @click="clearFilters">Clear</AppButton>
            </div>
        </template>
    </AppFilterBar>
</template>
