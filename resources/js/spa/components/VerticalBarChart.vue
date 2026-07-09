<script setup>
import { computed } from 'vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    limit: { type: Number, default: 8 },
});

const visibleRows = computed(() => props.rows.slice(0, props.limit));
const maximum = computed(() => Math.max(...visibleRows.value.map((row) => Number(row.total)), 1));
</script>

<template>
    <div class="civic-vertical-bar-chart">
        <div v-for="row in visibleRows" :key="row.code ?? row.label" class="civic-vertical-bar-column">
            <div class="civic-vertical-bar-value">{{ Number(row.total).toLocaleString() }}</div>
            <div class="civic-vertical-bar-track">
                <span :style="{ height: `${Math.max((row.total / maximum) * 100, 3)}%` }"></span>
            </div>
            <strong :title="row.label">{{ row.label }}</strong>
        </div>
        <div v-if="!visibleRows.length" class="civic-empty-cell">No distribution data available.</div>
    </div>
</template>
