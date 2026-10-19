<script setup>
defineProps({ rows: { type: Number, default: 5 } });
</script>

<template>
    <div role="status" aria-label="Loading" class="space-y-4 py-6 motion-safe:animate-pulse">
        <span class="sr-only">Loading</span>
        <div v-for="row in rows" :key="row" class="h-9 rounded bg-gray-200" />
    </div>
</template>
