<script setup>
import { computed, reactive, ref } from 'vue';
import AppButton from '../../../Components/AppButton.vue';
import AppIcon from '../../../Components/AppIcon.vue';
import AppModal from '../../../Components/AppModal.vue';
import AppSelect from '../../../Components/AppSelect.vue';
import AppTextInput from '../../../Components/AppTextInput.vue';
import ProcurementTable from './ProcurementTable.vue';
import { api, apiMessage } from '../../lib/api';
import { bpBase } from '../../lib/budgetProcurement';
import { can } from '../../stores/auth';
import { pushToast } from '../../stores/app';

const props = defineProps({ record: Object, area: String });
const emit = defineEmits(['refresh']);
const writable = computed(() => can(props.area === 'vendors' ? 'manage-vendors' : 'manage-sourcing'));
const open = ref(false);
const deleting = ref(null);
const saving = ref(false);
const error = ref('');
const form = reactive({ title: '', document_type: 'supporting_document', mode: 'file', external_url: '', file: null });
const columns = [{ key: 'title', label: 'Document' }, { key: 'document_type', label: 'Type', type: 'words' }, { key: 'actions', label: '' }];
function start() {
    Object.assign(form, { title: '', document_type: 'supporting_document', mode: 'file', external_url: '', file: null });
    error.value = '';
    open.value = true;
}
async function save() {
    saving.value = true;
    error.value = '';
    try {
        const data = new FormData();
        data.set('title', form.title);
        data.set('document_type', form.document_type);
        if (form.mode === 'file' && form.file) data.set('file', form.file);
        if (form.mode === 'link') data.set('external_url', form.external_url);
        await api.post(`${bpBase}/${props.area}/${props.record.id}/documents`, data);
        open.value = false;
        emit('refresh');
        pushToast('Document attached.');
    } catch (failure) {
        error.value = Object.values(failure.response?.data?.errors ?? {}).flat().join(' ') || apiMessage(failure);
    } finally { saving.value = false; }
}
async function remove() {
    saving.value = true;
    error.value = '';
    try {
        await api.delete(`${bpBase}/documents/${deleting.value.id}`);
        deleting.value = null;
        emit('refresh');
        pushToast('Document deleted.');
    } catch (failure) { error.value = apiMessage(failure); }
    finally { saving.value = false; }
}
</script>

<template>
    <section class="bp-detail-section">
        <div class="bp-section-heading"><h2>Documents</h2><AppButton v-if="writable" size="sm" variant="outline" @click="start"><AppIcon name="plus" class="h-4 w-4" />Attach document</AppButton></div>
        <ProcurementTable v-if="record.documents?.length" :columns="columns" :rows="record.documents">
            <template #actions="{ row }">
                <div class="bp-row-actions">
                    <AppButton variant="ghost" size="sm" :href="row.external_url || `/api${bpBase}/documents/${row.id}/download`" external target="_blank" rel="noopener noreferrer" :title="row.external_url ? 'Open document link' : 'Download document'" :aria-label="row.external_url ? 'Open document link' : 'Download document'"><AppIcon name="download" class="h-4 w-4" /></AppButton>
                    <AppButton v-if="writable" variant="ghost" size="sm" title="Delete document" aria-label="Delete document" @click="deleting = row; error = ''"><AppIcon name="trash" class="h-4 w-4" /></AppButton>
                </div>
            </template>
        </ProcurementTable>
        <p v-else class="bp-muted">No documents attached.</p>
    </section>
    <AppModal :show="open" title="Attach document" :closeable="!saving" overflow-visible @close="open = false">
        <form id="bp-document-form" class="bp-form" @submit.prevent="save">
            <AppTextInput v-model="form.title" label="Document title" required maxlength="255" />
            <AppTextInput v-model="form.document_type" label="Document type" required maxlength="100" />
            <AppSelect v-model="form.mode" label="Source" :searchable="false" :options="[{ value: 'file', label: 'File' }, { value: 'link', label: 'Web link' }]" />
            <AppTextInput v-if="form.mode === 'link'" v-model="form.external_url" type="url" label="Document URL" required />
            <label v-else class="bp-textarea-label"><span class="ehrmis-label">File</span><input type="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" class="ehrmis-input" @change="form.file = $event.target.files[0]"></label>
            <p v-if="error" class="bp-error bp-form-wide" role="alert">{{ error }}</p>
        </form>
        <template #footer><AppButton variant="outline" :disabled="saving" @click="open = false">Cancel</AppButton><AppButton type="submit" form="bp-document-form" :disabled="saving"><AppIcon name="plus" class="h-4 w-4" />{{ saving ? 'Attaching...' : 'Attach' }}</AppButton></template>
    </AppModal>
    <AppModal :show="Boolean(deleting)" title="Delete document" :closeable="!saving" @close="deleting = null">
        <p>{{ deleting?.title }}</p><p v-if="error" class="bp-error" role="alert">{{ error }}</p>
        <template #footer><AppButton variant="outline" :disabled="saving" @click="deleting = null">Cancel</AppButton><AppButton variant="danger" :disabled="saving" @click="remove"><AppIcon name="trash" class="h-4 w-4" />Delete</AppButton></template>
    </AppModal>
</template>
