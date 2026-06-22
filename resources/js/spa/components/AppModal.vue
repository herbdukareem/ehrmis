<script setup>
const props = defineProps({
    open: { type: Boolean, default: false },
    eyebrow: { type: String, default: '' },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    size: { type: String, default: 'regular' },
});

const emit = defineEmits(['close']);

const close = () => emit('close');
</script>

<template>
    <div v-if="open" class="civic-preview-overlay" @click.self="close">
        <section class="civic-dialog" :class="`civic-dialog-${props.size}`">
            <header class="civic-workspace-header">
                <div>
                    <div v-if="eyebrow" class="civic-eyebrow">{{ eyebrow }}</div>
                    <h2>{{ title }}</h2>
                    <p v-if="description" class="civic-section-note">{{ description }}</p>
                </div>
                <button class="civic-button" type="button" @click="close">Close</button>
            </header>
            <div class="civic-dialog-body">
                <slot />
            </div>
            <footer v-if="$slots.actions" class="civic-dialog-actions">
                <slot name="actions" />
            </footer>
        </section>
    </div>
</template>
