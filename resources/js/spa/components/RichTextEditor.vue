<script setup>
import { ref, watch } from 'vue';

const props = defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);
const editor = ref(null);

watch(() => props.modelValue, (value) => {
    if (editor.value && editor.value.innerHTML !== value) editor.value.innerHTML = value || '';
});
const command = (name) => { document.execCommand(name); editor.value?.focus(); };
const update = () => emit('update:modelValue', editor.value?.innerHTML ?? '');
</script>

<template>
    <div class="civic-rich-editor">
        <div class="civic-rich-toolbar">
            <button type="button" @click="command('bold')"><strong>B</strong></button>
            <button type="button" @click="command('italic')"><em>I</em></button>
            <button type="button" @click="command('insertUnorderedList')">List</button>
        </div>
        <div ref="editor" class="civic-rich-surface" contenteditable="true" @input="update" v-html="modelValue"></div>
    </div>
</template>
