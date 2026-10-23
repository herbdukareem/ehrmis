<script setup>
import { ref } from 'vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
defineProps({ options: { type: Object, required: true }, busy: Boolean });
const emit = defineEmits(['save']);
const actions = ref([]);
const add = () => actions.value.push({ subject: '', priority: 'normal', confidentiality: 'standard', assigned_to: '', department_id: null, due_at: '', details: { instruction: '' } });
</script>
<template>
    <AppCard title="Follow-up actions" subtitle="Share only the instruction needed by each recipient. The linked source document keeps its own access restrictions. PAs prepare drafts; executives issue them.">
        <form class="space-y-4" @submit.prevent="emit('save', actions)">
            <AppCard v-for="(action, index) in actions" :key="index" :title="`Action ${index + 1}`">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <AppTextInput v-model="action.subject" label="Action subject" required />
                    <AppSelect v-model="action.assigned_to" label="Responsible officer" :options="options.users" option-value="id" option-label="name" placeholder="Choose officer" />
                    <AppSelect v-model="action.department_id" label="Department" :options="options.departments" option-value="id" option-label="name" placeholder="Choose department" />
                    <AppTextInput v-model="action.due_at" label="Deadline (office time)" type="datetime-local" required />
                    <AppTextarea v-model="action.details.instruction" label="Instruction shared with the recipient" required class="md:col-span-2" />
                </div>
                <AppButton class="mt-3" variant="outline" :disabled="busy" @click="actions.splice(index, 1)">Remove action</AppButton>
            </AppCard>
            <div class="flex flex-wrap gap-3"><AppButton variant="outline" :disabled="busy || actions.length >= 20" @click="add">Add follow-up action</AppButton><AppButton v-if="actions.length" type="submit" :disabled="busy || actions.some(action => !action.assigned_to)">Save follow-up actions</AppButton></div>
        </form>
    </AppCard>
</template>
