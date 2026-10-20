<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextInput from '@/Components/AppTextInput.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppSkeleton from '@/Components/AppSkeleton.vue';
import AppTabs from '@/Components/AppTabs.vue';
import ProcurementReportPanel from '../components/budget-procurement/ProcurementReportPanel.vue';
import { api, apiMessage } from '../lib/api';
import { can } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const base = '/budget-procurement/reports';
const root = '/budget-procurement/reports';
const type = computed(() => route.params.type || 'dashboard');
const options = ref({});
const reports = ref({});
const loading = ref(true);
const downloading = ref('');
const error = ref('');
const downloadError = ref('');
const applied = ref({});
const filters = reactive({ mda_id: '', vendor_id: '', year: '', date_from: '', date_to: '' });
let version = 0;
const clean = (value) => Object.fromEntries(Object.entries(value).filter(([, item]) => item !== '' && item !== null && item !== undefined).map(([key, item]) => [key, String(item)]));
const dirty = computed(() => Object.keys(filters).some((key) => String(filters[key] || '') !== String(applied.value[key] || '')));
const selectedMda = computed(() => options.value.mdas?.find((mda) => String(mda.id) === String(applied.value.mda_id)));
const tabs = computed(() => [{ key: 'dashboard', label: 'Dashboard' }, ...Object.entries(options.value.reports || {}).map(([key, item]) => ({ key, label: item.title }))].map((tab) => ({ ...tab, to: { path: tab.key === 'dashboard' ? root : `${root}/${tab.key}`, query: clean({ ...applied.value, ...(tab.key === 'budget-utilization' ? { vendor_id: '' } : {}) }) }, active: type.value === tab.key })));
const heading = computed(() => options.value.reports?.[type.value]?.title || 'Reports & dashboard');
const current = computed(() => reports.value[type.value]);
const canExport = computed(() => can('export-procurement-reports') && type.value !== 'dashboard');

async function load() {
    const token = ++version;
    loading.value = true;
    error.value = '';
    downloadError.value = '';
    reports.value = {};
    try {
        const lookup = await api.get(`${base}/options`, { params: route.query.mda_id ? { mda_id: route.query.mda_id } : {} });
        if (token !== version) return;
        options.value = lookup.data.data;
        const params = { ...clean(Object.fromEntries(Object.keys(filters).map((key) => [key, route.query[key]]))), mda_id: options.value.mda_id };
        const response = await api.get(`${base}/${type.value}`, { params: { ...params, page: route.query.page || 1 } });
        if (token !== version) return;
        const result = response.data.data;
        reports.value = type.value === 'dashboard' ? result.reports : { [type.value]: result };
        applied.value = clean(type.value === 'dashboard' ? result.filters : result.meta.filters);
        for (const key of Object.keys(filters)) filters[key] = applied.value[key] || '';
    } catch (failure) { if (token === version) error.value = apiMessage(failure); }
    finally { if (token === version) loading.value = false; }
}
async function apply() {
    const query = clean(filters);
    if (JSON.stringify(clean(route.query)) === JSON.stringify(query)) await load();
    else await router.push({ path: route.path, query });
}
async function changeMda(value) { await router.push({ path: route.path, query: clean({ ...applied.value, mda_id: value, vendor_id: '' }) }); }
function page(value) { router.push({ path: route.path, query: { ...applied.value, page: value } }); }
async function download(format) {
    if (loading.value || dirty.value || downloading.value) return;
    downloading.value = format;
    downloadError.value = '';
    const target = type.value;
    const params = { ...applied.value };
    try {
        const response = await api.get(`${base}/${target}/export/${format}`, { params, responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `${target}-${params.date_to}.${format}`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    } catch (failure) {
        if (failure.response?.data instanceof Blob) {
            try { failure.response.data = JSON.parse(await failure.response.data.text()); } catch { /* Non-JSON errors use the normal fallback. */ }
        }
        downloadError.value = apiMessage(failure);
    } finally { downloading.value = ''; }
}
watch(() => route.fullPath, load, { immediate: true });
onBeforeUnmount(() => { version++; });
</script>

<template>
    <div class="bp-workspace bp-reports">
        <AppPageHeader :title="heading" eyebrow="Budget & procurement" :subtitle="selectedMda ? `${selectedMda.name} / ${applied.date_from} to ${applied.date_to}` : ''" :show-breadcrumbs="false">
            <template #actions>
                <template v-if="canExport"><AppButton v-for="format in ['xlsx', 'pdf']" :key="format" variant="outline" :disabled="loading || dirty || Boolean(downloading) || Boolean(error)" @click="download(format)"><AppIcon name="download" class="h-4 w-4" />{{ downloading === format ? 'Exporting...' : (format === 'xlsx' ? 'Excel' : 'PDF') }}</AppButton></template>
                <AppButton variant="outline" title="Refresh reports" aria-label="Refresh reports" :disabled="loading" @click="load"><AppIcon name="refresh" class="h-4 w-4" /></AppButton>
            </template>
        </AppPageHeader>
        <AppCard class="bp-panel" :aria-label="heading">
        <AppTabs :tabs="tabs" />
            <form class="bp-report-filters" @submit.prevent="apply">
                <AppSelect :model-value="filters.mda_id" label="MDA" :options="(options.mdas || []).map((row) => ({ value: row.id, label: row.name }))" @update:model-value="changeMda" />
                <AppSelect v-if="type !== 'budget-utilization' && (can('view-procurement') || can('view-supplier-payables'))" v-model="filters.vendor_id" label="Supplier" placeholder="All suppliers" :options="(options.vendors || []).map((row) => ({ value: row.id, label: row.name }))" />
                <AppTextInput v-model="filters.year" type="number" label="Financial year" min="1900" max="2200" placeholder="All years" />
                <AppTextInput v-model="filters.date_from" type="date" label="From" required />
                <AppTextInput v-model="filters.date_to" type="date" label="Through" required :min="filters.date_from" />
                <div class="bp-filter-actions"><AppButton type="submit" :disabled="loading"><AppIcon name="filter" class="h-4 w-4" />Apply</AppButton><AppButton variant="ghost" title="Reset filters" aria-label="Reset filters" :disabled="loading" @click="router.push({ path: route.path, query: { mda_id: applied.mda_id } })"><AppIcon name="close" class="h-4 w-4" /></AppButton></div>
            </form>
        <p v-if="error || downloadError" role="alert" class="bp-error">{{ error || downloadError }}</p>
        <AppSkeleton v-if="loading" :rows="8" />
        <template v-else-if="!error">
            <section v-for="(report, key) in reports" :key="key" class="bp-report-section" :aria-label="report.title">
                <div class="bp-report-section-heading"><h2>{{ report.title }}</h2><AppButton v-if="type === 'dashboard'" variant="ghost" size="sm" :to="{ path: `${root}/${key}`, query: applied }">{{ report.summary.count }} records<AppIcon name="chevronRight" class="h-4 w-4" /></AppButton><span v-else class="bp-muted">{{ report.summary.count }} records</span></div>
                <ProcurementReportPanel :report="report" :mda-id="applied.mda_id" />
            </section>
            <div v-if="current?.meta.total" class="bp-pagination"><span>{{ current.meta.from || 0 }}-{{ current.meta.to || 0 }} of {{ current.meta.total }}</span><div class="bp-row-actions"><AppButton variant="outline" size="sm" title="Previous page" aria-label="Previous page" :disabled="current.meta.current_page <= 1 || dirty" @click="page(current.meta.current_page - 1)"><AppIcon name="chevronLeft" class="h-4 w-4" /></AppButton><span>Page {{ current.meta.current_page }} of {{ current.meta.last_page }}</span><AppButton variant="outline" size="sm" title="Next page" aria-label="Next page" :disabled="current.meta.current_page >= current.meta.last_page || dirty" @click="page(current.meta.current_page + 1)"><AppIcon name="chevronRight" class="h-4 w-4" /></AppButton></div></div>
        </template>
        </AppCard>
    </div>
</template>
