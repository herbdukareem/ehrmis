<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import RichTextEditor from '../components/RichTextEditor.vue';
import { api, apiMessage } from '../lib/api';
import { loadPublicContext } from '../stores/app';

const data = ref(null);
const active = ref('platform');
const selectedMdaId = ref(null);
const feedback = ref('');
const platformLogo = ref(null);
const mdaLogo = ref(null);
const signature = ref(null);
const eligibleHeads = ref([]);
const platform = reactive({});
const mdaForm = reactive({});
const selectedMda = computed(() => data.value?.mdas.find((mda) => mda.id === selectedMdaId.value));

const fillMda = () => {
    const mda = selectedMda.value;
    if (!mda) return;
    Object.assign(mdaForm, {
        name: mda.name, code: mda.code, acronym: mda.setting?.acronym ?? mda.code,
        domain: mda.setting?.domain ?? '', vision_html: mda.setting?.vision_html ?? '',
        mission_html: mda.setting?.mission_html ?? '', phone: mda.setting?.phone ?? '',
        email: mda.setting?.email ?? '', head_rank_id: mda.setting?.head_rank_id ?? '',
        head_staff_id: mda.setting?.head_staff_id ?? '', head_title: mda.setting?.head_title ?? '',
        posting_reference_prefix: mda.setting?.posting_reference_prefix ?? `${mda.code}/STA`,
        posting_reference_suffix: mda.setting?.posting_reference_suffix ?? 'VOL.1',
    });
};
watch(selectedMdaId, fillMda);
watch(() => mdaForm.head_rank_id, async (rankId) => {
    eligibleHeads.value = rankId && selectedMdaId.value
        ? (await api.get(`/settings/mdas/${selectedMdaId.value}/eligible-heads`, { params: { rank_id: rankId } })).data.data
        : [];
});
const load = async () => {
    data.value = (await api.get('/settings')).data.data;
    if (data.value.platform) Object.assign(platform, data.value.platform);
    if (!data.value.platform) active.value = 'mda';
    selectedMdaId.value = data.value.mdas[0]?.id ?? null;
    fillMda();
};
const savePlatform = async () => {
    const form = new FormData();
    Object.entries(platform).forEach(([key, value]) => value !== null && form.append(key, value === true ? 1 : value === false ? 0 : value));
    if (platformLogo.value) form.append('logo', platformLogo.value);
    try { feedback.value = (await api.post('/settings/platform', form)).data.message; await loadPublicContext(); } catch (error) { feedback.value = apiMessage(error); }
};
const saveMda = async () => {
    const form = new FormData();
    Object.entries(mdaForm).forEach(([key, value]) => value !== null && form.append(key, value));
    if (mdaLogo.value) form.append('logo', mdaLogo.value);
    if (signature.value) form.append('signature', signature.value);
    try { feedback.value = (await api.post(`/settings/mdas/${selectedMdaId.value}`, form)).data.message; await load(); await loadPublicContext(); } catch (error) { feedback.value = apiMessage(error); }
};
onMounted(load);
</script>

<template>
    <PageHeading eyebrow="Administration" title="Platform and MDA settings" description="Manage jurisdiction access, public identity, official leadership, and document branding." />
    <LoadingBlock v-if="!data" />
    <section v-else class="civic-workspace">
        <nav class="civic-analytics-nav">
            <button v-if="data.platform" :class="{ active: active === 'platform' }" @click="active = 'platform'">Platform settings</button>
            <button :class="{ active: active === 'mda' }" @click="active = 'mda'">MDA settings</button>
        </nav>
        <div v-if="feedback" class="civic-feedback">{{ feedback }}</div>
        <form v-if="active === 'platform' && data.platform" class="civic-form-grid" @submit.prevent="savePlatform">
            <label class="civic-field"><span>State name</span><input v-model="platform.state_name" required></label>
            <label class="civic-field"><span>State code</span><input v-model="platform.state_code" required></label>
            <label class="civic-field"><span>Platform name</span><input v-model="platform.platform_name" required></label>
            <label class="civic-field"><span>Platform acronym</span><input v-model="platform.platform_acronym" required></label>
            <label class="civic-field"><span>Default domain</span><input v-model="platform.default_domain"></label>
            <label class="civic-field"><span>Support email</span><input v-model="platform.support_email" type="email"></label>
            <label class="civic-field"><span>Support phone</span><input v-model="platform.support_phone"></label>
            <label class="civic-field"><span>State/platform logo</span><input type="file" accept="image/*" @change="platformLogo = $event.target.files[0]"></label>
            <label class="civic-check"><input v-model="platform.allow_platform_login" type="checkbox"> Allow access through the platform domain</label>
            <div class="civic-field-wide"><button class="civic-button civic-button-primary">Save platform settings</button></div>
        </form>
        <form v-if="active === 'mda'" class="civic-form-grid" @submit.prevent="saveMda">
            <label class="civic-field civic-field-wide"><span>Select MDA</span><select v-model="selectedMdaId"><option v-for="mda in data.mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option></select></label>
            <label class="civic-field"><span>Official name</span><input v-model="mdaForm.name" required></label>
            <label class="civic-field"><span>Code</span><input v-model="mdaForm.code" required></label>
            <label class="civic-field"><span>Acronym</span><input v-model="mdaForm.acronym"></label>
            <label class="civic-field"><span>Domain</span><input v-model="mdaForm.domain" placeholder="moh.ehrmis.gov.ng"></label>
            <label class="civic-field"><span>Phone</span><input v-model="mdaForm.phone"></label>
            <label class="civic-field"><span>Email</span><input v-model="mdaForm.email" type="email"></label>
            <label class="civic-field"><span>MDA logo</span><input type="file" accept="image/*" @change="mdaLogo = $event.target.files[0]"></label>
            <label class="civic-field"><span>Head rank</span><select v-model="mdaForm.head_rank_id"><option value="">Not selected</option><option v-for="rank in data.ranks" :key="rank.id" :value="rank.id">{{ rank.name }} / Level {{ rank.level ?? '-' }}</option></select></label>
            <label class="civic-field"><span>Head of MDA</span><select v-model="mdaForm.head_staff_id"><option value="">Not selected</option><option v-for="staff in eligibleHeads" :key="staff.id" :value="staff.id">{{ staff.full_name }} / {{ staff.staff_number }}</option></select></label>
            <label class="civic-field"><span>Official head title</span><input v-model="mdaForm.head_title" placeholder="Permanent Secretary"></label>
            <label class="civic-field"><span>Signature image</span><input type="file" accept="image/*" @change="signature = $event.target.files[0]"></label>
            <label class="civic-field"><span>Posting reference prefix</span><input v-model="mdaForm.posting_reference_prefix" placeholder="HMB/STA"></label>
            <label class="civic-field"><span>Posting reference suffix</span><input v-model="mdaForm.posting_reference_suffix" placeholder="VOL.1"></label>
            <label class="civic-field civic-field-wide"><span>Vision</span><RichTextEditor v-model="mdaForm.vision_html" /></label>
            <label class="civic-field civic-field-wide"><span>Mission</span><RichTextEditor v-model="mdaForm.mission_html" /></label>
            <div class="civic-field-wide"><button class="civic-button civic-button-primary">Save MDA settings</button></div>
        </form>
    </section>
</template>
