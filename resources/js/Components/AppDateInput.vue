<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useId } from 'vue';

defineProps({
    label: {
        type: String,
        default: null,
    },
    error: {
        type: String,
        default: null,
    },
    help: {
        type: String,
        default: null,
    },
});

const model = defineModel({ default: '' });

const id = useId();
</script>

<template>
    <div>
        <InputLabel v-if="label" :for="id" :value="label" class="ehrmis-label mb-1.5" />

        <input
            :id="id"
            v-model="model"
            type="date"
            class="ehrmis-input"
            :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': error }"
            v-bind="$attrs"
        >

        <p v-if="help && !error" class="ehrmis-help-text">{{ help }}</p>
        <InputError :message="error" />
    </div>
</template>
