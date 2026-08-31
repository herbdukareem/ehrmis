<script setup>
defineProps({
    eyebrow: { type: String, default: '' },
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <header class="civic-page-heading">
        <div>
            <div v-if="eyebrow" class="civic-eyebrow">{{ eyebrow }}</div>
            <h1>{{ title }}</h1>
            <p v-if="description">{{ description }}</p>
        </div>
        <div class="civic-page-actions"><slot /></div>
    </header>
</template>
