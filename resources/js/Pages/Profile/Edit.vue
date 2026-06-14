<script setup>
import AppCard from '@/Components/AppCard.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Profile" />

    <AppLayout>
        <AppPageHeader
            title="Profile"
            subtitle="Manage your account's profile information, password, and security."
        />

        <div class="max-w-3xl space-y-6">
            <AppCard>
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                />
            </AppCard>

            <AppCard>
                <UpdatePasswordForm />
            </AppCard>

            <AppCard>
                <DeleteUserForm />
            </AppCard>
        </div>
    </AppLayout>
</template>
