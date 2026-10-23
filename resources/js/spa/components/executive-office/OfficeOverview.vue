<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppStatCard from '@/Components/AppStatCard.vue';
import AppTable from '@/Components/AppTable.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import { api, apiMessage } from '../../lib/api';
import { officeLabel as label } from '../../lib/executiveOffice';
import { reportDateTime } from '../../lib/reportFormatting';
const props = defineProps({ context: { type: Object, required: true } });
const emit = defineEmits(['open', 'navigate']);
const data = ref(null), notifications = ref([]), busy = ref(false), error = ref('');
const briefingDate = ref(new Intl.DateTimeFormat('en-CA', { timeZone: props.context.office.timezone }).format(new Date()));
const populatedQueues = computed(() => Object.fromEntries(Object.entries(data.value?.queues ?? {}).filter(([, rows]) => rows.length)));
let timer;
async function load(background = false) {
    if (background && document.hidden) return;
    if (!background) busy.value = true;
    try {
        const params = { mda_id: props.context.mda_id };
        const results = await Promise.all([api.get('/executive-office/dashboard', { params, background }), api.get('/executive-office/notifications', { params, background })]);
        data.value = results[0].data.data; notifications.value = results[1].data.data; error.value = '';
    } catch (e) { data.value = null; notifications.value = []; error.value = apiMessage(e); } finally { if (!background) busy.value = false; }
}
async function read(item) { try { await api.post(`/executive-office/notifications/${item.id}/read`, { mda_id: props.context.mda_id }); item.read_at = new Date().toISOString(); emit('open', { id: item.record_id, type: item.type }); } catch (e) { error.value = apiMessage(e); } }
async function prepare() { busy.value = true; try { const result = await api.post('/executive-office/briefings/prepare', { mda_id: props.context.mda_id, date: briefingDate.value }); emit('open', result.data.data); } catch (e) { error.value = apiMessage(e); } finally { busy.value = false; } }
onMounted(() => { load(); timer = window.setInterval(() => load(true), 60000); });
onBeforeUnmount(() => window.clearInterval(timer));
</script>
<template>
    <div class="space-y-5">
        <AppAlert v-if="error" tone="danger" title="Office overview unavailable">{{ error }}</AppAlert>
        <AppCard :title="data?.role === 'Executive' ? 'Your executive briefing' : 'Your office coordination desk'" :subtitle="data?.role === 'Executive' ? 'Review your schedule, pending decisions, and outstanding instructions.' : 'Organise engagements, prepare documents, and follow up outstanding matters.'">
            <div class="flex flex-wrap gap-3"><AppButton variant="outline" :disabled="busy" @click="load()">Refresh overview</AppButton><AppButton v-if="context.abilities.includes('schedule')" variant="outline" @click="emit('navigate', 'appointment')">Diary and appointments</AppButton><AppButton v-if="context.abilities.includes('register')" variant="outline" @click="emit('navigate', 'correspondence')">Register correspondence</AppButton><AppButton v-if="context.abilities.includes('create')" variant="outline" @click="emit('navigate', 'directive')">Prepare instruction</AppButton></div>
        </AppCard>
        <div v-if="data" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"><AppStatCard v-for="(value, key) in data.counts" :key="key" :label="label(key)" :value="value" /></div>
        <AppCard v-if="context.abilities.includes('brief')" title="Prepare a daily briefing" subtitle="Build a dated draft from the records you may access, then review and publish it."><form class="flex flex-wrap items-end gap-3" @submit.prevent="prepare"><AppTextInput v-model="briefingDate" label="Briefing date" type="date" required /><AppButton type="submit" :disabled="busy">Prepare briefing draft</AppButton></form></AppCard>
        <AppCard title="Notifications" subtitle="Reminders and updates are restricted to records you can currently access.">
            <AppEmptyState v-if="!notifications.length" title="No notifications" message="Reminders and office updates will appear here." />
            <AppTable v-else :rows="notifications" :headers="[{ key: 'subject', label: 'Matter' }, { key: 'kind', label: 'Update' }, { key: 'read_at', label: 'Read' }, { key: 'open', label: 'Action' }]">
                <template #cell-kind="{ value }">{{ label(value) }}</template><template #cell-read_at="{ value }">{{ value ? 'Read' : 'Unread' }}</template><template #cell-open="{ row }"><AppButton variant="outline" size="sm" :disabled="busy" @click="read(row)">Open update</AppButton></template>
            </AppTable>
        </AppCard>
        <AppCard v-if="data && !Object.keys(populatedQueues).length" title="Office matters"><AppEmptyState title="No outstanding matters" message="Your schedule and actionable office matters will appear here." /></AppCard>
        <template v-if="data"><AppCard v-for="(rows, queue) in populatedQueues" :key="queue" :title="label(queue)" :subtitle="`Showing up to 20 of ${data.counts[queue]} matching items.`">
            <AppEmptyState v-if="!rows.length" title="Nothing pending here" message="Relevant office matters will appear in this queue." />
            <AppTable v-else :rows="rows" :headers="[{ key: 'subject', label: 'Matter' }, { key: 'status', label: 'Status' }, { key: 'starts_at', label: 'Scheduled' }, { key: 'due_at', label: 'Deadline' }, { key: 'open', label: 'Action' }]">
                <template #cell-status="{ value }">{{ label(value) }}</template><template #cell-starts_at="{ value }">{{ value ? reportDateTime(value) : '—' }}</template><template #cell-due_at="{ value }">{{ value ? reportDateTime(value) : '—' }}</template><template #cell-open="{ row }"><AppButton variant="outline" size="sm" @click="emit('open', row)">Open matter</AppButton></template>
            </AppTable>
        </AppCard></template>
    </div>
</template>
