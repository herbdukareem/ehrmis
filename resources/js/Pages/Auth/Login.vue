<script setup>
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    localLoginHint: {
        type: Object,
        default: null,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Sign In" />

        <AppAlert v-if="status" tone="success" class="mb-4">
            {{ status }}
        </AppAlert>

        <div class="mb-6">
            <h1 class="text-xl font-semibold text-ehrmis-text">Sign in</h1>
            <p class="mt-1 text-sm text-ehrmis-muted">Use your eHRMIS account to continue.</p>
        </div>

        <AppAlert v-if="localLoginHint" tone="info" class="mb-4">
            Local sign-in: <strong>{{ localLoginHint.email }}</strong> / <strong>{{ localLoginHint.password }}</strong>
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

            <AppTextInput
                id="password"
                v-model="form.password"
                label="Password"
                type="password"
                required
                autocomplete="current-password"
                :error="form.errors.password"
            />

            <label class="flex items-center">
                <Checkbox name="remember" v-model:checked="form.remember" />
                <span class="ms-2 text-sm text-ehrmis-muted">Remember me</span>
            </label>

            <div class="flex items-center justify-end gap-4">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-sm text-ehrmis-muted underline hover:text-ehrmis-text"
                >
                    Forgot your password?
                </Link>

                <AppButton type="submit" :disabled="form.processing">
                    Log in
                </AppButton>
            </div>
        </form>
    </GuestLayout>
</template>
