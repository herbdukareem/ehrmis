<script setup>
defineProps({
    tabs: { type: Array, required: true },
    modelValue: { type: String, required: true },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <nav class="civic-tabs" aria-label="Page sections">
        <button
            v-for="tab in tabs"
            :key="tab.id"
            class="civic-tab"
            :class="{ active: modelValue === tab.id }"
            type="button"
            :aria-selected="modelValue === tab.id"
            @click="$emit('update:modelValue', tab.id)"
        >
            {{ tab.label }}
            <span v-if="tab.count !== undefined">{{ tab.count }}</span>
        </button>
    </nav>
</template>
