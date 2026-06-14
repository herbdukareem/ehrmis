<script setup>
import AppStatusBadge from '@/Components/AppStatusBadge.vue';

defineProps({
    status: {
        type: String,
        default: 'pending',
    },
});
</script>

<template>
    <AppStatusBadge :status="status" />
</template>
