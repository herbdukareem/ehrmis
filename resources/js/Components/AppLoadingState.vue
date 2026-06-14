<script setup>
defineProps({
    lines: {
        type: Number,
        default: 3,
    },
});
</script>

<template>
    <div class="ehrmis-card p-6">
        <div class="space-y-3">
            <div
                v-for="line in lines"
                :key="line"
                class="h-4 animate-pulse rounded-full bg-slate-200"
                :class="line === 1 ? 'w-2/3' : line === lines ? 'w-1/2' : 'w-full'"
            />
        </div>
    </div>
</template>
