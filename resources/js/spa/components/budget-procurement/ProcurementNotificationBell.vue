<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppButton from '@/Components/AppButton.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { auth, hasAnyAccess } from '../../stores/auth';
import { procurementNotifications as state, refreshNotificationSummary, resetNotificationSummary } from '../../stores/procurementNotifications';

const route = useRoute();
const visible = computed(() => hasAnyAccess('budget_procurement', ['view-budget-cycles', 'view-procurement', 'view-supplier-payables']));
const mdaId = computed(() => route.query.mda_id || state.mdaId);
const title = computed(() => state.unread === null ? 'Notifications' : `Notifications: ${state.unread} unread`);
let timer;
function refresh() {
    if (visible.value && !document.hidden) refreshNotificationSummary(mdaId.value);
}
watch(() => [auth.user?.id, route.query.mda_id, visible.value], () => {
    resetNotificationSummary();
    refresh();
}, { immediate: true });
onMounted(() => {
    timer = setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('focus', refresh);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', refresh);
    window.removeEventListener('focus', refresh);
    resetNotificationSummary();
});
</script>

<template>
    <AppButton v-if="visible" variant="ghost" size="sm" class="bp-notification-bell" :title="title" :aria-label="title" :to="{ path: '/budget-procurement/notifications', query: mdaId ? { mda_id: mdaId } : {} }">
        <AppIcon name="bell" class="h-5 w-5" />
        <AppBadge v-if="state.unread > 0" tone="danger" class="bp-notification-count">{{ state.unread > 99 ? '99+' : state.unread }}</AppBadge>
    </AppButton>
</template>
