<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppModal from '../components/AppModal.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();
const rows = ref([]); const report = ref(null); const error = ref(''); const loading = ref(true); const saving = ref(false);
const returnOpen = ref(false); const returnReason = ref('');
const evidenceType = ref('file'); const evidenceTitle = ref(''); const evidenceNotes = ref(''); const evidenceUrl = ref(''); const evidenceFile = ref(null);

const load = async () => {
  loading.value = true;
  try {
    rows.value = (await api.get('/workplan-progress-reports')).data.data ?? [];
    if (route.params.id) report.value = (await api.get(`/workplan-progress-reports/${route.params.id}`)).data.data;
  } catch (e) { error.value = apiMessage(e); } finally { loading.value = false; }
};
const action = async (path, payload = {}) => {
  saving.value = true;
  try { report.value = (await api.post(`/workplan-progress-reports/${report.value.id}/${path}`, payload)).data.data; await load(); }
  catch (e) { error.value = apiMessage(e); }
  finally { saving.value = false; returnOpen.value = false; }
};
const save = async () => {
  saving.value = true;
  try { report.value = (await api.put(`/workplan-progress-reports/${report.value.id}`, report.value)).data.data; }
  catch (e) { error.value = apiMessage(e); } finally { saving.value = false; }
};
const sync = async () => {
  saving.value = true;
  try {
    report.value = (await api.put(`/workplan-progress-reports/${report.value.id}/indicators`, {
      indicators: report.value.indicators.map((item) => ({ workplan_indicator_id: item.indicator_id, actual_value: item.actual_value, verification_note: item.verification_note })),
    })).data.data;
  } catch (e) { error.value = apiMessage(e); } finally { saving.value = false; }
};
const addEvidence = async () => {
  if (!evidenceTitle.value.trim()) { error.value = 'An evidence title is required.'; return; }
  if (evidenceType.value === 'file' && !evidenceFile.value) { error.value = 'Select a file to upload.'; return; }
  saving.value = true;
  try {
    const form = new FormData(); form.append('title', evidenceTitle.value); form.append('evidence_type', evidenceType.value);
    if (evidenceNotes.value) form.append('notes', evidenceNotes.value);
    if (evidenceType.value === 'file') form.append('file', evidenceFile.value); else form.append('external_url', evidenceUrl.value);
    await api.post(`/workplan-progress-reports/${report.value.id}/evidence`, form);
    evidenceTitle.value = ''; evidenceNotes.value = ''; evidenceUrl.value = ''; evidenceFile.value = null; await load();
  } catch (e) { error.value = apiMessage(e); } finally { saving.value = false; }
};
const removeEvidence = async (id) => {
  if (!confirm('Delete this evidence?')) return;
  saving.value = true;
  try { await api.delete(`/workplan-evidence/${id}`); await load(); }
  catch (e) { error.value = apiMessage(e); } finally { saving.value = false; }
};
onMounted(load);
</script>

<template>
  <PageHeading eyebrow="Performance management" title="Progress Reporting" />
  <div v-if="error" class="civic-error">{{ error }}</div>
  <LoadingBlock v-else-if="loading" />
  <section v-else-if="!report" class="civic-workspace">
    <p>Reports are revision-specific. Quarterly actuals are cumulative totals achieved as at the end of the selected period.</p>
    <p v-for="row in rows" :key="row.id"><RouterLink :to="`/workplan-progress/${row.id}`">{{ row.activity.code }} · {{ row.activity.title }} · {{ row.period.toUpperCase() }}</RouterLink> <StatusPill :status="row.status" /></p>
    <p v-if="!rows.length">No reports yet. Create one from an active Workplan reporting matrix.</p>
  </section>
  <template v-else>
    <section class="civic-workspace">
      <div class="civic-section-heading"><div><span class="civic-kicker">{{ report.activity.department?.name }} · {{ report.period.toUpperCase() }}</span><h2>{{ report.activity.code }} · {{ report.activity.title }}</h2></div><StatusPill :status="report.status" /></div>
      <p>Responsible officer: {{ report.activity.responsible_staff?.full_name || 'Unassigned' }}. Actuals are cumulative as at the end of this period.</p>
      <div v-if="report.status === 'returned'" class="civic-error">Returned for correction: {{ report.return_reason }}</div>
      <div v-if="report.can.edit" class="civic-form-grid">
        <label class="civic-field"><span>Reported expenditure</span><input v-model="report.reported_expenditure" type="number" min="0" step="0.01"></label>
        <label class="civic-field civic-field-wide"><span>Achievement summary</span><textarea v-model="report.achievement_summary" rows="3" /></label>
        <label class="civic-field civic-field-wide"><span>Challenges</span><textarea v-model="report.challenges" rows="2" /></label>
        <label class="civic-field civic-field-wide"><span>Corrective action</span><textarea v-model="report.corrective_action" rows="2" /></label>
        <label class="civic-field civic-field-wide"><span>Next-period action</span><textarea v-model="report.next_period_action" rows="2" /></label>
        <label class="civic-field civic-field-wide"><span>Remarks</span><textarea v-model="report.remarks" rows="2" /></label>
        <button class="civic-button civic-button-primary" :disabled="saving" @click="save">Save narrative</button>
      </div>
    </section>
    <section class="civic-workspace"><h3>Indicator actuals</h3>
      <div v-for="item in report.indicators" :key="item.id" class="civic-form-grid"><p class="civic-field-wide"><strong>{{ item.code }} · {{ item.indicator }}</strong><br>Period target: {{ item.target_value_snapshot }} {{ item.unit }} · Achievement: {{ item.achievement_ratio == null ? 'Not calculated' : `${(item.achievement_ratio * 100).toFixed(1)}%` }}</p><label v-if="report.can.edit" class="civic-field"><span>Cumulative actual</span><input v-model="item.actual_value" type="number" step="0.0001"></label><label v-if="report.can.edit" class="civic-field"><span>Note</span><input v-model="item.verification_note"></label></div>
      <button v-if="report.can.edit" class="civic-button civic-button-primary" :disabled="saving" @click="sync">Save indicator actuals</button>
    </section>
    <section class="civic-workspace"><h3>Evidence</h3>
      <p v-for="item in report.evidence" :key="item.id"><strong>{{ item.title }}</strong> · {{ item.evidence_type }} <a v-if="item.download_url" :href="item.download_url">Download</a><a v-if="item.external_url" :href="item.external_url" target="_blank" rel="noopener">Open link</a><button v-if="report.can.delete_evidence" class="civic-button" :disabled="saving" @click="removeEvidence(item.id)">Delete</button></p>
      <p v-if="!report.evidence.length">No evidence attached.</p>
      <div v-if="report.can.add_evidence" class="civic-form-grid"><label class="civic-field"><span>Evidence type</span><select v-model="evidenceType"><option value="file">Upload file</option><option value="link">Add link</option></select></label><label class="civic-field"><span>Title</span><input v-model="evidenceTitle"></label><label v-if="evidenceType === 'file'" class="civic-field civic-field-wide"><span>File (PDF, Office document or image; max 10 MB)</span><input type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" @change="evidenceFile = $event.target.files[0]"></label><label v-else class="civic-field civic-field-wide"><span>External URL</span><input v-model="evidenceUrl" type="url" placeholder="https://"></label><label class="civic-field civic-field-wide"><span>Notes</span><textarea v-model="evidenceNotes" rows="2" /></label><button class="civic-button civic-button-primary" :disabled="saving" @click="addEvidence">Add evidence</button></div>
      <p v-if="report.status === 'verified'">Evidence is locked after verification.</p>
    </section>
    <section v-if="report.history?.length" class="civic-workspace"><h3>Report history</h3><p v-for="entry in report.history" :key="`${entry.event}-${entry.occurred_at}`"><strong>{{ entry.event.replace('workplan.progress.', '') }}</strong> · {{ entry.actor?.name || 'System' }} · {{ entry.occurred_at }}<br><small v-if="entry.reason">{{ entry.reason }}</small></p></section>
    <section class="civic-workspace"><button v-if="report.can.submit" class="civic-button civic-button-primary" :disabled="saving" @click="action('submit')">{{ report.status === 'returned' ? 'Resubmit' : 'Submit' }}</button><button v-if="report.can.verify" class="civic-button civic-button-primary" :disabled="saving" @click="action('verify')">Verify</button><button v-if="report.can.return" class="civic-button" @click="returnOpen = true">Return for correction</button></section>
  </template>
  <AppModal :open="returnOpen" title="Return progress report" @close="returnOpen = false"><label class="civic-field"><span>Correction reason</span><textarea v-model="returnReason" rows="4" /></label><template #actions><button class="civic-button civic-button-primary" @click="action('return', { return_reason: returnReason })">Return report</button></template></AppModal>
</template>
