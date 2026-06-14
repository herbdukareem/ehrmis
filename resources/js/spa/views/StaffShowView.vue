<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StaffMediaPanel from '../components/StaffMediaPanel.vue';
import StatusPill from '../components/StatusPill.vue';
import { api } from '../lib/api';
import { can } from '../stores/auth';

const route = useRoute();
const staff = ref(null);
const load = async () => { staff.value = (await api.get(`/staff/${route.params.id}`)).data.data; };
onMounted(load);
</script>

<template>
    <LoadingBlock v-if="!staff" />
    <template v-else>
        <PageHeading :eyebrow="staff.staff_number" :title="staff.full_name" :description="`${staff.mda?.name ?? 'Unassigned MDA'} · ${staff.current_employment?.department_name ?? 'No department'}`">
            <RouterLink class="civic-button" :to="`/staff/${staff.id}/edit`">Edit record</RouterLink>
            <StatusPill :status="staff.status" />
        </PageHeading>
        <section class="civic-record-sheet">
            <aside class="civic-record-index">
                <div class="civic-eyebrow">Official record</div>
                <dl>
                    <div><dt>Staff number</dt><dd>{{ staff.staff_number }}</dd></div>
                    <div><dt>Legacy CNO / PSN</dt><dd>{{ staff.legacy_cno ?? '—' }} / {{ staff.legacy_psn ?? '—' }}</dd></div>
                    <div><dt>Sex</dt><dd>{{ staff.sex ?? '—' }}</dd></div>
                    <div><dt>Date of birth</dt><dd>{{ staff.date_of_birth ?? '—' }}</dd></div>
                </dl>
            </aside>
            <div class="civic-record-body">
                <article>
                    <h2>Current appointment</h2>
                    <dl class="civic-detail-grid">
                        <div><dt>Department</dt><dd>{{ staff.current_employment?.department_name ?? '—' }}</dd></div>
                        <div><dt>Station</dt><dd>{{ staff.current_employment?.station_name ?? '—' }}</dd></div>
                        <div><dt>Cadre</dt><dd>{{ staff.current_employment?.cadre_name ?? '—' }}</dd></div>
                        <div><dt>Rank</dt><dd>{{ staff.current_employment?.rank_name ?? '—' }}</dd></div>
                    </dl>
                </article>
                <article>
                    <h2>Salary position</h2>
                    <dl class="civic-detail-grid">
                        <div><dt>Scale / Level / Step</dt><dd>{{ staff.current_salary_placement?.salary_scale_code ?? '—' }} {{ staff.current_salary_placement?.level ?? '—' }}/{{ staff.current_salary_placement?.step ?? '—' }}</dd></div>
                        <div><dt>Basic salary</dt><dd>{{ Number(staff.salary_summary?.basic_salary ?? 0).toLocaleString() }}</dd></div>
                        <div><dt>Calculated gross</dt><dd>{{ Number(staff.salary_summary?.calculated_gross_salary ?? 0).toLocaleString() }}</dd></div>
                        <div><dt>Legacy difference</dt><dd>{{ Number(staff.salary_summary?.gross_difference ?? 0).toLocaleString() }}</dd></div>
                    </dl>
                </article>
                <article>
                    <h2>Qualifications and allowances</h2>
                    <div class="civic-tag-line">
                        <span v-for="item in staff.qualifications" :key="item.id">{{ item.qualification_type?.name ?? item.qualification_name }}</span>
                        <span v-for="item in staff.allowance_assignments" :key="`a-${item.id}`">{{ item.allowance_type?.name }}</span>
                        <span v-if="!staff.qualifications?.length && !staff.allowance_assignments?.length">No entries recorded</span>
                    </div>
                </article>
            </div>
        </section>
        <StaffMediaPanel :staff="staff" :can-update="can('update-staff')" @changed="load" />
    </template>
</template>
