<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppTabs from '../components/AppTabs.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StaffMediaPanel from '../components/StaffMediaPanel.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();
const staff = ref(null);
const activeTab = ref('details');
const selectedAllowanceIds = ref([]);
const allowanceBusy = ref(false);
const allowanceError = ref('');
const allowanceFeedback = ref('');
const eligibleAllowances = computed(() => staff.value?.allowance_assignments?.filter((item) => item.is_eligible) ?? []);
const tabs = computed(() => [
    { id: 'details', label: 'Current details' },
    { id: 'documents', label: 'Documents', count: staff.value?.documents?.length ?? 0 },
]);
const money = (value) => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const setStaff = (data) => {
    staff.value = data;
    selectedAllowanceIds.value = eligibleAllowances.value.map((item) => item.allowance_type_id);
};
const load = async () => setStaff((await api.get(`/staff/${route.params.id}`)).data.data);
const saveAllowances = async () => {
    allowanceBusy.value = true;
    allowanceError.value = '';
    allowanceFeedback.value = '';

    try {
        const response = await api.put(`/staff/${staff.value.id}/allowances`, {
            assignments: staff.value.allowance_types.map((type) => ({
                allowance_type_id: type.id,
                is_eligible: selectedAllowanceIds.value.includes(type.id),
            })),
        });
        setStaff(response.data.data);
        allowanceFeedback.value = response.data.message;
    } catch (exception) {
        allowanceError.value = apiMessage(exception);
    } finally {
        allowanceBusy.value = false;
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
                    <div><dt>Date of birth</dt><dd>{{ staff.date_of_birth ?? '-' }}</dd></div>
                </dl>
            </aside>
            <div class="civic-record-body">
                <article>
                    <h2>Current appointment</h2>
                    <dl class="civic-detail-grid">
                        <div><dt>Department</dt><dd>{{ staff.current_employment?.department_name ?? '-' }}</dd></div>
                        <div><dt>Station</dt><dd>{{ staff.current_employment?.station_name ?? '-' }}</dd></div>
                        <div><dt>Cadre</dt><dd>{{ staff.current_employment?.cadre_name ?? '-' }}</dd></div>
                        <div><dt>Rank</dt><dd>{{ staff.current_employment?.rank_name ?? '-' }}</dd></div>
                    </dl>

                    <div class="civic-allowance-editor">
                        <div class="civic-analysis-heading">
                            <div><div class="civic-eyebrow">Current eligibility</div><h3>Allowances</h3></div>
                            <span>{{ eligibleAllowances.length }} eligible</span>
                        </div>
                        <div v-if="staff.can_update" class="civic-check-grid civic-allowance-check-grid">
                            <label v-for="type in staff.allowance_types" :key="type.id" class="civic-check">
                                <input v-model="selectedAllowanceIds" type="checkbox" :value="type.id">
                                <span>{{ type.name }}</span>
                            </label>
                        </div>
                        <div v-else class="civic-tag-line">
                            <span v-for="item in eligibleAllowances" :key="item.id">{{ item.allowance_name }}</span>
                            <span v-if="!eligibleAllowances.length">No eligible allowances recorded</span>
                        </div>
                        <div v-if="allowanceFeedback" class="civic-feedback">{{ allowanceFeedback }}</div>
                        <div v-if="allowanceError" class="civic-error">{{ allowanceError }}</div>
                        <button v-if="staff.can_update" class="civic-button civic-button-primary" type="button" :disabled="allowanceBusy" @click="saveAllowances">
                            {{ allowanceBusy ? 'Recomputing...' : 'Save allowances and recompute gross' }}
                        </button>
                    </div>
                </article>
                <article>
                    <h2>Salary position</h2>
                    <dl class="civic-detail-grid">
                        <div><dt>Scale / Level / Step</dt><dd>{{ staff.current_salary_placement?.salary_scale_code ?? '-' }} {{ staff.current_salary_placement?.level ?? '-' }}/{{ staff.current_salary_placement?.step ?? '-' }}</dd></div>
                        <div><dt>Basic salary</dt><dd>{{ money(staff.salary_summary?.basic_salary) }}</dd></div>
                        <div><dt>Eligible allowances</dt><dd>{{ money(staff.salary_summary?.total_allowances) }}</dd></div>
                        <div><dt>Calculated gross</dt><dd>{{ money(staff.salary_summary?.calculated_gross_salary) }}</dd></div>
                        <div><dt>Legacy difference</dt><dd>{{ money(staff.salary_summary?.gross_difference) }}</dd></div>
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

        <StaffMediaPanel v-else :staff="staff" :can-update="staff.can_update" @changed="load" />
    </template>
</template>
