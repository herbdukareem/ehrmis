<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <div class="mb-6">
            <h1 class="text-xl font-semibold text-ehrmis-text">Create account</h1>
            <p class="mt-1 text-sm text-ehrmis-muted">Register a new eHRMIS user profile.</p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <AppTextInput
                id="name"
                v-model="form.name"
                label="Name"
                type="text"
                required
                autofocus
                autocomplete="name"
                :error="form.errors.name"
            />

            <AppTextInput
                id="email"
                v-model="form.email"
                label="Email"
                type="email"
                required
                autocomplete="username"
                :error="form.errors.email"
            />

            <AppTextInput
                id="password"
                v-model="form.password"
                label="Password"
                type="password"
                required
                autocomplete="new-password"
                :error="form.errors.password"
            />

            <AppTextInput
                id="password_confirmation"
                v-model="form.password_confirmation"
                label="Confirm Password"
                type="password"
                required
                autocomplete="new-password"
                :error="form.errors.password_confirmation"
            />

            <div class="flex items-center justify-end gap-4">
                <Link
                    :href="route('login')"
                    class="text-sm text-ehrmis-muted underline hover:text-ehrmis-text"
                >
                    Already registered?
                </Link>

                <AppButton type="submit" :disabled="form.processing">
                    Register
                </AppButton>
            </div>
        </form>
    </GuestLayout>
</template>
