<script setup>
import { onMounted, reactive, ref } from 'vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppTable from '@/Components/AppTable.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import { api, apiMessage } from '../../lib/api';
import { officeLabel as label } from '../../lib/executiveOffice';
import { reportDateTime } from '../../lib/reportFormatting';
const props = defineProps({ context: { type: Object, required: true } });
const emit = defineEmits(['open', 'changed']);
const users = ref([]), members = ref([]), grants = ref([]), abilities = ref([]), busy = ref(false), error = ref(''), message = ref('');
const grant = reactive({ user_id: '', starts_at: '', ends_at: '', abilities: ['read'], executive_only_access: false, reason: '' });
const transfer = reactive({ outgoing_user_id: '', incoming_user_id: '', reason: '' });
const params = input => ({ mda_id: props.context.mda_id, ...input });
async function run(work) { busy.value = true; error.value = ''; message.value = ''; try { await work(); } catch (e) { error.value = apiMessage(e); } finally { busy.value = false; } }
async function load() {
    if (props.context.can_configure) { const result = await api.get('/executive-office/team', { params: params() }); users.value = result.data.users; members.value = [...new Map(result.data.data.filter(item => !item.revoked_at && new Date(item.starts_at) <= new Date() && (!item.ends_at || new Date(item.ends_at) > new Date())).map(item => [item.user_id, { id: item.user_id, name: item.user.name }])).values()]; }
    else users.value = (await api.get('/executive-office/options', { params: params() })).data.users;
    if (props.context.abilities.includes('delegate')) { const result = await api.get('/executive-office/delegations', { params: params() }); grants.value = result.data.data; abilities.value = result.data.abilities; }
}
async function delegate() { await run(async () => { await api.post('/executive-office/delegations', params(grant)); await load(); message.value = 'Delegation recorded. Access follows the specified start and end times.'; }); }
async function revoke(id) { await run(async () => { await api.delete(`/executive-office/delegations/${id}`, { params: params() }); await load(); }); }
async function handover() { await run(async () => { const { data } = await api.post('/executive-office/handover', params(transfer)); await load(); message.value = `Handover complete. ${data.data.transferred_records} outstanding records reassigned.`; emit('changed'); }); }
async function pack() { await run(async () => { const { data } = await api.post('/executive-office/handover/pack', params()); emit('open', data.data); }); }
onMounted(() => run(load));
</script>
<template>
    <div class="space-y-5">
        <AppAlert v-if="error" tone="danger">{{ error }}</AppAlert><AppAlert v-if="message" tone="success">{{ message }}</AppAlert>
        <AppCard v-if="context.abilities.includes('brief')" title="Handover briefing pack" subtitle="Prepare a dated record of outstanding matters and upcoming commitments you are authorised to view."><AppButton :disabled="busy" @click="pack">Prepare handover pack</AppButton></AppCard>
        <AppCard v-if="context.abilities.includes('delegate')" title="Acting cover and limited delegation" subtitle="Grant only the abilities needed. Delegates cannot pass their authority to another person. All dates use the office timezone.">
            <form class="space-y-4" @submit.prevent="delegate"><fieldset :disabled="busy" class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-2">
                <AppSelect v-model="grant.user_id" label="Delegate" :options="users" option-value="id" option-label="name" placeholder="Choose user" />
                <AppSelect v-model="grant.abilities" multiple label="Delegated abilities" :options="abilities.map(value => ({ value, label: label(value) }))" />
                <AppTextInput v-model="grant.starts_at" label="Authority starts (office time)" type="datetime-local" required /><AppTextInput v-model="grant.ends_at" label="Authority ends (office time)" type="datetime-local" required />
                <AppSelect v-model="grant.executive_only_access" label="Executive-only content" :options="[{ value: false, label: 'Keep executive-only content restricted' }, { value: true, label: 'Explicitly permit executive-only content' }]" :searchable="false" />
                <AppTextarea v-model="grant.reason" label="Reason and authority scope" required />
            </fieldset><AppButton type="submit" :disabled="busy || !grant.user_id || !grant.abilities.length">Grant dated authority</AppButton></form>
            <AppTable class="mt-5" :rows="grants" :headers="[{ key: 'user_id', label: 'Delegate' }, { key: 'abilities', label: 'Abilities' }, { key: 'starts_at', label: 'Starts' }, { key: 'ends_at', label: 'Ends' }, { key: 'action', label: 'Access' }]"><template #cell-user_id="{ value }">{{ users.find(user => user.id === value)?.name ?? 'Office user' }}</template><template #cell-abilities="{ value }">{{ value.map(label).join(', ') }}</template><template #cell-starts_at="{ value }">{{ reportDateTime(value) }}</template><template #cell-ends_at="{ value }">{{ reportDateTime(value) }}</template><template #cell-action="{ row }"><span v-if="row.revoked_at">Revoked</span><AppButton v-else variant="outline" size="sm" :disabled="busy" @click="revoke(row.id)">Revoke authority</AppButton></template></AppTable>
        </AppCard>
        <AppCard v-if="context.can_configure" title="Transfer office responsibilities" subtitle="This ends the outgoing user’s office assignments and delegations immediately. Their current roles and outstanding responsibilities transfer to the incoming user; historical authorship remains intact.">
            <form class="space-y-4" @submit.prevent="handover"><fieldset :disabled="busy" class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-2"><AppSelect v-model="transfer.outgoing_user_id" label="Outgoing office user" :options="members" option-value="id" option-label="name" placeholder="Choose current office user" /><AppSelect v-model="transfer.incoming_user_id" label="Incoming office user" :options="users" option-value="id" option-label="name" placeholder="Choose replacement" /><AppTextarea v-model="transfer.reason" label="Handover reason" required class="md:col-span-2" /></fieldset><AppButton type="submit" :disabled="busy || !transfer.outgoing_user_id || !transfer.incoming_user_id || transfer.outgoing_user_id === transfer.incoming_user_id">Complete handover and transfer access</AppButton></form>
        </AppCard>
    </div>
</template>
