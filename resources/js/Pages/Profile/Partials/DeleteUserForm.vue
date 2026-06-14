<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppModal from '@/Components/AppModal.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="space-y-6">
        <header>
            <h2 class="text-base font-semibold text-ehrmis-text">
                Delete Account
            </h2>

            <p class="mt-1 text-sm text-ehrmis-muted">
                Once your account is deleted, all of its resources and data will
                be permanently deleted. Before deleting your account, please
                download any data or information that you wish to retain.
            </p>
        </header>

        <AppButton variant="danger" @click="confirmUserDeletion">Delete Account</AppButton>

        <AppModal
            :show="confirmingUserDeletion"
            title="Are you sure you want to delete your account?"
            @close="closeModal"
        >
            <p class="text-sm text-ehrmis-muted">
                Once your account is deleted, all of its resources and data
                will be permanently deleted. Please enter your password to
                confirm you would like to permanently delete your account.
            </p>

            <div class="mt-6">
                <AppTextInput
                    ref="passwordInput"
                    v-model="form.password"
                    type="password"
                    label="Password"
                    placeholder="Password"
                    :error="form.errors.password"
                    @keyup.enter="deleteUser"
                />
            </div>

            <template #footer>
                <AppButton variant="secondary" @click="closeModal">
                    Cancel
                </AppButton>

                <AppButton
                    variant="danger"
                    :disabled="form.processing"
                    @click="deleteUser"
                >
                    Delete Account
                </AppButton>
            </template>
        </AppModal>
    </section>
</template>
