<script setup>
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-base font-semibold text-ehrmis-text">
                Profile Information
            </h2>

            <p class="mt-1 text-sm text-ehrmis-muted">
                Update your account's profile information and email address.
            </p>
        </header>

        <form
            class="mt-6 space-y-6"
            @submit.prevent="form.patch(route('profile.update'))"
        >
            <AppTextInput
                v-model="form.name"
                label="Name"
                required
                autofocus
                autocomplete="name"
                :error="form.errors.name"
            />

            <AppTextInput
                v-model="form.email"
                type="email"
                label="Email"
                required
                autocomplete="username"
                :error="form.errors.email"
            />

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <AppAlert tone="warning">
                    Your email address is unverified.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="font-semibold underline hover:text-amber-900"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </AppAlert>

                <AppAlert
                    v-show="status === 'verification-link-sent'"
                    tone="success"
                    class="mt-2"
                >
                    A new verification link has been sent to your email address.
                </AppAlert>
            </div>

            <div class="flex items-center gap-4">
                <AppButton type="submit" :disabled="form.processing">Save</AppButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-ehrmis-muted"
                    >
                        Saved.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
