<script setup>
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppLayout from '@/Components/AppLayout.vue';
import AppPageHeader from '@/Components/AppPageHeader.vue';
import AppStatCard from '@/Components/AppStatCard.vue';
import AppStatusBadge from '@/Components/AppStatusBadge.vue';
import { Head, usePage } from '@inertiajs/vue3';

const page = usePage();

const summaryCards = [
    {
        label: 'Staff Registry',
        value: 'Operational',
        description: 'Imported and manually managed staff records are available.',
        icon: 'users',
        tone: 'primary',
    },
    {
        label: 'Legacy Imports',
        value: 'Review Ready',
        description: 'Batch review, warnings, mapping fixes, and publication UI are in place.',
        icon: 'upload',
        tone: 'navy',
    },
    {
        label: 'Movement Workbooks',
        value: 'Foundation',
        description: 'Generation and workflow foundation are implemented.',
        icon: 'arrowsRightLeft',
        tone: 'accent',
    },
    {
        label: 'Budget Workbooks',
        value: 'Foundation',
        description: 'Initial budget generation flows are available.',
        icon: 'wallet',
        tone: 'neutral',
    },
];

const moduleLinks = [
    {
        title: 'Staff',
        description: 'Browse, create, review, and update canonical staff records.',
        href: '/staff',
        state: 'live',
    },
    {
        title: 'Staff Imports',
        description: 'Review legacy import batches, inspect warnings, and publish rows safely.',
        href: '/legacy-staff-imports',
        state: 'live',
    },
    {
        title: 'MDAs',
        description: 'MDA management UI is the next admin surface to expose.',
        href: null,
        state: 'planned',
    },
    {
        title: 'Departments',
        description: 'Department administration will be surfaced in the UI next.',
        href: null,
        state: 'planned',
    },
    {
        title: 'Stations',
        description: 'Canonical station management is ready in data model, UI still pending.',
        href: null,
        state: 'planned',
    },
    {
        title: 'Salary Structure',
        description: 'Dynamic salary and allowance foundation is complete; admin pages are still pending.',
        href: null,
        state: 'planned',
    },
    {
        title: 'Reports',
        description: 'Operational reporting will come after workflow approvals and HR modules.',
        href: null,
        state: 'planned',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <AppPageHeader
            title="eHRMIS Dashboard"
            subtitle="Operational entry point for staff management, import review, movement, and budget workflows."
        >
            <template #actions>
                <AppButton :href="route('staff.index')">Open Staff Module</AppButton>
            </template>
        </AppPageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <AppCard>
                <div class="text-sm text-ehrmis-muted">Signed in as</div>
                <div class="mt-2 text-xl font-semibold text-ehrmis-text">
                    {{ page.props.auth.user.name }}
                </div>
                <div class="mt-2">
                    <AppStatusBadge :status="page.props.auth.context?.has_global_access ? 'global' : 'mda'" />
                </div>
            </AppCard>

            <AppCard>
                <div class="text-sm text-ehrmis-muted">Assigned MDA</div>
                <div class="mt-2 text-xl font-semibold text-ehrmis-text">
                    {{ page.props.auth.context?.assigned_mda?.name ?? 'Global Administration' }}
                </div>
                <div class="mt-1 text-sm text-ehrmis-muted">
                    {{ page.props.auth.user.email }}
                </div>
            </AppCard>

            <AppCard>
                <div class="text-sm text-ehrmis-muted">Authorization</div>
                <div class="mt-2 text-xl font-semibold text-ehrmis-text">
                    {{ page.props.auth.context?.roles?.[0] ?? 'No role assigned' }}
                </div>
                <div class="mt-1 text-sm text-ehrmis-muted">
                    {{ page.props.auth.context?.permissions?.length ?? 0 }} permissions available
                </div>
            </AppCard>
        </div>

        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
            <AppStatCard
                v-for="card in summaryCards"
                :key="card.label"
                :label="card.label"
                :value="card.value"
                :description="card.description"
                :icon="card.icon"
                :tone="card.tone"
            />
        </div>

        <div>
            <div class="mb-4 text-sm font-semibold text-ehrmis-text">Modules</div>
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                <AppCard
                    v-for="moduleLink in moduleLinks"
                    :key="moduleLink.title"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-lg font-semibold text-ehrmis-text">{{ moduleLink.title }}</div>
                            <div class="mt-2 text-sm text-ehrmis-muted">{{ moduleLink.description }}</div>
                        </div>
                        <AppStatusBadge :status="moduleLink.state" />
                    </div>

                    <div class="mt-5">
                        <AppButton
                            v-if="moduleLink.href"
                            :href="moduleLink.href"
                            size="sm"
                        >
                            Open Module
                        </AppButton>
                        <span
                            v-else
                            class="ehrmis-btn-outline cursor-default px-4 py-2 text-sm"
                        >
                            UI Coming Soon
                        </span>
                    </div>
                </AppCard>
            </div>
        </div>
    </AppLayout>
</template>
