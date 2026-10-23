<script setup>
import { computed } from 'vue';
import AppCard from './AppCard.vue';
import AppButton from './AppButton.vue';
import AppTabs from './AppTabs.vue';
import AppTextInput from './AppTextInput.vue';
defineProps({ days: { type: Array, default: () => [] }, busy: Boolean, timezone: { type: String, default: 'Africa/Lagos' } });
const date = defineModel({ default: '' });
const view = defineModel('view', { default: 'week' });
const emit = defineEmits(['open', 'refresh']);
function move(direction) { const anchor = new Date(`${date.value}T12:00:00Z`); view.value === 'month' ? anchor.setUTCMonth(anchor.getUTCMonth() + direction) : anchor.setUTCDate(anchor.getUTCDate() + direction * (view.value === 'week' ? 7 : 1)); date.value = anchor.toISOString().slice(0, 10); }
const heading = value => new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${value}T12:00:00Z`));
</script>
<template>
    <AppCard title="Executive diary" :subtitle="`Times are shown in ${timezone}. Restricted engagements appear as unavailable.`">
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <AppButton variant="outline" :disabled="busy" @click="move(-1)">Earlier</AppButton>
            <AppTextInput v-model="date" type="date" label="Calendar date" :disabled="busy" />
            <AppButton variant="outline" :disabled="busy" @click="move(1)">Later</AppButton>
            <AppTabs v-model="view" :tabs="['day', 'week', 'month'].map(key => ({ key, label: key[0].toUpperCase() + key.slice(1) }))" />
        </div>
        <div class="overflow-x-auto" :aria-busy="busy">
            <div class="grid gap-2" :class="view === 'day' ? 'grid-cols-1' : 'min-w-[700px] grid-cols-7'">
                <div v-for="day in days" :key="day.date" class="min-h-28 min-w-0 rounded border border-ehrmis-border p-2">
                    <p class="mb-2 text-sm font-semibold">{{ heading(day.date) }}</p>
                    <p v-if="!day.events.length" class="text-xs text-ehrmis-muted">No engagements</p>
                    <div v-for="(event, index) in day.events" :key="event.id ?? index" class="mb-2">
                        <AppButton variant="ghost" size="sm" :disabled="busy || !event.id" @click="emit('open', event.id)"><span class="break-words text-left">{{ event.time }} · {{ event.subject }}</span></AppButton>
                    </div>
                </div>
            </div>
        </div>
    </AppCard>
</template>
