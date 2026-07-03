<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: '' },
    value: { type: String, default: '' },
});

const displayStatus = computed(() => props.status || props.value || 'unknown');
</script>

<template>
    <span class="civic-status" :data-status="displayStatus">{{ displayStatus.replaceAll('_', ' ') }}</span>
</template>
