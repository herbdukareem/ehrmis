<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    initialMode: { type: String, default: 'document' },
});
const emit = defineEmits(['close', 'passport', 'pages']);
const mode = ref('document');
const video = ref(null);
const stream = ref(null);
const pages = ref([]);
const error = ref('');

watch(() => props.open, async (open) => {
    if (!open) {
        stopCamera();
        clearPages();
        return;
    }

    mode.value = props.initialMode;
    await nextTick();
    await startCamera();
});

const startCamera = async () => {
    error.value = '';

    if (!navigator.mediaDevices?.getUserMedia) {
        error.value = 'Camera access requires HTTPS or a browser-trusted local address.';
        return;
    }

    try {
        stream.value = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        video.value.srcObject = stream.value;
        await video.value.play();
    } catch {
        error.value = 'Camera access was blocked. Allow camera permission and try again.';
    }
};
const stopCamera = () => {
    stream.value?.getTracks().forEach((track) => track.stop());
    stream.value = null;
    if (video.value) video.value.srcObject = null;
};
const clearPages = () => {
    pages.value.forEach(({ url }) => URL.revokeObjectURL(url));
    pages.value = [];
};
const capturedFile = async (name) => {
    const canvas = document.createElement('canvas');
    canvas.width = video.value.videoWidth;
    canvas.height = video.value.videoHeight;
    canvas.getContext('2d').drawImage(video.value, 0, 0);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.94));
    return new File([blob], name, { type: 'image/jpeg' });
};
const capture = async () => {
    const file = await capturedFile(mode.value === 'passport' ? 'passport-camera.jpg' : `captured-page-${pages.value.length + 1}.jpg`);

    if (mode.value === 'passport') {
        emit('passport', file);
        close();
        return;
    }

    pages.value.push({ file, url: URL.createObjectURL(file) });
};
const removePage = (index) => {
    URL.revokeObjectURL(pages.value[index].url);
    pages.value.splice(index, 1);
};
const usePages = () => {
    emit('pages', pages.value.map(({ file }) => file));
    close();
};
const close = () => {
    stopCamera();
    clearPages();
    emit('close');
};

onBeforeUnmount(() => {
    stopCamera();
    clearPages();
});
</script>

<template>
    <div v-if="open" class="civic-preview-overlay" @click.self="close">
        <section class="civic-camera-modal">
            <header class="civic-workspace-header">
                <div><div class="civic-eyebrow">USB or built-in webcam</div><h2>Camera capture</h2></div>
                <button class="civic-button" type="button" @click="close">Close</button>
            </header>
            <nav class="civic-analytics-nav">
                <button type="button" :class="{ active: mode === 'passport' }" @click="mode = 'passport'; clearPages()">Passport</button>
                <button type="button" :class="{ active: mode === 'document' }" @click="mode = 'document'">Multi-page document</button>
            </nav>
            <div class="civic-camera-modal-body">
                <div v-if="error" class="civic-error">{{ error }}</div>
                <video ref="video" class="civic-camera" autoplay playsinline muted></video>
                <p class="civic-muted">
                    {{ mode === 'passport' ? 'Capture one clear passport image.' : 'Capture each page in order, then add all pages to the document composer.' }}
                </p>
                <div class="civic-action-cluster">
                    <button class="civic-button" type="button" :disabled="stream" @click="startCamera">Start camera</button>
                    <button class="civic-button civic-button-primary" type="button" :disabled="!stream" @click="capture">
                        {{ mode === 'passport' ? 'Capture passport' : `Capture page ${pages.length + 1}` }}
                    </button>
                    <button class="civic-button" type="button" :disabled="!stream" @click="stopCamera">Stop camera</button>
                    <button v-if="mode === 'document'" class="civic-button civic-button-primary" type="button" :disabled="!pages.length" @click="usePages">Use {{ pages.length }} page(s)</button>
                </div>
                <div v-if="mode === 'document' && pages.length" class="civic-page-thumbnails">
                    <button v-for="(page, index) in pages" :key="page.url" type="button" @click="removePage(index)">
                        <img :src="page.url" alt=""><strong>Page {{ index + 1 }}</strong><small>Click to remove</small>
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>
