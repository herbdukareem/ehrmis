<script setup>
defineProps({ status: { type: String, default: 'unknown' } });
</script>

<template>
    <span class="civic-status" :data-status="status">{{ status.replaceAll('_', ' ') }}</span>
</template>
