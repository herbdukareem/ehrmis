<script setup>
import AppSidebar from '@/Components/AppSidebar.vue';
import AppTopbar from '@/Components/AppTopbar.vue';
import { ref } from 'vue';

const sidebarOpen = ref(false);
</script>

<template>
    <div class="min-h-screen bg-ehrmis-bg text-ehrmis-text">
        <div class="flex min-h-screen">
            <AppSidebar :open="sidebarOpen" @close="sidebarOpen = false" />

            <div class="flex min-w-0 flex-1 flex-col">
                <AppTopbar @toggle-sidebar="sidebarOpen = !sidebarOpen" />

                <main class="flex-1 p-4 md:p-6">
                    <div class="mx-auto max-w-7xl space-y-6">
                        <slot />
                    </div>
                </main>
            </div>
        </div>
    </div>
</template>
