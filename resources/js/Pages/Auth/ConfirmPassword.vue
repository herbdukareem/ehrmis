<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirm Password" />

        <div class="mb-4 text-sm text-ehrmis-muted">
            This is a secure area of the application. Please confirm your
            password before continuing.
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <AppTextInput
                id="password"
                v-model="form.password"
                label="Password"
                type="password"
                required
                autocomplete="current-password"
                autofocus
                :error="form.errors.password"
            />

            <div class="flex justify-end">
                <AppButton type="submit" :disabled="form.processing">
                    Confirm
                </AppButton>
            </div>
        </form>
    </GuestLayout>
</template>
