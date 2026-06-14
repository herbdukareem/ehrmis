<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { api, apiMessage } from '../lib/api';
import CameraCaptureModal from './CameraCaptureModal.vue';

const props = defineProps({ staff: { type: Object, required: true }, canUpdate: { type: Boolean, default: false } });
const emit = defineEmits(['changed']);
const busy = ref(false);
const error = ref('');
const feedback = ref('');
const passportFile = ref(null);
const passportPreview = ref('');
const documentForm = ref({ title: '', document_type: '', notes: '', compile_pdf: true });
const pendingPages = ref([]);
const preview = ref(null);
const cameraOpen = ref(false);
const cameraMode = ref('document');

const setPassportFile = (file) => {
    if (passportPreview.value) URL.revokeObjectURL(passportPreview.value);
    passportFile.value = file;
    passportPreview.value = file ? URL.createObjectURL(file) : '';
};
const selectPassport = (event) => setPassportFile(event.target.files?.[0] ?? null);
const addPages = (files) => [...files].forEach((file) => pendingPages.value.push({ file, url: URL.createObjectURL(file) }));
const selectPages = (event) => addPages(event.target.files ?? []);
const removePendingPage = (index) => {
    URL.revokeObjectURL(pendingPages.value[index].url);
    pendingPages.value.splice(index, 1);
};
const openCamera = (mode) => {
    cameraMode.value = mode;
    cameraOpen.value = true;
};
const run = async (action) => {
    busy.value = true; error.value = ''; feedback.value = '';
    try { feedback.value = await action(); } catch (exception) { error.value = apiMessage(exception); } finally { busy.value = false; }
};
const uploadPassport = () => run(async () => {
    const form = new FormData();
    form.append('passport', passportFile.value);
    await api.post(`/staff/${props.staff.id}/passport`, form);
    setPassportFile(null); emit('changed');
    return 'Staff passport updated successfully.';
});
const uploadDocument = () => run(async () => {
    const form = new FormData();
    form.append('title', documentForm.value.title);
    form.append('document_type', documentForm.value.document_type);
    form.append('notes', documentForm.value.notes);
    form.append('compile_pdf', documentForm.value.compile_pdf ? '1' : '0');
    pendingPages.value.forEach(({ file }) => form.append('pages[]', file));
    await api.post(`/staff/${props.staff.id}/documents`, form);
    pendingPages.value.forEach(({ url }) => URL.revokeObjectURL(url));
    pendingPages.value = [];
    documentForm.value = { title: '', document_type: '', notes: '', compile_pdf: true };
    emit('changed');
    return 'Staff document uploaded successfully.';
});
const deleteDocument = (document) => {
    if (!confirm(`Delete "${document.title}" and all its pages?`)) return;
    run(async () => {
        await api.delete(`/staff/${props.staff.id}/documents/${document.id}`);
        emit('changed');
        return 'Staff document deleted successfully.';
    });
};

onBeforeUnmount(() => {
    if (passportPreview.value) URL.revokeObjectURL(passportPreview.value);
    pendingPages.value.forEach(({ url }) => URL.revokeObjectURL(url));
});
</script>

<template>
    <section class="civic-media-workspace">
        <div v-if="feedback" class="civic-feedback">{{ feedback }}</div>
        <div v-if="error" class="civic-error">{{ error }}</div>
        <div class="civic-media-grid">
            <article class="civic-media-card">
                <div class="civic-eyebrow">Identity image</div><h2>Staff passport</h2>
                <img v-if="passportPreview || staff.passport_url" class="civic-passport" :src="passportPreview || staff.passport_url" alt="Staff passport">
                <div v-else class="civic-passport civic-passport-empty">No passport</div>
                <template v-if="canUpdate">
                    <label class="civic-field"><span>Select passport image</span><input type="file" accept="image/jpeg,image/png,image/webp" @change="selectPassport"></label>
                    <div class="civic-action-cluster">
                        <button class="civic-button" type="button" @click="openCamera('passport')">Capture with camera</button>
                        <button class="civic-button civic-button-primary" :disabled="!passportFile || busy" @click="uploadPassport">Upload passport</button>
                    </div>
                </template>
            </article>
            <article v-if="canUpdate" class="civic-media-card">
                <div class="civic-eyebrow">Camera workspace</div><h2>Reusable camera capture</h2>
                <p class="civic-muted">Open one camera modal for passport capture or repeated multi-page document capture.</p>
                <div class="civic-action-cluster">
                    <button class="civic-button" type="button" @click="openCamera('passport')">Capture passport</button>
                    <button class="civic-button civic-button-primary" type="button" @click="openCamera('document')">Capture document pages</button>
                </div>
            </article>
        </div>
        <article v-if="canUpdate" class="civic-media-card">
            <div class="civic-eyebrow">New staff document</div><h2>Upload or capture a multi-page document</h2>
            <div class="civic-form-grid">
                <label class="civic-field"><span>Document title</span><input v-model="documentForm.title" placeholder="e.g. Appointment letter"></label>
                <label class="civic-field"><span>Document type</span><input v-model="documentForm.document_type" placeholder="Appointment, credential, ID..."></label>
                <label class="civic-field"><span>Add pages</span><input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" @change="selectPages"></label>
                <label class="civic-field civic-field-wide"><span>Notes</span><textarea v-model="documentForm.notes" rows="2"></textarea></label>
            </div>
            <label class="civic-check">
                <input v-model="documentForm.compile_pdf" type="checkbox">
                <span>Generate one private PDF from image pages</span>
            </label>
         
            <div v-if="pendingPages.length" class="civic-page-thumbnails">
                <button v-for="(page, index) in pendingPages" :key="page.url" type="button" @click="removePendingPage(index)">
                    <img v-if="page.file.type.startsWith('image/')" :src="page.url" alt=""><span v-else>PDF</span>
                    <strong>Page {{ index + 1 }}</strong><small>Click to remove</small>
                </button>
            </div>
            <div class="civic-action-cluster">
                <button class="civic-button" type="button" @click="openCamera('document')">Capture more pages</button>
                <button class="civic-button civic-button-primary" type="button" :disabled="!documentForm.title || !pendingPages.length || busy" @click="uploadDocument">Upload {{ pendingPages.length }} page(s)</button>
            </div>
        </article>
        <article class="civic-media-card">
            <div class="civic-analysis-heading"><div><div class="civic-eyebrow">Private personnel file</div><h2>Staff documents</h2></div><span>{{ staff.documents?.length ?? 0 }} documents</span></div>
            <div v-if="staff.documents?.length" class="civic-document-list">
                <section v-for="document in staff.documents" :key="document.id">
                    <div><strong>{{ document.title }}</strong><small>{{ document.document_type || 'General document' }} · {{ document.pages.length }} page(s)</small></div>
                    <div class="civic-action-cluster">
                        <button v-if="document.compiled_pdf_url" class="civic-button civic-button-primary" type="button" @click="preview = { preview_url: document.compiled_pdf_url, mime_type: 'application/pdf', original_name: `${document.title}.pdf` }">Single PDF</button>
                        <button v-for="page in document.pages" :key="page.id" class="civic-button" type="button" @click="preview = page">Page {{ page.page_number }}</button>
                        <button v-if="canUpdate" class="civic-button civic-button-danger" type="button" @click="deleteDocument(document)">Delete</button>
                    </div>
                </section>
            </div>
            <p v-else class="civic-muted">No staff documents uploaded.</p>
        </article>
        <CameraCaptureModal :open="cameraOpen" :initial-mode="cameraMode" @close="cameraOpen = false" @passport="setPassportFile" @pages="addPages" />
        <div v-if="preview" class="civic-preview-overlay" @click.self="preview = null">
            <div class="civic-preview-panel">
                <div class="civic-workspace-header"><strong>{{ preview.original_name }}</strong><button class="civic-button" @click="preview = null">Close</button></div>
                <img v-if="preview.mime_type.startsWith('image/')" :src="preview.preview_url" alt="Document preview">
                <iframe v-else :src="preview.preview_url" title="Document preview"></iframe>
            </div>
        </div>
    </section>
</template>
