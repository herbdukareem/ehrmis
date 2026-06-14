<script setup>
import AppButton from '@/Components/AppButton.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    batchId: {
        type: Number,
        required: true,
    },
    rowId: {
        type: Number,
        required: true,
    },
    warningId: {
        type: Number,
        required: true,
    },
});

const form = useForm({
    warning_id: props.warningId,
    notes: 'Reviewed in import management UI',
});

const submit = () => {
    form.post(route('legacy-staff-imports.rows.ignore-warning', [props.batchId, props.rowId]));
};
</script>

<template>
    <AppButton
        variant="outline"
        size="sm"
        @click="submit"
    >
        Mark Reviewed
    </AppButton>
</template>
