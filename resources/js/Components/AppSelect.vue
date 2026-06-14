<script setup>
import AppIcon from '@/Components/AppIcon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, onUpdated, ref, useId } from 'vue';

const props = defineProps({
    label: {
        type: String,
        default: null,
    },
    options: {
        type: Array,
        default: () => [],
    },
    optionLabel: {
        type: String,
        default: 'label',
    },
    optionValue: {
        type: String,
        default: 'value',
    },
    placeholder: {
        type: String,
        default: null,
    },
    searchPlaceholder: {
        type: String,
        default: 'Search...',
    },
    error: {
        type: String,
        default: null,
    },
    help: {
        type: String,
        default: null,
    },
});

const model = defineModel({ default: '' });

const id = useId();
const root = ref(null);
const slotHost = ref(null);
const search = ref('');
const open = ref(false);
const highlighted = ref(0);
const slotItems = ref([]);

const refreshSlotItems = () => {
    if (!slotHost.value) {
        return;
    }

    slotItems.value = Array.from(slotHost.value.querySelectorAll('option')).map((el) => ({
        value: '_value' in el ? el._value : el.value,
        label: el.textContent.trim(),
    }));
};

onMounted(() => nextTick(refreshSlotItems));
onUpdated(() => nextTick(refreshSlotItems));

const items = computed(() => {
    if (props.options.length) {
        return props.options.map((option) => ({
            value: option[props.optionValue],
            label: String(option[props.optionLabel]),
        }));
    }

    return slotItems.value;
});

const filteredItems = computed(() => {
    if (!search.value.trim()) {
        return items.value;
    }

    const query = search.value.trim().toLowerCase();

    return items.value.filter((item) => item.label.toLowerCase().includes(query));
});

const displayItems = computed(() => {
    if (!props.placeholder) {
        return filteredItems.value;
    }

    return [{ value: '', label: props.placeholder, isPlaceholder: true }, ...filteredItems.value];
});

const selectedLabel = computed(() => {
    const match = items.value.find((item) => String(item.value) === String(model.value) && model.value !== '');

    return match ? match.label : '';
});

const selectItem = (item) => {
    model.value = item.value;
    closePanel();
};

const openPanel = () => {
    if (open.value) {
        return;
    }

    open.value = true;
    search.value = '';
    highlighted.value = 0;

    nextTick(() => root.value?.querySelector('[data-combobox-search]')?.focus());
};

const closePanel = () => {
    open.value = false;
    root.value?.querySelector('[data-combobox-trigger]')?.focus();
};

const togglePanel = () => {
    if (open.value) {
        closePanel();
    } else {
        openPanel();
    }
};

const onTriggerKeydown = (e) => {
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        openPanel();
    }
};

const onSearchKeydown = (e) => {
    if (e.key === 'Escape') {
        closePanel();
        return;
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlighted.value = Math.min(highlighted.value + 1, displayItems.value.length - 1);
        return;
    }

    if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlighted.value = Math.max(highlighted.value - 1, 0);
        return;
    }

    if (e.key === 'Enter') {
        e.preventDefault();
        const item = displayItems.value[highlighted.value];

        if (item) {
            selectItem(item);
        }
    }
};

const onClickOutside = (e) => {
    if (open.value && !root.value?.contains(e.target)) {
        open.value = false;
    }
};

onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="root" class="relative">
        <InputLabel v-if="label" :for="id" :value="label" class="ehrmis-label mb-1.5" />

        <select ref="slotHost" class="hidden" tabindex="-1" aria-hidden="true">
            <slot />
        </select>

        <button
            :id="id"
            data-combobox-trigger
            type="button"
            class="ehrmis-select flex w-full items-center justify-between gap-2 px-3 py-2 text-left"
            :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': error }"
            @click="togglePanel"
            @keydown="onTriggerKeydown"
        >
            <span class="truncate" :class="selectedLabel ? 'text-ehrmis-text' : 'text-ehrmis-muted'">
                {{ selectedLabel || placeholder }}
            </span>
            <AppIcon name="chevronDown" class="h-4 w-4 shrink-0 text-ehrmis-muted" />
        </button>

        <div
            v-if="open"
            class="absolute z-20 mt-1 w-full overflow-hidden rounded-ehrmis border border-ehrmis-border bg-white shadow-ehrmis-pop"
        >
            <div class="border-b border-ehrmis-border p-2">
                <div class="relative">
                    <AppIcon name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ehrmis-muted" />
                    <input
                        v-model="search"
                        data-combobox-search
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="w-full rounded-lg border border-ehrmis-border py-1.5 pl-8 pr-2 text-sm text-ehrmis-text focus:border-ehrmis-primary-500 focus:outline-none focus:ring-1 focus:ring-ehrmis-primary-500"
                        @keydown="onSearchKeydown"
                    >
                </div>
            </div>

            <ul class="max-h-60 overflow-auto py-1 text-sm">
                <li
                    v-for="(item, index) in displayItems"
                    :key="`${item.value}-${index}`"
                >
                    <button
                        type="button"
                        class="flex w-full items-center px-3 py-1.5 text-left"
                        :class="[
                            index === highlighted ? 'bg-ehrmis-primary-50 text-ehrmis-primary-800' : 'text-ehrmis-text hover:bg-slate-50',
                            item.isPlaceholder ? 'text-ehrmis-muted' : '',
                            String(item.value) === String(model.value) && model.value !== '' ? 'font-semibold' : '',
                        ]"
                        @mouseenter="highlighted = index"
                        @click="selectItem(item)"
                    >
                        {{ item.label }}
                    </button>
                </li>

                <li v-if="!displayItems.length" class="px-3 py-2 text-ehrmis-muted">
                    No matches found.
                </li>
            </ul>
        </div>

        <p v-if="help && !error" class="ehrmis-help-text">{{ help }}</p>
        <InputError :message="error" />
    </div>
</template>
