<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    batchId: {
        type: Number,
        required: true,
    },
    rowId: {
        type: Number,
        required: true,
    },
    options: {
        type: Object,
        default: () => ({}),
    },
});

const form = useForm({
    field: 'station',
    target_id: '',
    notes: 'Resolved through import management UI',
});

const fieldOptions = [
    { value: 'mda', label: 'MDA' },
    { value: 'department', label: 'Department' },
    { value: 'station', label: 'Station' },
    { value: 'cadre', label: 'Cadre' },
    { value: 'rank', label: 'Rank' },
    { value: 'qualification_type', label: 'Qualification Type' },
];

const targetOptions = computed(() => {
    return {
        mda: props.options?.mdas ?? [],
        department: props.options?.departments ?? [],
        station: props.options?.stations ?? [],
        cadre: props.options?.cadres ?? [],
        rank: props.options?.ranks ?? [],
        qualification_type: props.options?.qualification_types ?? [],
    }[form.field] ?? [];
});

const optionLabel = (option) => option.code
    ? `${option.code} - ${option.name}`
    : option.name;

const submit = () => {
    form.post(route('legacy-staff-imports.rows.resolve-mapping', [props.batchId, props.rowId]));
};
</script>

<template>
    <AppCard title="Resolve Mapping" subtitle="Apply a safe canonical mapping without changing the original raw legacy payload.">
        <form
            class="space-y-4"
            @submit.prevent="submit"
        >
            <AppSelect v-model="form.field" label="Field">
                <option v-for="field in fieldOptions" :key="field.value" :value="field.value">{{ field.label }}</option>
            </AppSelect>

            <AppSelect v-model="form.target_id" label="Canonical Target" placeholder="Select a target">
                <option v-for="option in targetOptions" :key="option.id" :value="option.id">{{ optionLabel(option) }}</option>
            </AppSelect>

            <AppTextarea v-model="form.notes" label="Notes" :rows="3" />

            <AppButton type="submit" :disabled="form.processing || !form.target_id">
                Save Mapping
            </AppButton>
        </form>
    </AppCard>
</template>
