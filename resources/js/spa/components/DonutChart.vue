<script setup>
import { computed } from 'vue';

const props = defineProps({ rows: { type: Array, default: () => [] } });
const colors = ['#173d70', '#b28a38', '#5b806f', '#a64b45', '#6f7887'];
const total = computed(() => props.rows.reduce((sum, row) => sum + Number(row.total), 0));
const gradient = computed(() => {
    let cursor = 0;
    const segments = props.rows.map((row, index) => {
        const start = cursor;
        cursor += total.value ? (Number(row.total) / total.value) * 100 : 0;
        return `${colors[index % colors.length]} ${start}% ${cursor}%`;
    });
    return `conic-gradient(${segments.join(', ')})`;
});
</script>

<template>
    <div class="civic-donut-layout">
        <div class="civic-donut" :style="{ background: gradient }"><div><strong>{{ total.toLocaleString() }}</strong><span>staff</span></div></div>
        <div class="civic-chart-legend">
            <div v-for="(row, index) in rows" :key="row.label"><i :style="{ background: colors[index % colors.length] }"></i><span>{{ row.label }}</span><strong>{{ Number(row.total).toLocaleString() }}</strong></div>
        </div>
    </div>
</template>
