<script setup>
import { computed } from 'vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    limit: { type: Number, default: 12 },
});

const visibleRows = computed(() => props.rows.slice(0, props.limit));
const maximum = computed(() => Math.max(...visibleRows.value.map((row) => Number(row.total)), 1));
</script>

<template>
    <div class="civic-bar-chart">
        <div v-for="row in visibleRows" :key="row.label" class="civic-bar-row">
            <div class="civic-bar-label" :title="row.label">{{ row.label }}</div>
            <div class="civic-bar-track"><span :style="{ width: `${Math.max((row.total / maximum) * 100, 2)}%` }"></span></div>
            <strong>{{ Number(row.total).toLocaleString() }}</strong>
        </div>
        <div v-if="!visibleRows.length" class="civic-empty-cell">No distribution data available.</div>
    </div>
</template>
