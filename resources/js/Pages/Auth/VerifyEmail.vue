<script setup>
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />

        <div class="mb-4 text-sm text-ehrmis-muted">
            Thanks for signing up! Before getting started, could you verify your
            email address by clicking on the link we just emailed to you? If you
            didn't receive the email, we will gladly send you another.
        </div>

        <AppAlert v-if="verificationLinkSent" tone="success" class="mb-4">
            A new verification link has been sent to the email address you
            provided during registration.
        </AppAlert>

        <form @submit.prevent="submit">
            <div class="flex items-center justify-between">
                <AppButton type="submit" :disabled="form.processing">
                    Resend Verification Email
                </AppButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="text-sm text-ehrmis-muted underline hover:text-ehrmis-text"
                >
                    Log Out
                </Link>
            </div>
        </form>
    </GuestLayout>
</template>
