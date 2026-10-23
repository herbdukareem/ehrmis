<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppTable from '@/Components/AppTable.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppFileInput from '@/Components/AppFileInput.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppSkeleton from '@/Components/AppSkeleton.vue';
import OfficeRecordForm from './OfficeRecordForm.vue';
import OfficeRecordActions from './OfficeRecordActions.vue';
import AppCalendar from '@/Components/AppCalendar.vue';
import OfficeFollowUpForm from './OfficeFollowUpForm.vue';
import { api, apiMessage } from '../../lib/api';
import { reportDateTime } from '../../lib/reportFormatting';
import { officeDownload, officeLabel as label } from '../../lib/executiveOffice';
const props = defineProps({ context: { type: Object, required: true }, type: { type: String, required: true }, initialRecord: { type: Number, default: null } });
const busy = ref(false), error = ref(''), rows = ref([]), meta = ref(null), selected = ref(null), editing = ref(false), creating = ref(false);
const options = ref({ users: [], departments: [] });
const filters = reactive({ search: '', status: '', priority: '', archived: false });
const note = reactive({ body: '', visibility: 'office' });
const file = ref(null), fileVisibility = ref('office'), target = ref('');
const relatedSearch = ref(''), relatedOptions = ref([]);
const calendarDate = ref(new Intl.DateTimeFormat('en-CA', { timeZone: props.context.office.timezone }).format(new Date())), calendarView = ref('week'), calendar = ref(null);
const calendarDays = computed(() => {
    if (!calendar.value) return [];
    const days = []; const cursor = new Date(`${calendar.value.from}T12:00:00Z`), end = new Date(`${calendar.value.to}T12:00:00Z`);
    while (cursor <= end) {
        const date = cursor.toISOString().slice(0, 10);
        const events = calendar.value.events.filter(event => new Intl.DateTimeFormat('en-CA', { timeZone: props.context.office.timezone }).format(new Date(event.starts_at)) === date)
            .map(event => ({ ...event, time: new Intl.DateTimeFormat('en-GB', { timeZone: props.context.office.timezone, hour: '2-digit', minute: '2-digit' }).format(new Date(event.starts_at)) }));
        days.push({ date, events }); cursor.setUTCDate(cursor.getUTCDate() + 1);
    } return days;
});
const allowed = ability => props.context.abilities.includes(ability);
const canCreate = computed(() => allowed(({ correspondence: 'register', visitor: 'register', appointment: 'schedule', meeting: 'meetings', briefing: 'brief', directive: 'create' })[props.type]));
const visibilities = computed(() => ['recipients', ...(allowed('notes') ? ['office'] : []), ...(props.context.executive_content ? ['executive_only'] : [])].map(value => ({ value, label: value === 'office' ? 'Executive and PA team' : label(value) })));
const params = extra => ({ mda_id: props.context.mda_id, ...extra });
async function run(work) { busy.value = true; error.value = ''; try { await work(); } catch (e) { error.value = apiMessage(e); } finally { busy.value = false; } }
async function load(page = 1) { const { data } = await api.get('/executive-office/records', { params: params({ ...filters, archived: filters.archived ? 1 : 0, type: props.type, page }) }); rows.value = data.data; meta.value = data.meta; }
async function open(id) { const { data } = await api.get(`/executive-office/records/${id}`, { params: params() }); selected.value = data.data; creating.value = false; editing.value = false; }
async function save(form) { await run(async () => { const { data } = await api[editing.value ? 'put' : 'post'](`/executive-office/records${editing.value ? `/${selected.value.id}` : ''}`, params(form)); await load(); await loadCalendar(); selected.value = data.data; creating.value = false; editing.value = false; }); }
async function addNote() { await run(async () => { await api.post(`/executive-office/records/${selected.value.id}/notes`, params(note)); note.body = ''; await open(selected.value.id); }); }
async function upload() { await run(async () => { const data = new FormData(); data.append('file', file.value); data.append('mda_id', props.context.mda_id); data.append('visibility', fileVisibility.value); data.append('version', selected.value.version); await api.post(`/executive-office/records/${selected.value.id}/attachments`, data); file.value = null; await open(selected.value.id); }); }
async function download(attachment) { await run(async () => { const { data } = await api.get(`/executive-office/records/${selected.value.id}/attachments/${attachment.id}`, { params: params(), responseType: 'blob' }); const url = URL.createObjectURL(data); const link = document.createElement('a'); link.href = url; link.download = attachment.name; link.click(); window.setTimeout(() => URL.revokeObjectURL(url), 1000); }); }
async function link() { await run(async () => { await api.post(`/executive-office/records/${selected.value.id}/links`, params({ target_id: target.value })); target.value = ''; await open(selected.value.id); }); }
async function findRelated() { await run(async () => { const result = await api.get('/executive-office/records', { params: params({ search: relatedSearch.value }) }); relatedOptions.value = result.data.data.filter(item => item.id !== selected.value.id).map(item => ({ id: item.id, label: `${item.reference} · ${item.subject}` })); }); }
async function loadCalendar() { if (['appointment', 'meeting'].includes(props.type) && allowed('schedule')) calendar.value = (await api.get('/executive-office/diary', { params: params({ date: calendarDate.value, view: calendarView.value }) })).data.data; }
async function act(data) { await run(async () => { await api.post(`/executive-office/records/${selected.value.id}/actions`, params(data)); await open(selected.value.id); await load(); await loadCalendar(); }); }
async function followUp(actions) { await run(async () => { await api.post(`/executive-office/records/${selected.value.id}/directives`, params({ version: selected.value.version, actions })); await open(selected.value.id); }); }
async function printPack() { await run(() => officeDownload(`/executive-office/records/${selected.value.id}/print`, params(), 'office-pack.pdf')); }
async function initialize() { selected.value = null; creating.value = false; editing.value = false; calendar.value = null; options.value = (await api.get('/executive-office/options', { params: params() })).data; await load(); await loadCalendar(); if (props.initialRecord) await open(props.initialRecord); }
watch(() => [props.type, props.context.mda_id], () => run(initialize));
watch(() => props.initialRecord, value => { if (value) run(() => open(value)); });
watch([calendarDate, calendarView], () => run(loadCalendar));
onMounted(() => { if (!allowed('notes')) { fileVisibility.value = 'recipients'; } run(initialize); });
</script>
<template>
    <div class="space-y-5">
        <AppAlert v-if="error" tone="danger" title="Action unavailable">{{ error }}</AppAlert>
        <AppCalendar v-if="calendar" v-model="calendarDate" v-model:view="calendarView" :days="calendarDays" :timezone="context.office.timezone" :busy="busy" @open="id => run(() => open(id))" />
        <AppCard :title="label(type)" subtitle="Search office records and open an item to view its permitted content and history.">
            <template #actions><AppButton v-if="canCreate" :disabled="busy" @click="creating = true; selected = null; editing = false">New {{ label(type).toLowerCase() }}</AppButton></template>
            <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="run(() => load())">
                <AppTextInput v-model="filters.search" label="Subject or reference" />
                <AppSelect v-model="filters.priority" label="Priority" :options="['normal', 'high', 'urgent'].map(value => ({ value, label: label(value) }))" placeholder="All priorities" />
                <AppSelect v-model="filters.archived" label="Register" :options="[{ value: false, label: 'Active records' }, { value: true, label: 'Archive' }]" :searchable="false" />
                <AppButton type="submit" variant="outline" :disabled="busy">Search</AppButton>
            </form>
            <AppSkeleton v-if="busy" />
            <AppEmptyState v-if="!busy && !rows.length" title="No matching records" message="New office records will appear here when registered." />
            <AppTable v-if="rows.length" :headers="[{ key: 'reference', label: 'Reference' }, { key: 'subject', label: 'Subject' }, { key: 'status', label: 'Status' }, { key: 'priority', label: 'Priority' }, { key: 'starts_at', label: 'Scheduled' }, { key: 'due_at', label: 'Deadline' }, { key: 'actions', label: 'Open' }]" :rows="rows">
                <template #cell-status="{ value }"><AppBadge>{{ label(value) }}</AppBadge></template>
                <template #cell-priority="{ value }"><AppBadge :tone="value === 'urgent' ? 'danger' : (value === 'high' ? 'warning' : 'neutral')">{{ label(value) }}</AppBadge></template>
                <template #cell-starts_at="{ value }">{{ value ? reportDateTime(value) : '—' }}</template>
                <template #cell-due_at="{ value }">{{ value ? reportDateTime(value) : '—' }}</template>
                <template #cell-actions="{ row }"><AppButton variant="outline" size="sm" :disabled="busy" @click="run(() => open(row.id))">Open</AppButton></template>
            </AppTable>
            <div v-if="meta?.last_page > 1" class="mt-4 flex items-center gap-3"><AppButton variant="outline" :disabled="busy || meta.current_page <= 1" @click="run(() => load(meta.current_page - 1))">Previous</AppButton><span>Page {{ meta.current_page }} of {{ meta.last_page }}</span><AppButton variant="outline" :disabled="busy || meta.current_page >= meta.last_page" @click="run(() => load(meta.current_page + 1))">Next</AppButton></div>
        </AppCard>
        <AppCard v-if="creating || editing" :title="`${editing ? 'Edit' : 'New'} ${label(type).toLowerCase()}`">
            <OfficeRecordForm :key="`${type}-${editing ? selected.id : 'new'}`" :record="editing ? selected : null" :type="editing ? selected.type : type" :context="context" :options="options" :busy="busy" @save="save" @cancel="creating = false; editing = false" />
        </AppCard>
        <template v-if="selected && !editing">
            <AppAlert v-for="warning in selected.warnings" :key="warning" tone="warning">{{ warning }}</AppAlert>
            <AppCard :title="selected.subject" :subtitle="`${selected.reference} · ${label(selected.status)} · Version ${selected.version} · ${label(selected.confidentiality)}`">
                <template #actions><AppButton v-if="allowed('brief')" variant="outline" :disabled="busy" @click="printPack">Download printable pack</AppButton><AppButton v-if="selected.can_edit" variant="outline" :disabled="busy" @click="editing = true">Edit record</AppButton></template>
                <p class="whitespace-pre-wrap">{{ selected.body }}</p>
                <dl class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2"><template v-for="(value, key) in selected.details" :key="key"><div v-if="!['rsvps', 'participant_user_ids', 'attended_user_ids'].includes(key)"><dt class="text-sm text-ehrmis-muted">{{ label(key) }}</dt><dd class="whitespace-pre-wrap break-words">{{ typeof value === 'boolean' ? (value ? 'Yes' : 'No') : value }}</dd></div></template></dl>
                <AppTable v-if="selected.participants?.length" class="mt-4" :rows="selected.participants" :headers="[{ key: 'name', label: 'Participant' }, { key: 'response', label: 'Invitation' }, { key: 'attendance', label: 'Attendance' }]" />
            </AppCard>
            <OfficeRecordActions :record="selected" :busy="busy" :options="options" @act="act" />
            <OfficeFollowUpForm v-if="allowed('create') && ['correspondence', 'meeting'].includes(selected.type) && (selected.type !== 'meeting' || selected.status === 'approved') && !selected.archived_at" :key="selected.version" :options="options" :busy="busy" @save="followUp" />
            <AppCard title="Notes and instructions">
                <div v-for="item in selected.notes" :key="item.id" class="mb-4"><AppBadge>{{ label(item.visibility) }}</AppBadge><p class="mt-2 whitespace-pre-wrap">{{ item.body }}</p></div>
                <form v-if="allowed('notes') && !selected.archived_at" class="space-y-3" @submit.prevent="addNote"><AppTextarea v-model="note.body" label="New note or recipient instruction" required /><AppSelect v-model="note.visibility" label="Note visibility" :options="visibilities" /><AppButton type="submit" :disabled="busy">Add note</AppButton></form>
            </AppCard>
            <AppCard title="Attachments" subtitle="Earlier attachments remain available as part of the record history.">
                <div class="mb-4 space-y-3"><div v-for="item in selected.attachments" :key="item.id" class="flex flex-wrap items-center gap-3"><span class="text-sm">{{ item.name }} · v{{ item.record_version }}</span><AppButton variant="outline" size="sm" :disabled="busy" @click="download(item)">Download document</AppButton><AppButton v-if="['application/pdf', 'image/jpeg', 'image/png', 'text/plain'].includes(item.mime_type)" variant="outline" size="sm" external :href="`/api/executive-office/records/${selected.id}/attachments/${item.id}/preview?mda_id=${context.mda_id}`" target="_blank" rel="noopener">Preview document</AppButton></div></div>
                <form v-if="!selected.archived_at && (allowed('edit') || allowed('respond') || allowed('register'))" class="space-y-3" @submit.prevent="upload"><AppFileInput v-model="file" label="Add attachment" :disabled="busy" /><AppSelect v-model="fileVisibility" label="Attachment visibility" :options="visibilities" /><AppButton type="submit" :disabled="busy || !file">Upload attachment</AppButton></form>
            </AppCard>
            <AppCard title="Linked records">
                <div class="mb-4 flex flex-wrap gap-3"><AppButton v-for="item in selected.links" :key="item.id" variant="outline" :disabled="busy" @click="run(() => open(item.id))">{{ item.reference }} · {{ item.subject }}</AppButton></div>
                <div v-if="allowed('route') && !selected.archived_at" class="space-y-3"><form class="flex flex-wrap items-end gap-3" @submit.prevent="findRelated"><AppTextInput v-model="relatedSearch" label="Related subject or reference" /><AppButton type="submit" variant="outline" :disabled="busy">Find related records</AppButton></form><form v-if="relatedOptions.length" class="flex flex-wrap items-end gap-3" @submit.prevent="link"><AppSelect v-model="target" label="Related record" :options="relatedOptions" option-value="id" option-label="label" placeholder="Choose related record" /><AppButton type="submit" :disabled="busy || !target">Link record</AppButton></form></div>
            </AppCard>
            <AppCard title="Record history">
                <div v-for="item in selected.events" :key="item.id" class="mb-4"><AppBadge>{{ label(item.action) }}</AppBadge><span class="ml-3 text-sm text-ehrmis-muted">{{ reportDateTime(item.created_at) }}</span><p v-if="item.comment" class="mt-2 whitespace-pre-wrap">{{ item.comment }}</p><details v-if="item.snapshot?.record"><summary class="cursor-pointer text-sm">View recorded version {{ item.snapshot.record.version }}</summary><p class="mt-2 font-semibold">{{ item.snapshot.record.subject }}</p><p class="whitespace-pre-wrap">{{ item.snapshot.record.body }}</p><template v-for="key in ['agenda', 'minutes', 'instruction', 'summary']" :key="key"><div v-if="item.snapshot.record.details?.[key]" class="mt-3"><p class="text-sm font-semibold">{{ label(key) }}</p><p class="whitespace-pre-wrap">{{ item.snapshot.record.details[key] }}</p></div></template><div class="mt-3 flex flex-wrap gap-2"><AppButton v-for="attachment in selected.attachments.filter(file => item.snapshot.attachments?.includes(file.id))" :key="attachment.id" variant="outline" size="sm" @click="download(attachment)">{{ attachment.name }}</AppButton></div></details></div>
            </AppCard>
        </template>
    </div>
</template>
