<script setup>
import AppBadge from '@/Components/AppBadge.vue';
import AppDropdown from '@/Components/AppDropdown.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
import { Link, usePage } from '@inertiajs/vue3';

defineEmits(['toggle-sidebar']);

const page = usePage();
</script>

<template>
    <header class="sticky top-0 z-20 border-b border-ehrmis-border bg-white/95 backdrop-blur px-4 py-3 md:px-6">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="ehrmis-icon-btn md:hidden"
                    @click="$emit('toggle-sidebar')"
                >
                    <AppIcon name="menu" class="h-5 w-5" />
                </button>

                <div>
                    <div class="text-xs font-medium text-ehrmis-muted">HMB-eHRMIS</div>
                    <div class="text-lg font-semibold text-ehrmis-text">Operations Workspace</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <AppBadge class="hidden sm:inline-flex">
                    {{ page.props.auth?.context?.assigned_mda?.name ?? 'Global access' }}
                </AppBadge>
                <AppStatusBadge :status="page.props.auth?.context?.has_global_access ? 'global' : 'mda'" />

                <button type="button" class="ehrmis-icon-btn">
                    <AppIcon name="bell" class="h-5 w-5" />
                </button>

                <AppDropdown align="right" width="56">
                    <template #trigger>
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-lg border border-ehrmis-border bg-white px-3 py-2 text-sm font-medium text-ehrmis-text transition hover:bg-slate-50"
                        >
                            <AppIcon name="userCircle" class="h-5 w-5 text-ehrmis-muted" />
                            <span class="hidden sm:inline">{{ page.props.auth?.user?.name }}</span>
                            <AppIcon name="chevronDown" class="h-4 w-4 text-ehrmis-muted" />
                        </button>
                    </template>

                    <template #content>
                        <Link
                            :href="route('profile.edit')"
                            class="flex items-center gap-2 px-4 py-2 text-sm text-ehrmis-text transition hover:bg-slate-50"
                        >
                            <AppIcon name="userCircle" class="h-4 w-4 text-ehrmis-muted" />
                            Profile
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-600 transition hover:bg-red-50"
                        >
                            <AppIcon name="logout" class="h-4 w-4" />
                            Log Out
                        </Link>
                    </template>
                </AppDropdown>
            </div>
        </div>
    </header>
</template>
