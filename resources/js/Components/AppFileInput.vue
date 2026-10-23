<script setup>
import { useId } from 'vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';
defineProps({ label: { type: String, required: true }, disabled: Boolean, accept: { type: String, default: '.pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt' }, error: { type: String, default: '' } });
const model = defineModel({ default: null });
const id = useId();
</script>
<template>
    <div class="min-w-0"><InputLabel :for="id" :value="label" class="ehrmis-label mb-1.5" /><input :id="id" type="file" :accept="accept" :disabled="disabled" class="ehrmis-input max-w-full" @change="model = $event.target.files?.[0] ?? null"><p class="ehrmis-help-text">PDF, images, Word, Excel, or text. Maximum 20 MB.</p><InputError :message="error" /></div>
</template>
