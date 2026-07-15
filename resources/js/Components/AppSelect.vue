<script setup>
import AppIcon from '@/Components/AppIcon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, onUpdated, ref, useId, watch } from 'vue';

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
    searchable: {
        type: Boolean,
        default: true,
    },
    emptyText: {
        type: String,
        default: 'No matches found.',
    },
    error: {
        type: String,
        default: null,
    },
    help: {
        type: String,
        default: null,
    },
    variant: {
        type: String,
        default: 'default',
    },
});

const model = defineModel({ default: '' });

const id = useId();
const root = ref(null);
const slotHost = ref(null);
const searchInput = ref(null);
const search = ref('');
const open = ref(false);
const highlighted = ref(0);
const slotItems = ref([]);
const optionRefs = ref([]);
const isCivic = computed(() => props.variant === 'civic');

const rootClass = computed(() => (isCivic.value ? 'relative civic-field' : 'relative'));
const triggerClass = computed(() => (
    isCivic.value
        ? 'flex w-full items-center justify-between gap-2 px-3 py-2 text-left'
        : 'ehrmis-select flex w-full items-center justify-between gap-2 px-3 py-2 text-left'
));
const triggerStyle = computed(() => (isCivic.value ? {
    height: '39px',
    color: 'var(--civic-ink)',
    background: '#fff',
    border: `1px solid ${props.error ? 'var(--civic-danger)' : '#c8c4b9'}`,
    borderRadius: '2px',
    outline: 'none',
    fontSize: '13px',
    fontWeight: '500',
    boxShadow: 'none',
} : null));
const panelClass = computed(() => (
    isCivic.value
        ? 'absolute z-20 mt-1 w-full overflow-hidden border bg-white shadow-ehrmis-pop'
        : 'absolute z-20 mt-1 w-full overflow-hidden rounded-ehrmis border border-ehrmis-border bg-white shadow-ehrmis-pop'
));
const panelStyle = computed(() => (isCivic.value ? {
    borderColor: '#c8c4b9',
    borderRadius: '2px',
} : null));
const searchWrapClass = computed(() => (
    isCivic.value
        ? 'sticky top-0 z-10 border-b bg-white p-2'
        : 'sticky top-0 z-10 border-b border-ehrmis-border bg-white p-2'
));
const searchWrapStyle = computed(() => (isCivic.value ? { borderColor: '#d8d3c7' } : null));
const searchClass = computed(() => (
    isCivic.value
        ? 'w-full py-1.5 pl-8 pr-2 text-sm'
        : 'w-full rounded-lg border border-ehrmis-border py-1.5 pl-8 pr-2 text-sm text-ehrmis-text focus:border-ehrmis-primary-500 focus:outline-none focus:ring-1 focus:ring-ehrmis-primary-500'
));
const searchStyle = computed(() => (isCivic.value ? {
    color: 'var(--civic-ink)',
    background: '#fff',
    border: '1px solid #c8c4b9',
    borderRadius: '2px',
    outline: 'none',
    boxShadow: 'none',
} : null));
const optionsClass = computed(() => (isCivic.value ? 'max-h-60 overflow-auto py-1 text-sm' : 'max-h-60 overflow-auto py-1 text-sm'));

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

    return items.value.filter((item) => `${item.label} ${item.value ?? ''}`.toLowerCase().includes(query));
});

const displayItems = computed(() => {
    if (!props.placeholder || search.value.trim()) {
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

const scrollHighlightedIntoView = () => {
    optionRefs.value[highlighted.value]?.scrollIntoView({ block: 'nearest' });
};

const focusSearchInput = () => {
    searchInput.value?.focus();
    searchInput.value?.setSelectionRange?.(search.value.length, search.value.length);
};

const isPrintableKey = (event) => event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey;

const syncHighlightToValue = () => {
    const selectedIndex = displayItems.value.findIndex((item) => String(item.value) === String(model.value));
    highlighted.value = selectedIndex >= 0 ? selectedIndex : 0;
    nextTick(scrollHighlightedIntoView);
};

const openPanel = () => {
    if (open.value) {
        return;
    }

    open.value = true;
    search.value = '';
    optionRefs.value = [];
    syncHighlightToValue();

    nextTick(() => {
        if (props.searchable) {
            focusSearchInput();
            return;
        }

        scrollHighlightedIntoView();
    });
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
    if (props.searchable && isPrintableKey(e)) {
        e.preventDefault();
        if (!open.value) {
            openPanel();
        }
        search.value = `${search.value}${e.key}`;
        nextTick(focusSearchInput);
        return;
    }

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

    if (e.key === 'Tab') {
        closePanel();
        return;
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlighted.value = Math.min(highlighted.value + 1, displayItems.value.length - 1);
        nextTick(scrollHighlightedIntoView);
        return;
    }

    if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlighted.value = Math.max(highlighted.value - 1, 0);
        nextTick(scrollHighlightedIntoView);
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

const onPanelKeydown = (e) => {
    if (!props.searchable || !open.value) {
        return;
    }

    if (e.target === searchInput.value) {
        return;
    }

    if (isPrintableKey(e)) {
        e.preventDefault();
        search.value = `${search.value}${e.key}`;
        nextTick(focusSearchInput);
        return;
    }

    if (e.key === 'Backspace' && search.value) {
        e.preventDefault();
        search.value = search.value.slice(0, -1);
        nextTick(focusSearchInput);
    }
};

const onClickOutside = (e) => {
    if (open.value && !root.value?.contains(e.target)) {
        open.value = false;
    }
};

watch(displayItems, () => {
    if (!open.value) {
        return;
    }

    highlighted.value = Math.max(0, Math.min(highlighted.value, displayItems.value.length - 1));
    nextTick(scrollHighlightedIntoView);
});

onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="root" :class="rootClass">
        <template v-if="label">
            <span v-if="isCivic">{{ label }}</span>
            <InputLabel v-else :for="id" :value="label" class="ehrmis-label mb-1.5" />
        </template>

        <select ref="slotHost" class="hidden" tabindex="-1" aria-hidden="true">
            <slot />
        </select>

        <button
            :id="id"
            data-combobox-trigger
            type="button"
            :class="triggerClass"
            :style="triggerStyle"
            @click="togglePanel"
            @keydown="onTriggerKeydown"
        >
            <span
                class="truncate"
                :style="isCivic ? { color: selectedLabel ? 'var(--civic-ink)' : 'var(--civic-muted)' } : null"
                :class="!isCivic ? (selectedLabel ? 'text-ehrmis-text' : 'text-ehrmis-muted') : ''"
            >
                {{ selectedLabel || placeholder }}
            </span>
            <AppIcon
                name="chevronDown"
                class="h-4 w-4 shrink-0"
                :class="isCivic ? '' : 'text-ehrmis-muted'"
                :style="isCivic ? { color: 'var(--civic-muted)' } : null"
            />
        </button>

        <div
            v-if="open"
            :class="panelClass"
            :style="panelStyle"
            @keydown="onPanelKeydown"
        >
            <div v-if="searchable" :class="searchWrapClass" :style="searchWrapStyle">
                <div class="relative">
                    <AppIcon name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ehrmis-muted" />
                    <input
                        ref="searchInput"
                        v-model="search"
                        data-combobox-search
                        type="text"
                        :placeholder="searchPlaceholder"
                        :class="searchClass"
                        :style="searchStyle"
                        @keydown="onSearchKeydown"
                    >
                </div>
            </div>

            <ul :class="optionsClass">
                <li
                    v-for="(item, index) in displayItems"
                    :key="`${item.value}-${index}`"
                >
                    <button
                        type="button"
                        :ref="(el) => { optionRefs[index] = el; }"
                        class="flex w-full items-center px-3 py-1.5 text-left"
                        :class="[
                            !isCivic && (index === highlighted ? 'bg-ehrmis-primary-50 text-ehrmis-primary-800' : 'text-ehrmis-text hover:bg-slate-50'),
                            !isCivic && item.isPlaceholder ? 'text-ehrmis-muted' : '',
                            String(item.value) === String(model.value) && model.value !== '' ? 'font-semibold' : '',
                        ]"
                        :style="isCivic ? {
                            color: item.isPlaceholder ? 'var(--civic-muted)' : 'var(--civic-ink)',
                            background: index === highlighted ? '#f6f4ee' : '#fff',
                        } : null"
                        @mouseenter="highlighted = index"
                        @click="selectItem(item)"
                    >
                        {{ item.label }}
                    </button>
                </li>

                <li v-if="!displayItems.length" class="px-3 py-2" :style="isCivic ? { color: 'var(--civic-muted)' } : null" :class="!isCivic ? 'text-ehrmis-muted' : ''">
                    {{ emptyText }}
                </li>
            </ul>
        </div>

        <p v-if="help && !error" :class="isCivic ? '' : 'ehrmis-help-text'" :style="isCivic ? { color: 'var(--civic-muted)', fontSize: '10px', fontWeight: '600' } : null">{{ help }}</p>
        <p v-if="error && isCivic" class="civic-field-error">{{ error }}</p>
        <InputError v-else-if="error" :message="error" />
    </div>
</template>
