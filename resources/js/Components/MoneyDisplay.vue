<script setup>
import { computed } from 'vue';

const props = defineProps({
    amount: {
        type: [Number, String],
        default: 0,
    },
});

const formattedAmount = computed(() => {
    const numericAmount = Number(props.amount ?? 0);

    if (Number.isNaN(numericAmount)) {
        return 'N/A';
    }

    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        maximumFractionDigits: 2,
    }).format(numericAmount);
});
</script>

<template>
    <span class="font-semibold tabular-nums text-slate-900">
        {{ formattedAmount }}
    </span>
</template>
