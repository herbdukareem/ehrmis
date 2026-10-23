<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppTable from '@/Components/AppTable.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppSkeleton from '@/Components/AppSkeleton.vue';
import AppTabs from '@/Components/AppTabs.vue';
import OfficeRecordsPanel from '../components/executive-office/OfficeRecordsPanel.vue';
import OfficeOverview from '../components/executive-office/OfficeOverview.vue';
import OfficeReports from '../components/executive-office/OfficeReports.vue';
import OfficeContinuity from '../components/executive-office/OfficeContinuity.vue';
import { api, apiMessage } from '../lib/api';
import { reportDateTime } from '../lib/reportFormatting';

const contexts = ref([]), catalog = ref({ roles: [], types: [] }), mdaId = ref(''), busy = ref(false), error = ref(''), message = ref('');
const context = computed(() => contexts.value.find(item => item.mda_id === Number(mdaId.value)));
const tab = ref('overview'), requestedRecord = ref(null), requestedType = ref('');
const tabs = computed(() => [...(context.value?.abilities.includes('read') ? ['overview', 'appointment', 'visitor', 'correspondence', 'directive', 'meeting', 'briefing'].map(key => ({ key, label: key === 'directive' && context.value.roles.includes('action_officer') ? 'Assigned to me' : label(key) })) : []), ...(context.value?.abilities.includes('report') ? [{ key: 'reports', label: 'Office reports' }] : []), ...(context.value?.abilities.includes('delegate') || context.value?.abilities.includes('brief') || context.value?.can_configure ? [{ key: 'continuity', label: 'Delegation and handover' }] : []), ...(context.value?.can_configure ? [{ key: 'settings', label: 'Office administration' }] : [])]);
function openRecord(row) { requestedType.value = row.type; requestedRecord.value = row.id; tab.value = row.type; }
watch(tab, value => { if (value !== requestedType.value) requestedRecord.value = null; });
const officeForm = reactive({ title: '', reference_prefix: '', timezone: 'Africa/Lagos', pa_confirms_routine: true, reminder_minutes: 60, workday_start: '08:00', workday_end: '17:00', workdays: [1, 2, 3, 4, 5] });
const team = ref([]), users = ref([]);
const member = reactive({ user_id: '', role: 'pa', starts_at: '', ends_at: '' });
const label = value => String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
async function run(action) { busy.value = true; error.value = ''; message.value = ''; try { await action(); } catch (e) { error.value = apiMessage(e); } finally { busy.value = false; } }
async function loadContext() {
    const { data } = await api.get('/executive-office/context'); contexts.value = data.data; catalog.value = data.catalog;
    if (!mdaId.value && contexts.value.length) mdaId.value = contexts.value[0].mda_id;
}
async function loadOffice() {
    const item = context.value;
    if (!item) return;
    if (!tabs.value.some(item => item.key === tab.value)) tab.value = tabs.value[0]?.key ?? '';
    Object.assign(officeForm, item.office ?? { title: item.suggested_title, reference_prefix: item.suggested_prefix, timezone: 'Africa/Lagos', pa_confirms_routine: true, reminder_minutes: 60, workday_start: '08:00', workday_end: '17:00', workdays: [1, 2, 3, 4, 5] });
    officeForm.workdays ??= [1, 2, 3, 4, 5];
    if (item.office && item.can_configure) { const { data } = await api.get('/executive-office/team', { params: { mda_id: mdaId.value } }); team.value = data.data; users.value = data.users; }
}
async function saveOffice() { await run(async () => { await api.put('/executive-office/office', { ...officeForm, mda_id: mdaId.value }); await loadContext(); await loadOffice(); message.value = 'Office settings saved.'; }); }
async function assign() { await run(async () => { await api.post('/executive-office/team', { ...member, ends_at: member.ends_at || null, mda_id: mdaId.value }); await loadOffice(); message.value = 'Office assignment saved.'; }); }
async function revoke(id) { await run(async () => { await api.delete(`/executive-office/team/${id}`, { params: { mda_id: mdaId.value } }); await loadOffice(); }); }
watch(mdaId, () => { requestedRecord.value = null; requestedType.value = ''; run(loadOffice); });
onMounted(() => run(loadContext));
</script>

<template>
    <div class="space-y-6">
        <AppPageHeader eyebrow="Executive Office" :title="context?.office ? `Office of the ${context.office.title}` : 'Executive Office'" subtitle="Office coordination and executive decisions." :show-breadcrumbs="false" />
        <AppAlert v-if="error" tone="danger" title="Action unavailable">{{ error }}</AppAlert>
        <AppAlert v-if="message" tone="success">{{ message }}</AppAlert>
        <AppSelect v-if="contexts.length" v-model="mdaId" label="MDA" :options="contexts" option-value="mda_id" option-label="mda_name" :disabled="busy" />
        <AppSkeleton v-if="busy" />
        <AppEmptyState v-if="!busy && !contexts.length" title="No executive office access" message="Your MDA must enable this module and assign you to its office team." />
        <template v-if="context">
            <AppTabs v-model="tab" :tabs="tabs" />
            <OfficeOverview v-if="tab === 'overview' && context.office && context.abilities.includes('read')" :key="context.mda_id" :context="context" @open="openRecord" @navigate="value => { requestedRecord = null; tab = value; }" />
            <OfficeReports v-else-if="tab === 'reports' && context.abilities.includes('report')" :key="context.mda_id" :context="context" :catalog="catalog" @open="openRecord" />
            <OfficeContinuity v-else-if="tab === 'continuity' && context.office" :key="context.mda_id" :context="context" @open="openRecord" @changed="run(loadContext)" />
            <OfficeRecordsPanel v-else-if="catalog.types.includes(tab) && context.office && context.abilities.includes('read')" :key="`${context.mda_id}:${tab}`" :context="context" :type="tab" :initial-record="requestedRecord" />
            <AppCard v-if="tab === 'settings' && context.can_configure" title="Office settings" subtitle="One executive office per MDA. Administrative access does not include executive records.">
                <form class="space-y-4" @submit.prevent="saveOffice">
                    <fieldset :disabled="busy" class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-2">
                        <AppTextInput v-model="officeForm.title" label="Executive title" required />
                        <AppTextInput v-model="officeForm.reference_prefix" label="Reference prefix" required />
                        <AppTextInput v-model="officeForm.timezone" label="Office timezone" required />
                        <AppTextInput v-model="officeForm.reminder_minutes" label="Reminder lead time (minutes)" type="number" min="5" max="10080" />
                        <AppTextInput v-model="officeForm.workday_start" label="Normal availability starts" type="time" required />
                        <AppTextInput v-model="officeForm.workday_end" label="Normal availability ends" type="time" required />
                        <AppSelect v-model="officeForm.workdays" multiple label="Normal working days" :options="['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((label, index) => ({ value: index + 1, label }))" />
                        <AppSelect v-model="officeForm.pa_confirms_routine" label="Routine appointments" :options="[{ value: true, label: 'PA may confirm routine appointments' }, { value: false, label: 'Executive confirms every appointment' }]" :searchable="false" />
                    </fieldset>
                    <AppButton type="submit" :disabled="busy">Save office settings</AppButton>
                </form>
            </AppCard>
            <AppCard v-if="tab === 'settings' && context.office && context.can_configure" title="Office team" subtitle="Assignment dates control access. Only one executive and one lead PA may hold overlapping assignments.">
                <form class="space-y-4" @submit.prevent="assign">
                    <fieldset :disabled="busy" class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-2">
                        <AppSelect v-model="member.user_id" label="User" :options="users" option-value="id" option-label="name" placeholder="Choose user" />
                        <AppSelect v-model="member.role" label="Office role" :options="catalog.roles.map(value => ({ value, label: label(value) }))" />
                        <AppTextInput v-model="member.starts_at" type="datetime-local" label="Assignment starts" required />
                        <AppTextInput v-model="member.ends_at" type="datetime-local" label="Assignment ends (optional)" />
                    </fieldset>
                    <AppButton type="submit" :disabled="busy || !member.user_id">Assign user</AppButton>
                </form>
                <AppTable class="mt-5" :headers="[{ key: 'user', label: 'User' }, { key: 'role', label: 'Role' }, { key: 'starts_at', label: 'Starts' }, { key: 'ends_at', label: 'Ends' }, { key: 'actions', label: 'Access' }]" :rows="team">
                    <template #cell-user="{ value }">{{ value?.name }}</template>
                    <template #cell-role="{ value }">{{ label(value) }}</template>
                    <template #cell-starts_at="{ value }">{{ reportDateTime(value) }}</template>
                    <template #cell-ends_at="{ value }">{{ value ? reportDateTime(value) : 'Open ended' }}</template>
                    <template #cell-actions="{ row }"><span v-if="row.revoked_at">Revoked</span><AppButton v-else variant="outline" size="sm" :disabled="busy" @click="revoke(row.id)">Revoke</AppButton></template>
                </AppTable>
            </AppCard>
        </template>
    </div>
</template>
