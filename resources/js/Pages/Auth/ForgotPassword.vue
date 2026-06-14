<script setup>
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <div class="mb-4 text-sm text-ehrmis-muted">
            Forgot your password? No problem. Just let us know your email
            address and we will email you a password reset link that will allow
            you to choose a new one.
        </div>

        <AppAlert v-if="status" tone="success" class="mb-4">
            {{ status }}
        </AppAlert>

        <form class="space-y-4" @submit.prevent="submit">
            <AppTextInput
                id="email"
                v-model="form.email"
                label="Email"
                type="email"
                required
                autofocus
                autocomplete="username"
                :error="form.errors.email"
            />

            <div class="flex items-center justify-end">
                <AppButton type="submit" :disabled="form.processing">
                    Email Password Reset Link
                </AppButton>
            </div>
        </form>
    </GuestLayout>
</template>
