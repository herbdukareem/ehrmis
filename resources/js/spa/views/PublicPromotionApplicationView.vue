<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import BrandedLoader from '../components/BrandedLoader.vue';
import { api, apiMessage } from '../lib/api';
import { appState } from '../stores/app';

const options = ref({ cycles: [], mdas: [] });
const busy = ref(true);
const submitting = ref(false);
const feedback = ref('');
const submitted = ref(null);
const form = reactive({
    cycle_id: '',
    mda_id: '',
    staff_number: '',
    legacy_cno: '',
    legacy_psn: '',
    surname: '',
    first_name: '',
    middle_name: '',
    email: '',
    phone: '',
    applicant_remarks: '',
});

const selectedCycle = computed(() => options.value.cycles.find((cycle) => cycle.id === Number(form.cycle_id)));
const mdasForCycle = computed(() => {
    if (!selectedCycle.value?.mda_id) return options.value.mdas;
    return options.value.mdas.filter((mda) => mda.id === selectedCycle.value.mda_id);
});

const load = async () => {
    busy.value = true;
    options.value = (await api.get('/public/promotion/options')).data.data;
    form.cycle_id = options.value.cycles[0]?.id ?? '';
    form.mda_id = mdasForCycle.value[0]?.id ?? '';
    busy.value = false;
};

const submit = async () => {
    submitting.value = true;
    feedback.value = '';
    submitted.value = null;
    try {
        submitted.value = (await api.post('/public/promotion/applications', form)).data.data;
    } catch (error) {
        feedback.value = apiMessage(error);
    } finally {
        submitting.value = false;
    }
};

onMounted(load);
</script>

<template>
    <main class="civic-public-page">
        <section class="civic-public-panel">
            <div class="civic-brand civic-public-brand">
                <img class="civic-brand-logo" :src="appState.branding.logo_url" :alt="`${appState.branding.name} logo`">
                <div>
                    <div class="civic-brand-title">{{ appState.branding.acronym }}</div>
                    <div class="civic-brand-subtitle">{{ appState.branding.name }}</div>
                </div>
            </div>

            <BrandedLoader v-if="busy" label="Loading promotion application..." />
            <template v-else>
                <div class="civic-section-heading">
                    <div>
                        <span class="civic-kicker">Annual Performance Assessment</span>
                        <h1>Staff promotion application</h1>
                    </div>
                </div>

                <div v-if="submitted" class="civic-feedback">
                    Application submitted. Reference: <strong>{{ submitted.application_number }}</strong>
                </div>
                <div v-if="feedback" class="civic-error">{{ feedback }}</div>

                <form v-if="!submitted" class="civic-form-grid" @submit.prevent="submit">
                    <label class="civic-field civic-field-wide">
                        <span>Promotion cycle</span>
                        <select v-model="form.cycle_id" required @change="form.mda_id = mdasForCycle[0]?.id ?? ''">
                            <option v-for="cycle in options.cycles" :key="cycle.id" :value="cycle.id">{{ cycle.title }} ({{ cycle.year }})</option>
                        </select>
                    </label>
                    <label class="civic-field civic-field-wide">
                        <span>MDA</span>
                        <select v-model="form.mda_id" required>
                            <option v-for="mda in mdasForCycle" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                        </select>
                    </label>
                    <label class="civic-field"><span>Staff number</span><input v-model="form.staff_number"></label>
                    <label class="civic-field"><span>CNO</span><input v-model="form.legacy_cno"></label>
                    <label class="civic-field"><span>PSN</span><input v-model="form.legacy_psn"></label>
                    <label class="civic-field"><span>Surname</span><input v-model="form.surname" required></label>
                    <label class="civic-field"><span>First name</span><input v-model="form.first_name" required></label>
                    <label class="civic-field"><span>Middle name</span><input v-model="form.middle_name"></label>
                    <label class="civic-field"><span>Email</span><input v-model="form.email" type="email"></label>
                    <label class="civic-field"><span>Phone</span><input v-model="form.phone"></label>
                    <label class="civic-field civic-field-wide"><span>Applicant remarks</span><textarea v-model="form.applicant_remarks" rows="4"></textarea></label>
                    <div class="civic-field civic-form-action">
                        <span>Submit APA form</span>
                        <button class="civic-button civic-button-primary" :disabled="submitting">{{ submitting ? 'Submitting...' : 'Submit application' }}</button>
                    </div>
                </form>
            </template>
        </section>
    </main>
</template>
