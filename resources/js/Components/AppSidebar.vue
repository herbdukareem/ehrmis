<script setup>
import AppIcon from '@/Components/AppIcon.vue';
import { Link, usePage } from '@inertiajs/vue3';

defineProps({
    open: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['close']);

const page = usePage();

const links = [
    { label: 'Dashboard', href: '/dashboard', icon: 'dashboard' },
    { label: 'Staff', href: '/staff', icon: 'users' },
    { label: 'Import Batches', href: '/legacy-staff-imports', icon: 'upload' },
    { label: 'Movement', href: '/movement-workbooks', icon: 'arrowsRightLeft' },
    { label: 'Budgets', href: '/budget-workbooks', icon: 'wallet' },
    { label: 'MDAs', href: '#', icon: 'building' },
    { label: 'Departments', href: '#', icon: 'department' },
];

const isActive = (href) => href !== '#' && page.url.startsWith(href);
</script>

<template>
    <!-- Mobile overlay -->
    <div
        v-if="open"
        class="fixed inset-0 z-30 bg-slate-900/50 md:hidden"
        @click="$emit('close')"
    />

    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-72 flex-col bg-ehrmis-navy-900 px-5 py-6 text-white transition-transform duration-200 md:static md:translate-x-0"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs uppercase tracking-[0.3em] text-ehrmis-navy-300">HMB-eHRMIS</div>
                <div class="mt-2 text-2xl font-semibold">Work Console</div>
            </div>
            <button
                type="button"
                class="rounded-lg p-1.5 text-ehrmis-navy-200 hover:bg-white/10 hover:text-white md:hidden"
                @click="$emit('close')"
            >
                <AppIcon name="close" class="h-6 w-6" />
            </button>
        </div>

        <div class="mt-2 text-sm text-ehrmis-navy-300">
            Human resource, import review, movement, and budget operations.
        </div>

        <nav class="mt-8 flex-1 space-y-1">
            <Link
                v-for="link in links"
                :key="link.label"
                :href="link.href"
                class="flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition"
                :class="isActive(link.href)
                    ? 'bg-ehrmis-primary-600 text-white shadow-ehrmis-card'
                    : 'text-ehrmis-navy-100 hover:bg-white/10 hover:text-white'"
            >
                <AppIcon :name="link.icon" class="h-5 w-5 shrink-0" />
                <span>{{ link.label }}</span>
            </Link>
        </nav>

        <div class="mt-6 rounded-xl bg-white/5 px-4 py-3 text-xs text-ehrmis-navy-300">
            <div class="text-xs font-medium text-ehrmis-navy-200">Signed in</div>
            <div class="mt-1 truncate text-sm text-white">{{ page.props.auth?.user?.name }}</div>
        </div>
    </aside>
</template>
