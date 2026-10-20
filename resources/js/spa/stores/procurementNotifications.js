import { reactive } from 'vue';
import { api } from '../lib/api';

export const procurementNotifications = reactive({ mdaId: null, unread: null });
let sequence = 0;

export function setNotificationSummary(data) {
    sequence++;
    procurementNotifications.mdaId = data.mda_id;
    procurementNotifications.unread = Number(data.unread_count || 0);
}

export function resetNotificationSummary() {
    sequence++;
    procurementNotifications.mdaId = null;
    procurementNotifications.unread = null;
}

export async function refreshNotificationSummary(mdaId) {
    const token = ++sequence;
    if (String(mdaId || '') !== String(procurementNotifications.mdaId || '')) procurementNotifications.unread = null;
    try {
        const response = await api.get('/budget-procurement/notifications/summary', { params: mdaId ? { mda_id: mdaId } : {}, background: true });
        if (token !== sequence) return;
        procurementNotifications.mdaId = response.data.data.mda_id;
        procurementNotifications.unread = Number(response.data.data.unread_count || 0);
    } catch {
        if (token === sequence) procurementNotifications.unread = null;
    }
}
