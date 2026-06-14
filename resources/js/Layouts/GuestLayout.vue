<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-ehrmis-bg px-4 py-12">
        <div class="flex flex-col items-center text-center">
            <Link href="/" class="flex h-16 w-16 items-center justify-center rounded-2xl bg-ehrmis-navy-900 p-3 shadow-ehrmis-pop">
                <ApplicationLogo class="h-full w-full fill-current text-ehrmis-primary-400" />
            </Link>
            <div class="mt-4 text-xs uppercase tracking-[0.3em] text-ehrmis-muted">HMB-eHRMIS</div>
            <div class="mt-2 text-2xl font-semibold text-ehrmis-text">Human Resource Management System</div>
            <div class="mt-2 max-w-md text-sm text-ehrmis-muted">
                Sign in to access staff records, legacy import review, movement workbooks, and budget operations.
            </div>
        </div>

        <div class="mt-6 w-full overflow-hidden rounded-ehrmis bg-white px-6 py-6 shadow-ehrmis-pop sm:max-w-md">
            <slot />
        </div>
    </div>
</template>
