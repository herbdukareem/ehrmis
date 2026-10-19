<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppButton from '@/Components/AppButton.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppCard from '@/Components/AppCard.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTabs from '@/Components/AppTabs.vue';
import AppTable from '@/Components/AppTable.vue';
import AppSkeleton from '@/Components/AppSkeleton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import MoneyDisplay from '@/Components/MoneyDisplay.vue';
import { api, apiMessage } from '../lib/api';
import { setNotificationSummary } from '../stores/procurementNotifications';

const route = useRoute();
const router = useRouter();
const base = '/budget-procurement/notifications';
const data = ref({ rows: [], mdas: [], categories: {}, counts: {}, meta: {} });
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const actionError = ref('');
let version = 0;
let timer;
const params = computed(() => ({ ...route.query, mda_id: route.query.mda_id || data.value.mda_id }));
const tabs = computed(() => [
    { label: `All (${data.value.total_count || 0})`, to: { path: base, query: { ...params.value, unread: undefined, page: undefined } }, active: route.query.unread !== '1' },
    { label: `Unread (${data.value.unread_count || 0})`, to: { path: base, query: { ...params.value, unread: '1', page: undefined } }, active: route.query.unread === '1' },
]);
const headers = [{ key: 'alert', label: 'Notification' }, { key: 'reference', label: 'Record' }, { key: 'occurred_at', label: 'Date' }, { key: 'actions', label: '' }];

async function load(background = false) {
    if (background && (loading.value || saving.value)) return;
    const token = ++version;
    if (!background) { loading.value = true; error.value = ''; actionError.value = ''; }
    try {
        const response = await api.get(base, { params: params.value, background });
        if (token !== version) return;
        data.value = response.data.data;
        setNotificationSummary(data.value);
        if (background) error.value = '';
    } catch (failure) {
        if (token === version) error.value = apiMessage(failure);
    } finally { if (token === version) loading.value = false; }
}
function filter(key, value) { router.push({ path: base, query: { ...params.value, [key]: value || undefined, page: undefined } }); }
function changeMda(value) { router.push({ path: base, query: { mda_id: value } }); }
async function mark(row, read = true) {
    if (saving.value) return false;
    saving.value = true;
    actionError.value = '';
    try {
        await api.post(`${base}/read`, { mda_id: data.value.mda_id, keys: [row.id], read });
        saving.value = false;
        await load(true);
        return true;
    } catch (failure) { actionError.value = apiMessage(failure); return false; }
    finally { saving.value = false; }
}
async function open(row, event) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    const origin = route.fullPath;
    if (row.unread && !await mark(row)) return;
    if (route.fullPath !== origin) return;
    router.push(row.url);
}
async function readAll() {
    if (saving.value) return;
    saving.value = true;
    actionError.value = '';
    try {
        await api.post(`${base}/read-all`, params.value);
        saving.value = false;
        await load();
    } catch (failure) { actionError.value = apiMessage(failure); }
    finally { saving.value = false; }
}
function date(value) { return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'Africa/Lagos' }).format(new Date(value)) : '-'; }
function changePage(page) { router.push({ path: base, query: { ...params.value, page } }); }
function refresh() { if (!document.hidden) load(true); }
watch(() => route.fullPath, () => load(), { immediate: true });
onMounted(() => { timer = setInterval(refresh, 60000); document.addEventListener('visibilitychange', refresh); });
onBeforeUnmount(() => { version++; clearInterval(timer); document.removeEventListener('visibilitychange', refresh); });
</script>

<template>
    <div class="bp-workspace bp-notifications">
        <AppPageHeader title="Notifications" eyebrow="Budget & procurement" :subtitle="data.mdas.find((mda) => mda.id === Number(data.mda_id))?.name" :show-breadcrumbs="false">
            <template #actions>
                <AppButton variant="outline" :disabled="loading || saving || Boolean(error) || !data.filtered_unread_count" @click="readAll"><AppIcon name="checkCircle" class="h-4 w-4" />Mark all read</AppButton>
                <AppButton variant="outline" title="Refresh notifications" aria-label="Refresh notifications" :disabled="loading || saving" @click="load()"><AppIcon name="refresh" class="h-4 w-4" /></AppButton>
            </template>
        </AppPageHeader>
        <AppCard class="bp-panel" aria-label="Notifications">
            <div class="bp-notification-filters">
                <AppSelect :model-value="data.mda_id" label="MDA" :options="data.mdas.map((mda) => ({ value: mda.id, label: mda.name }))" @update:model-value="changeMda" />
                <AppSelect :model-value="route.query.category || ''" label="Category" placeholder="All categories" :options="Object.entries(data.categories).map(([value, label]) => ({ value, label: `${label} (${data.counts[value] || 0})` }))" @update:model-value="filter('category', $event)" />
            </div>
        <AppTabs :tabs="tabs" />
        <p v-if="error || actionError" role="alert" class="bp-error">{{ error || actionError }}</p>
        <AppSkeleton v-if="loading" :rows="6" />
        <template v-else-if="!error">
            <AppTable v-if="data.rows.length" :headers="headers" :rows="data.rows">
                <template #cell-alert="{ row }">
                    <div class="bp-notification-copy" :class="{ 'bp-notification-unread': row.unread }">
                        <div class="bp-notification-title"><AppIcon :name="row.severity === 'info' ? 'info' : 'exclamation'" class="h-4 w-4 shrink-0" :class="`bp-alert-${row.severity}`" /><a :href="row.url" class="bp-link" @click="open(row, $event)">{{ row.title }}</a><AppBadge :tone="row.unread ? 'info' : 'neutral'">{{ row.unread ? 'Unread' : 'Read' }}</AppBadge><AppBadge v-if="row.is_reminder" tone="warning">Reminder</AppBadge></div>
                        <p>{{ row.message }}</p>
                        <MoneyDisplay v-if="row.amount !== undefined" :amount="row.amount" :currency="row.currency" />
                    </div>
                </template>
                <template #cell-reference="{ row }"><a :href="row.url" class="bp-link" @click="open(row, $event)">{{ row.reference }}</a></template>
                <template #cell-occurred_at="{ value }">{{ date(value) }}</template>
                <template #cell-actions="{ row }"><AppButton variant="ghost" size="sm" :disabled="saving" :title="row.unread ? 'Mark read' : 'Mark unread'" :aria-label="row.unread ? 'Mark read' : 'Mark unread'" @click="mark(row, row.unread)"><AppIcon :name="row.unread ? 'checkCircle' : 'envelope'" class="h-4 w-4" /></AppButton></template>
            </AppTable>
            <AppEmptyState v-else title="No notifications" icon="bell" />
            <div v-if="data.meta.total" class="bp-pagination"><span>{{ data.meta.total }} notifications</span><div class="bp-row-actions"><AppButton variant="outline" size="sm" title="Previous page" aria-label="Previous page" :disabled="data.meta.current_page <= 1 || saving" @click="changePage(data.meta.current_page - 1)"><AppIcon name="chevronLeft" class="h-4 w-4" /></AppButton><span>Page {{ data.meta.current_page }} of {{ data.meta.last_page }}</span><AppButton variant="outline" size="sm" title="Next page" aria-label="Next page" :disabled="data.meta.current_page >= data.meta.last_page || saving" @click="changePage(data.meta.current_page + 1)"><AppIcon name="chevronRight" class="h-4 w-4" /></AppButton></div></div>
        </template>
        </AppCard>
    </div>
</template>
