<script setup>
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'info', // info | success | warning | danger
    },
    title: {
        type: String,
        default: null,
    },
    dismissible: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['dismiss']);

const toneStyles = {
    info: { wrap: 'bg-blue-50 border-blue-200 text-blue-800', icon: 'info' },
    success: { wrap: 'bg-emerald-50 border-emerald-200 text-emerald-800', icon: 'checkCircle' },
    warning: { wrap: 'bg-amber-50 border-amber-200 text-amber-800', icon: 'exclamation' },
    danger: { wrap: 'bg-rose-50 border-rose-200 text-rose-800', icon: 'xCircle' },
};

const style = toneStyles[props.tone] ?? toneStyles.info;
</script>

<template>
    <div class="flex gap-3 rounded-xl border px-4 py-3 text-sm" :class="style.wrap">
        <AppIcon :name="style.icon" class="mt-0.5 h-5 w-5 shrink-0" />

        <div class="flex-1">
            <div v-if="title" class="font-semibold">{{ title }}</div>
            <div><slot /></div>
        </div>

        <button
            v-if="dismissible"
            type="button"
            class="shrink-0 rounded p-0.5 transition hover:bg-black/5"
            @click="$emit('dismiss')"
        >
            <AppIcon name="close" class="h-4 w-4" />
        </button>
    </div>
</template>
