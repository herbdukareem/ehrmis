<script setup>
import AppCard from '@/Components/AppCard.vue';
import { computed } from 'vue';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    payload: {
        type: Object,
        default: () => ({}),
    },
});

const formattedPayload = computed(() => JSON.stringify(props.payload ?? {}, null, 2));
</script>

<template>
    <AppCard :title="title">
        <pre class="overflow-x-auto rounded-ehrmis bg-ehrmis-navy-900 p-4 text-xs text-slate-100">{{ formattedPayload }}</pre>
    </AppCard>
</template>
