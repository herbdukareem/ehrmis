<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppModal from '../components/AppModal.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';

const route = useRoute();

const rows = ref([]);
const report = ref(null);
const error = ref('');
const loading = ref(true);
const saving = ref(false);
const returnOpen = ref(false);
const returnReason = ref('');
const evidenceType = ref('file');
const evidenceTitle = ref('');
const evidenceNotes = ref('');
const evidenceUrl = ref('');
const evidenceFile = ref(null);

const reportCountLabel = computed(() => `${rows.value.length} ${rows.value.length === 1 ? 'report' : 'reports'} available`);
const reportHistory = computed(() => report.value?.history ?? []);
const narrativeAvailable = computed(() => {
    if (!report.value) {
        return false;
    }

    return [
        report.value.achievement_summary,
        report.value.challenges,
        report.value.corrective_action,
        report.value.next_period_action,
        report.value.remarks,
    ].some(Boolean);
});

const achievementLabel = (item) => item.achievement_ratio == null ? 'Not calculated' : `${(item.achievement_ratio * 100).toFixed(1)}%`;

const load = async () => {
    loading.value = true;

    try {
        rows.value = (await api.get('/workplan-progress-reports')).data.data ?? [];

        if (route.params.id) {
            report.value = (await api.get(`/workplan-progress-reports/${route.params.id}`)).data.data;
        }
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        loading.value = false;
    }
};

const action = async (path, payload = {}) => {
    saving.value = true;

    try {
        report.value = (await api.post(`/workplan-progress-reports/${report.value.id}/${path}`, payload)).data.data;
        await load();
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        saving.value = false;
        returnOpen.value = false;
    }
};

const save = async () => {
    saving.value = true;

    try {
        report.value = (await api.put(`/workplan-progress-reports/${report.value.id}`, report.value)).data.data;
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        saving.value = false;
    }
};

const sync = async () => {
    saving.value = true;

    try {
        report.value = (await api.put(`/workplan-progress-reports/${report.value.id}/indicators`, {
            indicators: report.value.indicators.map((item) => ({
                workplan_indicator_id: item.indicator_id,
                actual_value: item.actual_value,
                verification_note: item.verification_note,
            })),
        })).data.data;
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        saving.value = false;
    }
};

const addEvidence = async () => {
    if (!evidenceTitle.value.trim()) {
        error.value = 'An evidence title is required.';
        return;
    }

    if (evidenceType.value === 'file' && !evidenceFile.value) {
        error.value = 'Select a file to upload.';
        return;
    }

    saving.value = true;

    try {
        const form = new FormData();
        form.append('title', evidenceTitle.value);
        form.append('evidence_type', evidenceType.value);

        if (evidenceNotes.value) {
            form.append('notes', evidenceNotes.value);
        }

        if (evidenceType.value === 'file') {
            form.append('file', evidenceFile.value);
        } else {
            form.append('external_url', evidenceUrl.value);
        }

        await api.post(`/workplan-progress-reports/${report.value.id}/evidence`, form);
        evidenceTitle.value = '';
        evidenceNotes.value = '';
        evidenceUrl.value = '';
        evidenceFile.value = null;
        await load();
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        saving.value = false;
    }
};

const removeEvidence = async (id) => {
    if (!confirm('Delete this evidence?')) {
        return;
    }

    saving.value = true;

    try {
        await api.delete(`/workplan-evidence/${id}`);
        await load();
    } catch (e) {
        error.value = apiMessage(e);
    } finally {
        saving.value = false;
    }
};

onMounted(load);
</script>

<template>
    <PageHeading
        eyebrow="Performance management"
        title="Progress Reporting"
        description="Revision-specific quarterly and annual reporting with cumulative actuals, evidence, and verification controls."
    />

    <div v-if="error" class="civic-error">{{ error }}</div>
    <LoadingBlock v-else-if="loading" />

    <div v-else-if="!report" class="civic-page-stack">
        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Report register</span>
                    <h2>Available progress reports</h2>
                    <p>Reports are revision-specific. Quarterly actuals are cumulative totals achieved as at the end of the selected reporting period.</p>
                </div>
                <span class="civic-inline-note">{{ reportCountLabel }}</span>
            </div>

            <div class="civic-workspace-body">
                <div v-if="rows.length" class="civic-list-stack">
                    <article v-for="row in rows" :key="row.id" class="civic-list-card">
                        <div class="civic-list-card-header">
                            <div>
                                <h3 class="civic-list-card-title">
                                    <RouterLink class="civic-record-link" :to="`/workplan-progress/${row.id}`">
                                        {{ row.activity.code }} - {{ row.activity.title }}
                                    </RouterLink>
                                </h3>
                                <p class="civic-list-card-meta">
                                    {{ row.activity.department?.name || 'MDA-wide activity' }} - {{ row.period.toUpperCase() }}
                                </p>
                            </div>
                            <StatusPill :status="row.status" />
                        </div>
                    </article>
                </div>

                <p v-else class="civic-performance-empty">No reports yet. Create one from an active Workplan reporting matrix.</p>
            </div>
        </section>
    </div>

    <div v-else class="civic-page-stack">
        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">{{ report.activity.department?.name || 'MDA-wide activity' }} - {{ report.period.toUpperCase() }}</span>
                    <h2>{{ report.activity.code }} - {{ report.activity.title }}</h2>
                    <p>Responsible officer: {{ report.activity.responsible_staff?.full_name || 'Unassigned' }}. Actuals are cumulative as at the end of this period.</p>
                </div>
                <StatusPill :status="report.status" />
            </div>

            <div class="civic-workspace-body civic-split-toolbar">
                <div class="civic-copy-block">
                    <p v-if="report.status === 'returned'"><strong>Returned for correction:</strong> {{ report.return_reason }}</p>
                    <p v-else>Use this report to capture delivery progress, verified expenditure, indicator actuals, and supporting evidence for the selected period.</p>
                </div>
                <div class="civic-aside-note">
                    Missing or unverified expected reports do not contribute measured performance. Indicator actuals should remain cumulative within the reporting year.
                </div>
            </div>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Narrative record</span>
                    <h2>Progress narrative</h2>
                    <p>Summarize what was achieved, what constrained delivery, and what happens next.</p>
                </div>
                <span v-if="report.can.edit" class="civic-inline-note">Editable draft</span>
            </div>

            <div v-if="report.can.edit" class="civic-form-grid">
                <label class="civic-field">
                    <span>Reported expenditure</span>
                    <input v-model="report.reported_expenditure" type="number" min="0" step="0.01">
                </label>
                <label class="civic-field civic-field-wide">
                    <span>Achievement summary</span>
                    <textarea v-model="report.achievement_summary" rows="3"></textarea>
                </label>
                <label class="civic-field civic-field-wide">
                    <span>Challenges</span>
                    <textarea v-model="report.challenges" rows="2"></textarea>
                </label>
                <label class="civic-field civic-field-wide">
                    <span>Corrective action</span>
                    <textarea v-model="report.corrective_action" rows="2"></textarea>
                </label>
                <label class="civic-field civic-field-wide">
                    <span>Next-period action</span>
                    <textarea v-model="report.next_period_action" rows="2"></textarea>
                </label>
                <label class="civic-field civic-field-wide">
                    <span>Remarks</span>
                    <textarea v-model="report.remarks" rows="2"></textarea>
                </label>
                <div class="civic-field civic-form-action">
                    <span>Save narrative</span>
                    <button class="civic-button civic-button-primary" :disabled="saving" @click="save">Save narrative</button>
                </div>
            </div>

            <div v-else class="civic-workspace-body">
                <div v-if="narrativeAvailable" class="civic-copy-block">
                    <p v-if="report.achievement_summary"><strong>Achievement summary:</strong> {{ report.achievement_summary }}</p>
                    <p v-if="report.challenges"><strong>Challenges:</strong> {{ report.challenges }}</p>
                    <p v-if="report.corrective_action"><strong>Corrective action:</strong> {{ report.corrective_action }}</p>
                    <p v-if="report.next_period_action"><strong>Next-period action:</strong> {{ report.next_period_action }}</p>
                    <p v-if="report.remarks"><strong>Remarks:</strong> {{ report.remarks }}</p>
                </div>
                <p v-else class="civic-performance-empty">No narrative details have been recorded for this report.</p>
            </div>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Indicator actuals</span>
                    <h2>Measured delivery</h2>
                    <p>Each actual should represent the cumulative value achieved by the close of the selected period.</p>
                </div>
            </div>

            <div class="civic-workspace-body">
                <div v-for="item in report.indicators" :key="item.id" class="civic-progress-indicator">
                    <div>
                        <h4>{{ item.code }} - {{ item.indicator }}</h4>
                        <p>
                            Period target: {{ item.target_value_snapshot ?? 'N/A' }} {{ item.unit || '' }}
                            <br>
                            Achievement: {{ achievementLabel(item) }}
                        </p>
                    </div>

                    <div v-if="report.can.edit" class="civic-progress-indicator-form">
                        <label class="civic-field">
                            <span>Cumulative actual</span>
                            <input v-model="item.actual_value" type="number" step="0.0001">
                        </label>
                        <label class="civic-field">
                            <span>Note</span>
                            <input v-model="item.verification_note">
                        </label>
                    </div>

                    <div v-else class="civic-copy-block">
                        <p><strong>Cumulative actual:</strong> {{ item.actual_value ?? 'Not recorded' }}</p>
                        <p v-if="item.verification_note"><strong>Note:</strong> {{ item.verification_note }}</p>
                    </div>
                </div>

                <div v-if="report.can.edit" class="civic-button-row" style="margin-top: 16px;">
                    <button class="civic-button civic-button-primary" :disabled="saving" @click="sync">Save indicator actuals</button>
                </div>
            </div>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Evidence register</span>
                    <h2>Attachments and references</h2>
                    <p>Upload supporting files or record authoritative links used to verify reported delivery.</p>
                </div>
                <span v-if="report.status === 'verified'" class="civic-inline-note">Evidence locked after verification</span>
            </div>

            <div class="civic-workspace-body">
                <div v-if="report.evidence.length" class="civic-evidence-list">
                    <article v-for="item in report.evidence" :key="item.id" class="civic-evidence-item">
                        <div class="civic-list-card-header">
                            <div>
                                <h3 class="civic-list-card-title">{{ item.title }}</h3>
                                <p class="civic-list-card-meta">{{ item.evidence_type }}</p>
                            </div>
                        </div>

                        <div class="civic-chip-row">
                            <a v-if="item.download_url" class="civic-record-link" :href="item.download_url">Download</a>
                            <a v-if="item.external_url" class="civic-record-link" :href="item.external_url" target="_blank" rel="noopener">Open link</a>
                            <button v-if="report.can.delete_evidence" class="civic-button" :disabled="saving" @click="removeEvidence(item.id)">Delete</button>
                        </div>
                    </article>
                </div>

                <p v-else class="civic-performance-empty">No evidence attached.</p>
            </div>

            <div v-if="report.can.add_evidence" class="civic-form-grid">
                <label class="civic-field">
                    <span>Evidence type</span>
                    <select v-model="evidenceType">
                        <option value="file">Upload file</option>
                        <option value="link">Add link</option>
                    </select>
                </label>

                <label class="civic-field">
                    <span>Title</span>
                    <input v-model="evidenceTitle">
                </label>

                <label v-if="evidenceType === 'file'" class="civic-field civic-field-wide">
                    <span>File (PDF, Office document or image; max 10 MB)</span>
                    <input type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" @change="evidenceFile = $event.target.files[0]">
                </label>

                <label v-else class="civic-field civic-field-wide">
                    <span>External URL</span>
                    <input v-model="evidenceUrl" type="url" placeholder="https://">
                </label>

                <label class="civic-field civic-field-wide">
                    <span>Notes</span>
                    <textarea v-model="evidenceNotes" rows="2"></textarea>
                </label>

                <div class="civic-field civic-form-action">
                    <span>Add evidence</span>
                    <button class="civic-button civic-button-primary" :disabled="saving" @click="addEvidence">Add evidence</button>
                </div>
            </div>
        </section>

        <section v-if="reportHistory.length" class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Audit trail</span>
                    <h2>Report history</h2>
                    <p>Track the workflow events recorded against this report.</p>
                </div>
            </div>

            <div class="civic-workspace-body">
                <div class="civic-timeline-list">
                    <article v-for="entry in reportHistory" :key="`${entry.event}-${entry.occurred_at}`" class="civic-timeline-item">
                        <strong>{{ entry.event.replace('workplan.progress.', '') }}</strong>
                        <div class="civic-list-card-meta">
                            {{ entry.actor?.name || 'System' }} - {{ entry.occurred_at }}
                            <br v-if="entry.reason">
                            <span v-if="entry.reason">{{ entry.reason }}</span>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section class="civic-workspace">
            <div class="civic-workspace-body">
                <div class="civic-button-row">
                    <button v-if="report.can.submit" class="civic-button civic-button-primary" :disabled="saving" @click="action('submit')">
                        {{ report.status === 'returned' ? 'Resubmit' : 'Submit' }}
                    </button>
                    <button v-if="report.can.verify" class="civic-button civic-button-primary" :disabled="saving" @click="action('verify')">Verify</button>
                    <button v-if="report.can.return" class="civic-button" @click="returnOpen = true">Return for correction</button>
                </div>
            </div>
        </section>
    </div>

    <AppModal :open="returnOpen" title="Return progress report" @close="returnOpen = false">
        <label class="civic-field">
            <span>Correction reason</span>
            <textarea v-model="returnReason" rows="4"></textarea>
        </label>
        <template #actions>
            <button class="civic-button civic-button-primary" @click="action('return', { return_reason: returnReason })">Return report</button>
        </template>
    </AppModal>
</template>
