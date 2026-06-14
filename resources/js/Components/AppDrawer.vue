<script setup>
import AppIcon from '@/Components/AppIcon.vue';
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: null,
    },
    width: {
        type: String,
        default: 'max-w-md',
    },
});

const emit = defineEmits(['close']);

const close = () => emit('close');

const closeOnEscape = (e) => {
    if (e.key === 'Escape' && props.show) {
        close();
    }
};

watch(() => props.show, (value) => {
    document.body.style.overflow = value ? 'hidden' : '';
});

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = '';
});
</script>

<template>
    <Transition
        enter-active-class="ease-out duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="ease-in duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div v-if="show" class="fixed inset-0 z-50 bg-slate-900/60" @click="close" />
    </Transition>

    <Transition
        enter-active-class="ease-out duration-200"
        enter-from-class="translate-x-full"
        enter-to-class="translate-x-0"
        leave-active-class="ease-in duration-150"
        leave-from-class="translate-x-0"
        leave-to-class="translate-x-full"
    >
        <aside
            v-if="show"
            class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-ehrmis-pop"
            :class="width"
        >
            <div class="flex items-center justify-between border-b border-ehrmis-border px-6 py-4">
                <h3 class="text-base font-semibold text-ehrmis-text">{{ title }}</h3>
                <button
                    type="button"
                    class="rounded-lg p-1 text-ehrmis-muted transition hover:bg-slate-100 hover:text-ehrmis-text"
                    @click="close"
                >
                    <AppIcon name="close" class="h-5 w-5" />
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-4">
                <slot />
            </div>

            <div v-if="$slots.footer" class="flex items-center justify-end gap-3 border-t border-ehrmis-border px-6 py-4">
                <slot name="footer" />
            </div>
        </aside>
    </Transition>
</template>
