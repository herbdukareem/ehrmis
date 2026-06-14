<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();
const router = useRouter();
const busy = ref(false);
const loading = ref(true);
const feedback = ref('');
const options = ref({ mdas: [], statuses: [] });
const form = reactive({
    mda_id: '', staff_number: '', legacy_cno: '', legacy_psn: '', surname: '',
    first_name: '', middle_name: '', full_name: '', sex: '', date_of_birth: '',
    status: 'active', status_reason: '', status_effective_from: '',
    personal_detail: { lga: '', state_of_origin: '', phone: '', email: '', address: '', marital_status: '', file_no: '' },
});

const submit = async () => {
    busy.value = true;
    feedback.value = '';
    try {
        await api.put(`/staff/${route.params.id}`, form);
        await router.push(`/staff/${route.params.id}`);
    } catch (error) {
        feedback.value = apiMessage(error);
    } finally {
        busy.value = false;
    }
};

onMounted(async () => {
    const [staffResponse, optionsResponse] = await Promise.all([api.get(`/staff/${route.params.id}`), api.get('/staff/options')]);
    const staff = staffResponse.data.data;
    options.value = optionsResponse.data.data;
    Object.assign(form, {
        mda_id: staff.mda?.id ?? '', staff_number: staff.staff_number, legacy_cno: staff.legacy_cno ?? '',
        legacy_psn: staff.legacy_psn ?? '', surname: staff.surname, first_name: staff.first_name,
        middle_name: staff.middle_name ?? '', full_name: staff.full_name, sex: staff.sex ?? '',
        date_of_birth: staff.date_of_birth ?? '', status: staff.status,
        personal_detail: { ...form.personal_detail, ...(staff.personal_detail ?? {}) },
    });
    loading.value = false;
});
</script>

<template>
    <LoadingBlock v-if="loading" />
    <template v-else>
        <PageHeading eyebrow="Registry amendment" :title="`Edit ${form.full_name}`" description="Update the core identity record. Appointment, salary and allowance changes remain controlled workflows." />
        <form class="civic-workspace civic-edit-sheet" @submit.prevent="submit">
            <div class="civic-workspace-header">
                <div><div class="civic-eyebrow">Core record</div><h2>Officer particulars</h2></div>
                <div class="civic-action-cluster">
                    <RouterLink class="civic-button" :to="`/staff/${route.params.id}`">Cancel</RouterLink>
                    <button class="civic-button civic-button-primary" :disabled="busy">Save amendment</button>
                </div>
            </div>
            <div v-if="feedback" class="civic-error">{{ feedback }}</div>
            <div class="civic-form-grid">
                <label class="civic-field"><span>MDA</span><select v-model="form.mda_id" required><option v-for="mda in options.mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option></select></label>
                <label class="civic-field"><span>Staff number</span><input v-model="form.staff_number" required></label>
                <label class="civic-field"><span>Surname</span><input v-model="form.surname" required></label>
                <label class="civic-field"><span>First name</span><input v-model="form.first_name" required></label>
                <label class="civic-field"><span>Middle name</span><input v-model="form.middle_name"></label>
                <label class="civic-field"><span>Official full name</span><input v-model="form.full_name" required></label>
                <label class="civic-field"><span>Legacy CNO</span><input v-model="form.legacy_cno"></label>
                <label class="civic-field"><span>Legacy PSN</span><input v-model="form.legacy_psn"></label>
                <label class="civic-field"><span>Sex</span><select v-model="form.sex"><option value="">Not recorded</option><option value="male">Male</option><option value="female">Female</option></select></label>
                <label class="civic-field"><span>Date of birth</span><input v-model="form.date_of_birth" type="date"></label>
                <label class="civic-field"><span>Status</span><select v-model="form.status" required><option v-for="status in options.statuses" :key="status" :value="status">{{ status }}</option></select></label>
                <label class="civic-field"><span>Status reason</span><input v-model="form.status_reason" placeholder="Required when status changes"></label>
                <label class="civic-field"><span>Phone</span><input v-model="form.personal_detail.phone"></label>
                <label class="civic-field"><span>Email</span><input v-model="form.personal_detail.email" type="email"></label>
                <label class="civic-field"><span>LGA</span><input v-model="form.personal_detail.lga"></label>
                <label class="civic-field"><span>State of origin</span><input v-model="form.personal_detail.state_of_origin"></label>
                <label class="civic-field"><span>File number</span><input v-model="form.personal_detail.file_no"></label>
                <label class="civic-field civic-field-wide"><span>Address</span><textarea v-model="form.personal_detail.address" rows="3"></textarea></label>
            </div>
        </form>
    </template>
</template>
