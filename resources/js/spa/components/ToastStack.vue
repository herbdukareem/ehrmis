<script setup>
import AppIcon from '../../Components/AppIcon.vue';
import { appState, removeToast } from '../stores/app';

const toneMeta = {
    success: {
        label: 'Success alert',
        iconPanelClass: 'civic-alert-modal-icon-success',
    },
    error: {
        label: 'Error alert',
        iconPanelClass: 'civic-alert-modal-icon-error',
    },
    warning: {
        label: 'Warning alert',
        iconPanelClass: 'civic-alert-modal-icon-warning',
    },
    info: {
        label: 'Information alert',
        iconPanelClass: 'civic-alert-modal-icon-info',
    },
};

const toastMeta = (toast) => toneMeta[toast.tone] ?? toneMeta.info;
</script>

<template>
    <TransitionGroup name="civic-toast" tag="aside" class="civic-toast-stack">
        <section
            v-for="toast in appState.toasts"
            :key="toast.id"
            class="civic-alert-modal"
            :data-tone="toast.tone"
            :role="toast.tone === 'error' ? 'alert' : 'status'"
            :aria-label="toastMeta(toast).label"
        >
            <div class="civic-alert-modal-icon" :class="toastMeta(toast).iconPanelClass">
                <AppIcon :name="toast.icon" />
            </div>
            <div class="civic-alert-modal-body">
                <header class="civic-alert-modal-header">
                    <div class="civic-alert-modal-copy">
                        <strong>{{ toast.title }}</strong>
                        <p>{{ toast.message }}</p>
                    </div>
                    <button
                        class="civic-alert-modal-close"
                        type="button"
                        :aria-label="toast.dismissLabel"
                        :title="toast.dismissLabel"
                        @click="removeToast(toast.id)"
                    >
                        <AppIcon name="close" />
                    </button>
                </header>
                <div v-if="toast.timeout > 0" class="civic-alert-modal-progress" aria-hidden="true">
                    <span :style="{ animationDuration: `${toast.timeout}ms` }"></span>
                </div>
            </div>
        </section>
    </TransitionGroup>
</template>
