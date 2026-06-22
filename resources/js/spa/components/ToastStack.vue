<script setup>
import { appState, removeToast } from '../stores/app';
</script>

<template>
    <TransitionGroup name="civic-toast" tag="aside" class="civic-toast-stack">
        <section v-for="toast in appState.toasts" :key="toast.id" class="civic-toast" :data-tone="toast.tone">
            <div>
                <strong>{{ toast.tone === 'error' ? 'Action blocked' : 'Saved' }}</strong>
                <p>{{ toast.message }}</p>
            </div>
            <button class="civic-text-action" type="button" @click="removeToast(toast.id)">Dismiss</button>
        </section>
    </TransitionGroup>
</template>
