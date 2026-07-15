<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import DataTable from '../components/DataTable.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import StatusPill from '../components/StatusPill.vue';
import { api, apiMessage } from '../lib/api';
import { can } from '../stores/auth';
import { pushToast } from '../stores/app';

const route = useRoute();
const data = ref(null);
const busy = ref(true);
const feedback = ref('');
const note = ref('');
const letterForm = reactive({
    subject_line: '',
    recipient_name: '',
    recipient_organisation: '',
    recipient_location: '',
    attention_line: '',
    signatory_name: '',
    signatory_title: '',
    signatory_for_line: '',
});
const approvalColumns = [
    { key: 'stage', label: 'Stage' },
    { key: 'decision', label: 'Decision' },
    { key: 'actor', label: 'Actor' },
    { key: 'comment', label: 'Comment' },
    { key: 'acted_at', label: 'Acted' },
];
const humanize = (value) => String(value ?? '')
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase());
const journey = computed(() => [
    { label: 'Origin', value: data.value?.from_mda?.name },
    { label: 'Origin station', value: data.value?.from_station?.name ?? data.value?.from_department?.name ?? 'Unassigned' },
    { label: 'Destination', value: data.value?.to_mda?.name },
    { label: 'Destination station', value: data.value?.to_station?.name ?? data.value?.to_department?.name ?? 'Unassigned' },
]);
const isInterMda = computed(() => Number(data.value?.from_mda?.id ?? 0) !== Number(data.value?.to_mda?.id ?? 0));
const latestApproval = computed(() => (data.value?.approvals ?? []).at(-1) ?? null);
const hasIssuedLetter = computed(() => Boolean(
    data.value?.letter
    && data.value.letter.status !== 'revoked'
    && (data.value.letter.letter_number || data.value?.issued_at),
));
const activeLetter = computed(() => (hasIssuedLetter.value ? data.value?.letter ?? null : null));
const currentStageKey = computed(() => {
    if (!data.value) return 'draft';
    if (data.value.status === 'rejected') {
        return {
            submission: 'draft',
            review: 'submitted',
            origin_mda: 'origin',
            receiving_mda: 'receiving',
            final: 'final',
        }[latestApproval.value?.stage] ?? 'draft';
    }

    if (isInterMda.value) {
        return {
            draft: 'draft',
            submitted: 'submitted',
            from_mda_approved: 'origin',
            receiving_mda_approved: 'receiving',
            approved: 'final',
            issued: 'issued',
            effected: 'effected',
        }[data.value.status] ?? 'draft';
    }

    return {
        draft: 'draft',
        submitted: 'submitted',
        approved: 'origin',
        issued: 'issued',
        effected: 'effected',
    }[data.value.status] ?? 'draft';
});
const workflowStages = computed(() => {
    const stages = [
        { key: 'draft', label: 'Draft request', hint: 'Prepared but not yet routed for review.' },
        { key: 'submitted', label: 'Submitted', hint: isInterMda.value ? 'Waiting for origin MDA approval.' : 'Waiting for approving officer action.' },
        { key: 'origin', label: isInterMda.value ? 'Origin approval' : 'Approval', hint: isInterMda.value ? 'Origin MDA confirms the release.' : 'This approval clears the request.' },
    ];

    if (isInterMda.value) {
        stages.push(
            { key: 'receiving', label: 'Receiving approval', hint: 'Receiving MDA accepts the transfer.' },
            { key: 'final', label: 'Final approval', hint: 'Final authority clears the inter-MDA posting.' },
        );
    }

    stages.push(
        { key: 'issued', label: 'Letter issued', hint: 'Official posting letter is generated and printed.' },
        { key: 'effected', label: 'Effected', hint: 'Staff employment record is updated.' },
    );

    const stageStates = Object.fromEntries(stages.map((stage) => [stage.key, 'pending']));
    const activeIndex = stages.findIndex((stage) => stage.key === currentStageKey.value);

    if (data.value?.status === 'rejected') {
        if (activeIndex >= 0) {
            stages.slice(0, activeIndex).forEach((stage) => {
                stageStates[stage.key] = 'complete';
            });
            stageStates[stages[activeIndex].key] = 'blocked';
        }
    } else if (activeIndex >= 0) {
        stages.slice(0, activeIndex).forEach((stage) => {
            stageStates[stage.key] = 'complete';
        });
        stageStates[stages[activeIndex].key] = 'active';
    }

    if (!hasIssuedLetter.value) {
        stageStates.issued = data.value?.status === 'effected' ? 'pending' : stageStates.issued;
    } else if (data.value?.status === 'effected') {
        stageStates.issued = 'complete';
    }

    return stages.map((stage) => ({
        ...stage,
        state: stageStates[stage.key] ?? 'pending',
    }));
});
const workflowSummary = computed(() => {
    if (!data.value) {
        return {
            title: 'Loading workflow',
            detail: '',
            next: '',
        };
    }

    const summaries = {
        draft: {
            title: 'Draft request',
            detail: 'The posting has been prepared, but approval cannot start until the request is submitted.',
            next: 'Submit for approval',
        },
        submitted: {
            title: isInterMda.value ? 'Awaiting origin MDA approval' : 'Awaiting approval',
            detail: isInterMda.value
                ? 'This inter-MDA posting is now with the origin MDA for the first approval.'
                : 'This same-MDA posting moves to approved as soon as the approving officer records the decision.',
            next: isInterMda.value ? 'Approve at origin MDA or reject' : 'Approve posting or reject',
        },
        from_mda_approved: {
            title: 'Awaiting receiving MDA approval',
            detail: 'Origin approval is complete. The receiving MDA now needs to accept the transfer.',
            next: 'Approve at receiving MDA or reject',
        },
        receiving_mda_approved: {
            title: 'Awaiting final approval',
            detail: 'Both MDAs have acted. Final platform approval is the remaining approval step.',
            next: 'Record final approval or reject',
        },
        approved: {
            title: 'Posting approved',
            detail: 'The workflow approval is complete. The next operational steps are letter issue and staff-record effecting.',
            next: 'Issue letter or effect the posting',
        },
        issued: {
            title: 'Letter issued',
            detail: 'The official posting letter has been produced. The staff employment record can now be effected.',
            next: 'Effect on staff record',
        },
        effected: {
            title: hasIssuedLetter.value ? 'Posting effected' : 'Posting effected, letter pending',
            detail: hasIssuedLetter.value
                ? 'The posting has been completed on the staff record and no further workflow action is required.'
                : 'The staff record has been updated, but the posting letter was not generated yet. You can still issue it now.',
            next: hasIssuedLetter.value ? 'No action pending' : 'Issue posting letter',
        },
        rejected: {
            title: 'Posting rejected',
            detail: latestApproval.value?.comment
                ? `Review note: ${latestApproval.value.comment}`
                : 'The request was rejected and must be corrected before it can be submitted again.',
            next: 'Revise and resubmit',
        },
    };

    return summaries[data.value.status] ?? summaries.draft;
});
const routeLabel = computed(() => isInterMda.value ? 'Inter-MDA transfer' : 'Same-MDA transfer');
const displayTitle = computed(() => {
    if (!data.value) return '';
    if ((data.value.staff_count ?? 1) > 1) {
        return `${data.value.staff_count} staff posting request`;
    }

    return data.value.staff?.full_name ?? 'Posting request';
});
const canIssueLetter = computed(() => can('print-posting-letters') && ((data.value?.status === 'approved') || (data.value?.status === 'effected' && !hasIssuedLetter.value)));
const notePlaceholder = computed(() => data.value?.status === 'rejected'
    ? 'Explain what changed before resubmission'
    : 'Optional for approval, required for rejection');
const canReject = computed(() => {
    const status = data.value?.status;

    if (!status || ['effected', 'cancelled'].includes(status)) {
        return false;
    }

    return can('approve-own-mda-postings')
        || can('approve-receiving-mda-postings')
        || can('approve-inter-mda-postings');
});
const revertAction = computed(() => {
    const status = data.value?.status;

    if (status === 'submitted' && can('create-postings')) {
        return 'Return to draft';
    }

    if (status === 'from_mda_approved' && can('approve-own-mda-postings')) {
        return 'Return to submitted';
    }

    if (status === 'receiving_mda_approved' && can('approve-receiving-mda-postings')) {
        return 'Return to origin-approved stage';
    }

    if (status === 'approved') {
        if (!isInterMda.value && can('approve-own-mda-postings')) {
            return 'Return to submitted';
        }

        if (isInterMda.value && can('approve-inter-mda-postings')) {
            return 'Return to receiving approval';
        }
    }

    if (status === 'issued' && can('print-posting-letters')) {
        return 'Return to approved';
    }

    return null;
});

const syncLetterForm = () => {
    Object.assign(letterForm, {
        subject_line: data.value?.letter_draft?.subject_line ?? '',
        recipient_name: data.value?.letter_draft?.recipient_name ?? '',
        recipient_organisation: data.value?.letter_draft?.recipient_organisation ?? '',
        recipient_location: data.value?.letter_draft?.recipient_location ?? '',
        attention_line: data.value?.letter_draft?.attention_line ?? '',
        signatory_name: data.value?.letter_draft?.signatory_name ?? '',
        signatory_title: data.value?.letter_draft?.signatory_title ?? '',
        signatory_for_line: data.value?.letter_draft?.signatory_for_line ?? '',
    });
};

const load = async () => {
    busy.value = true;
    data.value = (await api.get(`/posting-requests/${route.params.id}`)).data.data;
    syncLetterForm();
    busy.value = false;
};

const runAction = async (name, payload = {}) => {
    feedback.value = '';
    try {
        const response = await api.post(`/posting-requests/${route.params.id}/${name}`, payload);
        pushToast(response.data.message);
        await load();
    } catch (error) {
        feedback.value = apiMessage(error);
    }
};

onMounted(load);
</script>

<template>
    <LoadingBlock v-if="busy" />
    <template v-else>
        <PageHeading :eyebrow="data.request_number" :title="displayTitle" description="Posting request review, approval, letter issue, and staff-record effecting.">
            <StatusPill :status="data.status" />
        </PageHeading>
        <div v-if="feedback" class="civic-error">{{ feedback }}</div>

        <section class="civic-decision-bar">
            <div v-for="item in journey" :key="item.label"><span>{{ item.label }}</span><strong>{{ item.value }}</strong></div>
        </section>

        <section class="civic-workspace civic-posting-workflow">
            <div class="civic-section-heading civic-posting-heading">
                <div>
                    <span class="civic-kicker">Workflow actions</span>
                    <h2>Posting decision</h2>
                    <p>{{ workflowSummary.detail }}</p>
                </div>
                <StatusPill :status="data.status" />
            </div>

            <div class="civic-posting-flow">
                <div class="civic-posting-stage-list">
                    <article
                        v-for="(stage, index) in workflowStages"
                        :key="stage.key"
                        class="civic-posting-stage"
                        :data-state="stage.state"
                    >
                        <span class="civic-posting-stage-index">{{ index + 1 }}</span>
                        <div class="civic-posting-stage-copy">
                            <strong>{{ stage.label }}</strong>
                            <small>{{ stage.hint }}</small>
                        </div>
                        <span class="civic-posting-stage-state">{{ humanize(stage.state) }}</span>
                    </article>
                </div>

                <div class="civic-posting-action-panel">
                    <div class="civic-posting-summary-grid">
                        <div>
                            <span>Workflow</span>
                            <strong>{{ routeLabel }}</strong>
                        </div>
                        <div>
                            <span>Current stage</span>
                            <strong>{{ workflowSummary.title }}</strong>
                        </div>
                        <div>
                            <span>Next action</span>
                            <strong>{{ workflowSummary.next }}</strong>
                        </div>
                    </div>

                    <div
                        v-if="data.status === 'rejected' || latestApproval?.comment"
                        class="civic-import-note civic-posting-note"
                    >
                        <strong>{{ data.status === 'rejected' ? 'Rejection note' : 'Latest review note' }}</strong>
                        <span>{{ latestApproval?.comment ?? 'No note captured.' }}</span>
                    </div>

                    <label class="civic-field civic-field-wide">
                        <span>Decision note</span>
                        <input v-model="note" :placeholder="notePlaceholder">
                    </label>

                    <div class="civic-action-cluster civic-posting-action-wrap">
                        <button
                            v-if="can('create-postings') && ['draft', 'rejected'].includes(data.status)"
                            class="civic-button"
                            type="button"
                            @click="runAction('submit')"
                        >
                            {{ data.status === 'rejected' ? 'Resubmit for approval' : 'Submit for approval' }}
                        </button>
                        <button
                            v-if="can('approve-own-mda-postings') && data.status === 'submitted'"
                            class="civic-button civic-button-primary"
                            type="button"
                            @click="runAction('approve-origin', { comment: note })"
                        >
                            {{ isInterMda ? 'Approve at origin MDA' : 'Approve posting' }}
                        </button>
                        <button
                            v-if="can('approve-receiving-mda-postings') && data.status === 'from_mda_approved'"
                            class="civic-button civic-button-primary"
                            type="button"
                            @click="runAction('approve-receiving', { comment: note })"
                        >
                            Approve at receiving MDA
                        </button>
                        <button
                            v-if="can('approve-inter-mda-postings') && ['from_mda_approved', 'receiving_mda_approved'].includes(data.status)"
                            class="civic-button civic-button-primary"
                            type="button"
                            @click="runAction('approve-final', { comment: note })"
                        >
                            Record final approval
                        </button>
                        <button
                            v-if="canReject"
                            class="civic-button civic-button-danger"
                            type="button"
                            @click="runAction('reject', { comment: note })"
                        >
                            Reject request
                        </button>
                        <button
                            v-if="revertAction"
                            class="civic-button"
                            type="button"
                            @click="runAction('revert', { comment: note })"
                        >
                            {{ revertAction }}
                        </button>
                        <button
                            v-if="canIssueLetter"
                            class="civic-button"
                            type="button"
                            @click="runAction('issue', { ...letterForm })"
                        >
                            {{ data.status === 'effected' ? 'Issue missed posting letter' : 'Issue posting letter' }}
                        </button>
                        <a
                            v-if="activeLetter?.pdf_url"
                            class="civic-button"
                            :href="activeLetter.pdf_url"
                            target="_blank"
                            rel="noopener"
                        >
                            Open letter PDF
                        </a>
                        <button
                            v-if="can('effect-postings') && ['approved', 'issued'].includes(data.status)"
                            class="civic-button civic-button-primary"
                            type="button"
                            @click="runAction('effect')"
                        >
                            Effect on staff record
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="canIssueLetter || data.letter" class="civic-workspace">
            <div class="civic-section-heading">
                <div>
                    <span class="civic-kicker">Official letter</span>
                    <h2>Letter preparation</h2>
                    <p>Use the manual-style outward letter details below. The saved values will be frozen on issue and reused for every reprint.</p>
                </div>
                <StatusPill :status="data.letter?.status ?? 'pending'" />
            </div>
            <div class="civic-form-grid">
                <div class="civic-field">
                    <span>Official reference</span>
                    <strong>{{ data.letter_draft?.official_reference ?? data.letter?.official_reference ?? 'Pending issue' }}</strong>
                </div>
                <label class="civic-field civic-field-wide">
                    <span>Letter subject</span>
                    <input v-model="letterForm.subject_line" :disabled="!canIssueLetter">
                </label>
                <label class="civic-field">
                    <span>Recipient name</span>
                    <input v-model="letterForm.recipient_name" :disabled="!canIssueLetter" placeholder="The Head of Hospital Services">
                </label>
                <label class="civic-field">
                    <span>Recipient organisation</span>
                    <input v-model="letterForm.recipient_organisation" :disabled="!canIssueLetter" placeholder="GH. Suleja">
                </label>
                <label class="civic-field">
                    <span>Recipient location</span>
                    <input v-model="letterForm.recipient_location" :disabled="!canIssueLetter" placeholder="Optional location line">
                </label>
                <label class="civic-field">
                    <span>Attention</span>
                    <input v-model="letterForm.attention_line" :disabled="!canIssueLetter" placeholder="HOD HIM.">
                </label>
                <label class="civic-field">
                    <span>Signatory name</span>
                    <input v-model="letterForm.signatory_name" :disabled="!canIssueLetter">
                </label>
                <label class="civic-field">
                    <span>Signatory portfolio/title</span>
                    <input v-model="letterForm.signatory_title" :disabled="!canIssueLetter" placeholder="Director Health Infor. Magt.">
                </label>
                <label class="civic-field">
                    <span>For line</span>
                    <input v-model="letterForm.signatory_for_line" :disabled="!canIssueLetter" placeholder="For: Executive Medical Director">
                </label>
            </div>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Staff movement</span><h2>Posting details</h2></div></div>
            <div class="civic-form-grid">
                <div class="civic-field"><span>Staff count</span><strong>{{ data.staff_count }}</strong></div>
                <div class="civic-field"><span>Posting type</span><strong>{{ humanize(data.posting_type) }}</strong></div>
                <div class="civic-field"><span>Effective date</span><strong>{{ data.effective_date }}</strong></div>
                <div class="civic-field civic-field-wide"><span>Reason</span><p>{{ data.reason ?? 'No reason entered.' }}</p></div>
                <div class="civic-field"><span>Official reference</span><strong>{{ activeLetter?.official_reference ?? 'Not issued' }}</strong></div>
                <div class="civic-field"><span>Issued at</span><strong>{{ data.issued_at ?? 'Pending' }}</strong></div>
                <div class="civic-field"><span>PDF</span><a v-if="activeLetter?.pdf_url" class="civic-record-link" :href="activeLetter.pdf_url" target="_blank" rel="noopener">Open letter PDF</a><strong v-else>Pending</strong></div>
                <div class="civic-field"><span>Effected at</span><strong>{{ data.effected_at ?? 'Pending' }}</strong></div>
            </div>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Posting list</span><h2>Selected staff</h2></div></div>
            <DataTable :columns="[
                { key: 'staff_number', label: 'Staff number' },
                { key: 'full_name', label: 'Name' },
                { key: 'department', label: 'Department' },
                { key: 'station', label: 'Station' },
                { key: 'rank', label: 'Rank' },
            ]" :rows="data.items" row-key="staff_id">
                <template #department="{ row }">{{ row.department?.name ?? 'Unassigned' }}</template>
                <template #station="{ row }">{{ row.station?.name ?? 'Unassigned' }}</template>
                <template #rank="{ row }">{{ row.rank?.name ?? 'Not recorded' }}</template>
            </DataTable>
        </section>

        <section class="civic-workspace">
            <div class="civic-section-heading"><div><span class="civic-kicker">Audit trail</span><h2>Approvals</h2></div></div>
            <DataTable :columns="approvalColumns" :rows="data.approvals" row-key="acted_at">
                <template #stage="{ row }">{{ humanize(row.stage) }}</template>
                <template #decision="{ row }"><StatusPill :status="row.decision" /></template>
                <template #actor="{ row }">{{ row.actor?.name ?? 'System' }}</template>
                <template #comment="{ row }">{{ row.comment ?? 'No note' }}</template>
            </DataTable>
        </section>
    </template>
</template>
