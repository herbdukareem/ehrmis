<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();
const data = ref(null);
const busy = ref(false);
const feedback = ref('');
const note = ref('');
const mapping = ref({ field: '', target_id: '' });
const mappingChoices = computed(() => data.value?.mapping_options?.[`${mapping.value.field}s`] ?? []);
const load = async () => { data.value = (await api.get(`/legacy-staff-imports/${route.params.batchId}/rows/${route.params.rowId}`)).data.data; };
const post = async (action, payload = {}) => {
    busy.value = true;
    feedback.value = '';
    try {
        const response = await api.post(`/legacy-staff-imports/${route.params.batchId}/rows/${route.params.rowId}/${action}`, payload);
        feedback.value = response.data.message;
        await load();
    } catch (error) {
        feedback.value = apiMessage(error);
    } finally {
        busy.value = false;
    }
};
const resolveMapping = () => post('resolve-mapping', { ...mapping.value, notes: note.value });
onMounted(load);
</script>

<template>
    <LoadingBlock v-if="!data" />
    <template v-else>
        <PageHeading :eyebrow="`Batch #${data.batch.id} / ${data.row.staff_number ?? `Row ${data.row.id}`}`" :title="data.row.full_name ?? 'Unnamed legacy record'" description="Compare the preserved source payload with the normalized record, resolve exceptions, then publish when eligible.">
            <RouterLink class="civic-button" :to="`/legacy-staff-imports/${data.batch.id}`">Back to batch</RouterLink>
            <StatusPill :status="data.row.status" />
        </PageHeading>
        <section class="civic-decision-bar">
            <div><span>Warnings</span><strong>{{ data.row.issue_summary.warnings_count }}</strong></div>
            <div><span>Errors</span><strong>{{ data.row.issue_summary.errors_count }}</strong></div>
            <div><span>MDA</span><strong>{{ data.row.mda?.code ?? 'Unresolved' }}</strong></div>
            <label class="civic-field civic-decision-note"><span>Review note</span><input v-model="note" placeholder="Record the reason for this action"></label>
            <button v-if="data.can.publish" class="civic-button civic-button-primary" :disabled="busy" @click="post('publish')">Publish row</button>
        </section>
        <div v-if="feedback" class="civic-feedback">{{ feedback }}</div>
        <section class="civic-record-sheet">
            <aside class="civic-record-index">
                <div class="civic-eyebrow">Normalized placement</div>
                <dl>
                    <div><dt>Department</dt><dd>{{ data.row.department?.name ?? '-' }}</dd></div>
                    <div><dt>Station</dt><dd>{{ data.row.station?.name ?? '-' }}</dd></div>
                    <div><dt>Cadre</dt><dd>{{ data.row.cadre?.name ?? '-' }}</dd></div>
                    <div><dt>Rank</dt><dd>{{ data.row.rank?.name ?? '-' }}</dd></div>
                    <div><dt>Scale / Level / Step</dt><dd>{{ data.row.salary_scale?.code ?? '-' }} {{ data.row.level ?? '-' }}/{{ data.row.step ?? '-' }}</dd></div>
                </dl>
            </aside>
            <div class="civic-record-body">
                <article v-if="data.can.resolve">
                    <h2>Resolve reference</h2>
                    <div class="civic-inline-form">
                        <label class="civic-field"><span>Reference type</span><select v-model="mapping.field"><option value="">Select field</option><option value="mda">MDA</option><option value="department">Department</option><option value="station">Station</option><option value="cadre">Cadre</option><option value="rank">Rank</option><option value="qualification_type">Qualification</option></select></label>
                        <label class="civic-field"><span>Canonical value</span><select v-model="mapping.target_id" :disabled="!mapping.field"><option value="">Select value</option><option v-for="choice in mappingChoices" :key="choice.id" :value="choice.id">{{ choice.code ? `${choice.code} - ` : '' }}{{ choice.name }}</option></select></label>
                        <button class="civic-button" :disabled="busy || !mapping.target_id" @click="resolveMapping">Apply mapping</button>
                    </div>
                </article>
                <article>
                    <h2>Exceptions requiring review</h2>
                    <div v-if="!data.row.errors.length && !data.row.warnings.length" class="civic-empty-cell">No unresolved issues.</div>
                    <div v-for="issue in [...data.row.errors, ...data.row.warnings]" :key="issue.id" class="civic-issue-line">
                        <div><StatusPill :status="issue.severity" /><strong>{{ issue.message }}</strong></div>
                        <button v-if="issue.severity === 'warning' && data.can.ignore_warnings && !issue.ignored_at" class="civic-text-action" :disabled="busy" @click="post('ignore-warning', { warning_id: issue.id, notes: note })">Mark reviewed</button>
                    </div>
                </article>
                <article>
                    <h2>Source preservation</h2>
                    <details><summary>View raw legacy payload</summary><pre class="civic-code">{{ JSON.stringify(data.row.raw_payload, null, 2) }}</pre></details>
                </article>
            </div>
        </section>
    </template>
</template>
