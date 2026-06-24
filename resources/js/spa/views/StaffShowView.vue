<script setup>
import { computed, onMounted, ref } from 'vue';
import AppCard from '@/Components/AppCard.vue';
import AppDateInput from '@/Components/AppDateInput.vue';
import AppModal from '../components/AppModal.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useRoute } from 'vue-router';
import AppTabs from '../components/AppTabs.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StaffMediaPanel from '../components/StaffMediaPanel.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { pushToast } from '../stores/app';

const route = useRoute();
const staff = ref(null);
const activeTab = ref('details');
const selectedAllowanceIds = ref([]);
const allowanceBusy = ref(false);
const appointmentModalOpen = ref(false);
const appointmentBusy = ref(false);
const appointmentErrors = ref({});
const appointmentOptions = ref(null);

const emptyAppointmentForm = () => ({
    department_id: '',
    station_id: '',
    location_name: '',
    cadre_id: '',
    rank_id: '',
    staff_category: '',
    initial_rank: '',
    date_first_appointment: '',
    date_last_promotion: '',
    expected_retirement_date: '',
    next_promotion_date: '',
    employment_status: 'active',
    effective_from: '',
    salary_scale_id: '',
    level: '',
    step: '',
});

const appointmentForm = ref(emptyAppointmentForm());

const eligibleAllowances = computed(() => staff.value?.allowance_assignments?.filter((item) => item.is_eligible) ?? []);
const appointmentDepartments = computed(() => (appointmentOptions.value?.departments ?? [])
    .filter((item) => Number(item.mda_id) === Number(staff.value?.mda?.id)));
const appointmentStations = computed(() => (appointmentOptions.value?.stations ?? [])
    .filter((item) => Number(item.mda_id) === Number(staff.value?.mda?.id)));
const appointmentCadres = computed(() => {
    const departmentId = Number(appointmentForm.value.department_id || 0);
    const departmentIds = appointmentDepartments.value.map((item) => Number(item.id));

    return (appointmentOptions.value?.cadres ?? []).filter((item) => (
        departmentId ? Number(item.department_id) === departmentId : departmentIds.includes(Number(item.department_id))
    ));
});
const appointmentSalaryScales = computed(() => (appointmentOptions.value?.salary_scales ?? [])
    .filter((item) => Number(item.mda_id) === Number(staff.value?.mda?.id)));
const appointmentRanks = computed(() => {
    const cadreId = Number(appointmentForm.value.cadre_id || 0);
    const salaryScaleId = Number(appointmentForm.value.salary_scale_id || 0);

    return (appointmentOptions.value?.ranks ?? []).filter((item) => {
        if (cadreId && Number(item.cadre_id) !== cadreId) {
            return false;
        }

        if (salaryScaleId && Number(item.salary_scale_id) !== salaryScaleId) {
            return false;
        }

        return true;
    });
});
const tabs = computed(() => [
    { id: 'details', label: 'Current details' },
    { id: 'eligibility', label: 'Allowance eligibility', count: eligibleAllowances.value.length },
    { id: 'record-slip', label: 'Record slip' },
    { id: 'documents', label: 'Documents', count: staff.value?.documents?.length ?? 0 },
]);

const money = (value) => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatDate = (value) => {
    if (!value) return '-';

    return new Date(value).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};
const prettifyLabel = (value) => String(value ?? '')
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase());
const inputValue = (value) => value ?? '';

const setStaff = (data) => {
    staff.value = data;
    selectedAllowanceIds.value = eligibleAllowances.value.map((item) => item.allowance_type_id);
};

const load = async () => setStaff((await api.get(`/staff/${route.params.id}`)).data.data);

const loadAppointmentOptions = async () => {
    if (appointmentOptions.value) {
        return;
    }

    appointmentOptions.value = (await api.get('/staff/options')).data.data;
};

const primeAppointmentForm = () => {
    appointmentErrors.value = {};
    appointmentForm.value = {
        department_id: inputValue(staff.value?.current_employment?.department_id),
        station_id: inputValue(staff.value?.current_employment?.station_id),
        location_name: inputValue(staff.value?.current_employment?.location_name),
        cadre_id: inputValue(staff.value?.current_employment?.cadre_id),
        rank_id: inputValue(staff.value?.current_employment?.rank_id),
        staff_category: inputValue(staff.value?.current_employment?.staff_category),
        initial_rank: inputValue(staff.value?.current_employment?.initial_rank),
        date_first_appointment: inputValue(staff.value?.current_employment?.date_first_appointment),
        date_last_promotion: inputValue(staff.value?.current_employment?.date_last_promotion),
        expected_retirement_date: inputValue(staff.value?.current_employment?.expected_retirement_date),
        next_promotion_date: inputValue(staff.value?.current_employment?.next_promotion_date),
        employment_status: inputValue(staff.value?.current_employment?.employment_status || 'active'),
        effective_from: '',
        salary_scale_id: inputValue(staff.value?.current_salary_placement?.salary_scale_id),
        level: inputValue(staff.value?.current_salary_placement?.level),
        step: inputValue(staff.value?.current_salary_placement?.step),
    };
};

const openAppointmentModal = async () => {
    try {
        await loadAppointmentOptions();
        primeAppointmentForm();
        appointmentModalOpen.value = true;
    } catch (exception) {
        pushToast(apiMessage(exception), 'error', 4200);
    }
};

const closeAppointmentModal = () => {
    appointmentModalOpen.value = false;
    appointmentBusy.value = false;
    appointmentErrors.value = {};
};

const appointmentPayload = () => {
    const nullable = (value) => value === '' ? null : value;

    return {
        department_id: nullable(appointmentForm.value.department_id),
        station_id: nullable(appointmentForm.value.station_id),
        location_name: nullable(appointmentForm.value.location_name),
        cadre_id: nullable(appointmentForm.value.cadre_id),
        rank_id: nullable(appointmentForm.value.rank_id),
        staff_category: nullable(appointmentForm.value.staff_category),
        initial_rank: nullable(appointmentForm.value.initial_rank),
        date_first_appointment: nullable(appointmentForm.value.date_first_appointment),
        date_last_promotion: nullable(appointmentForm.value.date_last_promotion),
        expected_retirement_date: nullable(appointmentForm.value.expected_retirement_date),
        next_promotion_date: nullable(appointmentForm.value.next_promotion_date),
        employment_status: appointmentForm.value.employment_status || 'active',
        effective_from: nullable(appointmentForm.value.effective_from),
        salary_scale_id: nullable(appointmentForm.value.salary_scale_id),
        level: nullable(appointmentForm.value.level),
        step: nullable(appointmentForm.value.step),
    };
};

const saveAllowances = async () => {
    allowanceBusy.value = true;

    try {
        const response = await api.put(`/staff/${staff.value.id}/allowances`, {
            assignments: staff.value.allowance_types.map((type) => ({
                allowance_type_id: type.id,
                is_eligible: selectedAllowanceIds.value.includes(type.id),
            })),
        });

        setStaff(response.data.data);
        pushToast(response.data.message);
    } catch (exception) {
        pushToast(apiMessage(exception), 'error', 4200);
    } finally {
        allowanceBusy.value = false;
    }
};

const saveAppointment = async () => {
    appointmentBusy.value = true;
    appointmentErrors.value = {};

    try {
        const response = await api.put(`/staff/${staff.value.id}/appointment`, appointmentPayload());
        setStaff(response.data.data);
        closeAppointmentModal();
        pushToast(response.data.message);
    } catch (exception) {
        appointmentErrors.value = exception?.response?.data?.errors ?? {};
        pushToast(apiMessage(exception), 'error', 4200);
        appointmentBusy.value = false;
    }
};

onMounted(load);
</script>

<template>
    <LoadingBlock v-if="!staff" />
    <template v-else>
        <PageHeading :eyebrow="staff.staff_number" :title="staff.full_name" :description="`${staff.mda?.name ?? 'Unassigned MDA'} / ${staff.current_employment?.department_name ?? 'No department'}`">
            <RouterLink v-if="staff.can_update" class="civic-button" :to="`/staff/${staff.id}/edit`">Edit record</RouterLink>
            <StatusPill :status="staff.status" />
        </PageHeading>

        <AppTabs v-model="activeTab" :tabs="tabs" />

        <section v-if="activeTab === 'details'" class="civic-record-sheet">
            <aside class="civic-record-index">
                <div class="civic-eyebrow">Official record</div>
                <dl>
                    <div><dt>Staff number</dt><dd>{{ staff.staff_number }}</dd></div>
                    <div><dt>Legacy CNO / PSN</dt><dd>{{ staff.legacy_cno ?? '-' }} / {{ staff.legacy_psn ?? '-' }}</dd></div>
                    <div><dt>Sex</dt><dd>{{ staff.sex ?? '-' }}</dd></div>
                    <div><dt>Date of birth</dt><dd>{{ formatDate(staff.date_of_birth) }}</dd></div>
                    <div><dt>Status</dt><dd>{{ staff.status }}</dd></div>
                    <div><dt>Retirement state</dt><dd>{{ prettifyLabel(staff.retirement_state) }}</dd></div>
                </dl>
            </aside>

            <div class="civic-record-body">
                <article>
                    <div class="civic-section-head">
                        <h2>Current appointment</h2>
                        <button
                            v-if="staff.can_update_appointment"
                            class="civic-button civic-button-primary"
                            type="button"
                            @click="openAppointmentModal"
                        >
                            Edit appointment
                        </button>
                    </div>
                    <dl class="civic-detail-grid">
                        <div><dt>MDA</dt><dd>{{ staff.mda?.name ?? '-' }}</dd></div>
                        <div><dt>Department</dt><dd>{{ staff.current_employment?.department_name ?? '-' }}</dd></div>
                        <div><dt>Station</dt><dd>{{ staff.current_employment?.station_name ?? '-' }}</dd></div>
                        <div><dt>Cadre</dt><dd>{{ staff.current_employment?.cadre_name ?? '-' }}</dd></div>
                        <div><dt>Rank</dt><dd>{{ staff.current_employment?.rank_name ?? '-' }}</dd></div>
                        <div><dt>First appointment</dt><dd>{{ formatDate(staff.current_employment?.date_first_appointment) }}</dd></div>
                        <div><dt>Last promotion</dt><dd>{{ formatDate(staff.current_employment?.date_last_promotion) }}</dd></div>
                        <div><dt>Retirement date</dt><dd>{{ formatDate(staff.current_employment?.expected_retirement_date) }}</dd></div>
                        <div><dt>Employment status</dt><dd>{{ prettifyLabel(staff.current_employment?.employment_status) }}</dd></div>
                    </dl>
                </article>

                <article>
                    <h2>Salary position</h2>
                    <dl class="civic-detail-grid">
                        <div><dt>Scale/Level/Step</dt><dd>{{ staff.current_salary_placement?.salary_scale_code ?? '-' }} {{ staff.current_salary_placement?.level ?? '-' }}/{{ staff.current_salary_placement?.step ?? '-' }}</dd></div>
                        <div><dt>Placement effective</dt><dd>{{ formatDate(staff.current_salary_placement?.effective_from) }}</dd></div>
                        <div><dt>Basic salary</dt><dd>{{ money(staff.salary_summary?.basic_salary) }}</dd></div>
                        <div><dt>Eligible allowances</dt><dd>{{ money(staff.salary_summary?.total_allowances) }}</dd></div>
                        <div><dt>Calculated gross</dt><dd>{{ money(staff.salary_summary?.calculated_gross_salary) }}</dd></div>
                    </dl>
                </article>

                <article>
                    <h2>Qualifications</h2>
                    <div class="civic-tag-line">
                        <span v-for="item in staff.qualifications" :key="item.id">{{ item.qualification_type?.name ?? item.qualification_name }}</span>
                        <span v-if="!staff.qualifications?.length">No qualifications recorded</span>
                    </div>
                </article>
            </div>
        </section>

        <section v-else-if="activeTab === 'eligibility'" class="civic-workspace civic-allowance-workspace">
            <div class="civic-workspace-header">
                <div>
                    <div class="civic-eyebrow">Current eligibility</div>
                    <h2>Allowances</h2>
                </div>
                <div class="civic-record-count">{{ eligibleAllowances.length }} eligible</div>
            </div>

            <div class="civic-allowance-editor">
                <div v-if="staff.can_update_allowances" class="civic-check-grid civic-allowance-check-grid">
                    <label v-for="type in staff.allowance_types" :key="type.id" class="civic-check">
                        <input v-model="selectedAllowanceIds" type="checkbox" :value="type.id">
                        <span>{{ type.name }}</span>
                    </label>
                </div>

                <div v-else class="civic-tag-line">
                    <span v-for="item in eligibleAllowances" :key="item.id">{{ item.allowance_name }}</span>
                    <span v-if="!eligibleAllowances.length">No eligible allowances recorded</span>
                </div>
            </div>

            <button
                v-if="staff.can_update_allowances"
                class="civic-button civic-button-primary civic-button-wide"
                type="button"
                :disabled="allowanceBusy"
                @click="saveAllowances"
            >
                {{ allowanceBusy ? 'Recomputing...' : 'Save allowances and recompute gross' }}
            </button>
        </section>

        <section v-else-if="activeTab === 'record-slip'" class="civic-workspace civic-slip-panel">
            <div class="civic-workspace-header">
                <div>
                    <div class="civic-eyebrow">Printable slip</div>
                    <h2>Staff record slip</h2>
                    <p class="civic-section-note">Download the official MDA letterhead slip for filing, review, and verification.</p>
                </div>
                <a class="civic-button civic-button-primary" :href="`/api/staff/${staff.id}/record-slip`" target="_blank" rel="noopener">
                    Download PDF
                </a>
            </div>
        </section>

        <StaffMediaPanel v-else :staff="staff" :can-update="staff.can_update" @changed="load" />

        <AppModal
            :open="appointmentModalOpen"
            eyebrow="Current appointment"
            title="Edit appointment details"
            description="Update current deployment, retirement dates, and salary placement with one permission-controlled action."
            size="wide"
            @close="closeAppointmentModal"
        >
            <div class="civic-dialog-stack">
                <AppCard title="Employment Record" subtitle="Posting, cadre, rank, and appointment dates.">
                    <div class="civic-form-grid">
                        <label class="civic-native-field">
                            <InputLabel value="Department" class="ehrmis-label mb-1.5" />
                            <select v-model="appointmentForm.department_id" class="ehrmis-select civic-native-select" :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': appointmentErrors.department_id?.[0] }">
                                <option value="">Select department</option>
                                <option v-for="item in appointmentDepartments" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                            <InputError :message="appointmentErrors.department_id?.[0]" />
                        </label>
                        <label class="civic-native-field">
                            <InputLabel value="Station" class="ehrmis-label mb-1.5" />
                            <select v-model="appointmentForm.station_id" class="ehrmis-select civic-native-select" :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': appointmentErrors.station_id?.[0] }">
                                <option value="">Select station</option>
                                <option v-for="item in appointmentStations" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                            <InputError :message="appointmentErrors.station_id?.[0]" />
                        </label>
                        <label class="civic-native-field">
                            <InputLabel value="Cadre" class="ehrmis-label mb-1.5" />
                            <select v-model="appointmentForm.cadre_id" class="ehrmis-select civic-native-select" :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': appointmentErrors.cadre_id?.[0] }">
                                <option value="">Select cadre</option>
                                <option v-for="item in appointmentCadres" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                            <InputError :message="appointmentErrors.cadre_id?.[0]" />
                        </label>
                        <label class="civic-native-field">
                            <InputLabel value="Rank" class="ehrmis-label mb-1.5" />
                            <select v-model="appointmentForm.rank_id" class="ehrmis-select civic-native-select" :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': appointmentErrors.rank_id?.[0] }">
                                <option value="">Select rank</option>
                                <option v-for="item in appointmentRanks" :key="item.id" :value="item.id">{{ item.name }} (Level {{ item.level }})</option>
                            </select>
                            <InputError :message="appointmentErrors.rank_id?.[0]" />
                        </label>
                        <AppTextInput v-model="appointmentForm.location_name" label="Location" placeholder="Office or posting location" />
                        <label class="civic-native-field">
                            <InputLabel value="Employment status" class="ehrmis-label mb-1.5" />
                            <select v-model="appointmentForm.employment_status" class="ehrmis-select civic-native-select" :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': appointmentErrors.employment_status?.[0] }">
                                <option value="active">Active</option>
                                <option value="retired">Retired</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <InputError :message="appointmentErrors.employment_status?.[0]" />
                        </label>
                        <AppDateInput v-model="appointmentForm.date_first_appointment" label="First appointment" :error="appointmentErrors.date_first_appointment?.[0]" />
                        <AppDateInput v-model="appointmentForm.date_last_promotion" label="Last promotion" :error="appointmentErrors.date_last_promotion?.[0]" />
                        <AppDateInput v-model="appointmentForm.expected_retirement_date" label="Retirement date" :error="appointmentErrors.expected_retirement_date?.[0]" />
                        <AppDateInput v-model="appointmentForm.next_promotion_date" label="Next promotion date" :error="appointmentErrors.next_promotion_date?.[0]" />
                        <AppTextInput v-model="appointmentForm.staff_category" label="Staff category" />
                        <AppTextInput v-model="appointmentForm.initial_rank" label="Initial rank note" />
                    </div>
                </AppCard>

                <AppCard title="Salary Placement" subtitle="Current scale, level, step, and effective date.">
                    <div class="civic-form-grid">
                        <label class="civic-native-field">
                            <InputLabel value="Salary scale" class="ehrmis-label mb-1.5" />
                            <select v-model="appointmentForm.salary_scale_id" class="ehrmis-select civic-native-select" :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': appointmentErrors.salary_scale_id?.[0] }">
                                <option value="">Select salary scale</option>
                                <option v-for="item in appointmentSalaryScales" :key="item.id" :value="item.id">{{ item.code }} - {{ item.name }}</option>
                            </select>
                            <InputError :message="appointmentErrors.salary_scale_id?.[0]" />
                        </label>
                        <AppTextInput v-model="appointmentForm.level" label="Level" type="number" min="1" max="30" :error="appointmentErrors.level?.[0]" />
                        <AppTextInput v-model="appointmentForm.step" label="Step" type="number" min="1" max="30" :error="appointmentErrors.step?.[0]" />
                        <AppDateInput v-model="appointmentForm.effective_from" label="Effective from" :error="appointmentErrors.effective_from?.[0]" />
                    </div>
                    <p class="civic-modal-note">Saving here creates a new current appointment snapshot and, when scale/level/step changed, a new current salary placement snapshot.</p>
                </AppCard>
            </div>

            <template #actions>
                <button class="civic-button" type="button" :disabled="appointmentBusy" @click="closeAppointmentModal">Cancel</button>
                <button class="civic-button civic-button-primary" type="button" :disabled="appointmentBusy" @click="saveAppointment">
                    {{ appointmentBusy ? 'Saving...' : 'Save appointment' }}
                </button>
            </template>
        </AppModal>
    </template>
</template>

<style scoped>
.civic-allowance-workspace,
.civic-slip-panel {
    display: grid;
    gap: 1.5rem;
}

.civic-allowance-editor {
    padding: 1.5rem;
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 1.25rem;
    background: rgba(255, 255, 255, 0.88);
}

.civic-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.civic-dialog-stack {
    display: grid;
    gap: 1.25rem;
}

.civic-form-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
}

.civic-native-field {
    display: block;
}

.civic-native-select {
    width: 100%;
    min-height: 2.75rem;
    padding: 0.7rem 0.85rem;
}

.civic-modal-note {
    margin: 0.85rem 0 0;
    color: #64748b;
    font-size: 0.9rem;
}
</style>
