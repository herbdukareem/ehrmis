<script setup>
import { computed, onMounted, ref } from 'vue';
import AppModal from '../components/AppModal.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import { api, apiMessage } from '../lib/api';
import { pushToast } from '../stores/app';

const data = ref(null);
const activeType = ref('departments');
const selectedRecordIds = ref({});
const forms = ref({});
const search = ref('');
const modalMode = ref(null);
const busy = ref(false);

const groupConfigs = [
    {
        id: 'mda-structure',
        label: 'MDA Structure',
        blurb: 'Organizational records that define how each MDA is arranged internally.',
    },
    {
        id: 'state-reference',
        label: 'Statewide Rules',
        blurb: 'Unified statewide rules that should stay consistent across all MDAs.',
    },
    {
        id: 'mda-reference',
        label: 'MDA Reference Standards',
        blurb: 'Reference catalogs owned directly by each MDA and kept isolated per tenancy lane.',
    },
    {
        id: 'state-pay-framework',
        label: 'State Salary Framework',
        blurb: 'Salary policy records that stay unified across all MDAs.',
    },
];

const typeConfigs = [
    { id: 'mdas', singularLabel: 'MDA', label: 'MDAs', listKey: 'mdas', permissionKey: 'manage_mdas', decisionKey: 'mdas', group: 'mda-structure', blurb: 'Create and maintain ministries, departments, and agencies at platform level.' },
    { id: 'departments', singularLabel: 'Department', label: 'Departments', listKey: 'departments', permissionKey: 'manage_departments', decisionKey: 'departments', group: 'mda-structure', blurb: 'Define departments inside each visible MDA.' },
    { id: 'stations', singularLabel: 'Station', label: 'Stations', listKey: 'stations', permissionKey: 'manage_stations', decisionKey: 'stations', group: 'mda-structure', blurb: 'Maintain work locations and reporting stations for each MDA.' },
    { id: 'cadres', singularLabel: 'Cadre', label: 'Cadres', listKey: 'cadres', permissionKey: 'manage_cadres', decisionKey: 'cadres', group: 'mda-structure', blurb: 'Cadres inherit MDA ownership through the selected department.' },
    { id: 'ranks', singularLabel: 'Rank', label: 'Ranks', listKey: 'ranks', permissionKey: 'manage_ranks', decisionKey: 'ranks', group: 'mda-structure', blurb: 'Ranks inherit MDA ownership through the selected cadre chain.' },
    { id: 'allowance-types', singularLabel: 'Allowance type', label: 'Allowance Types', listKey: 'allowance_types', permissionKey: 'manage_allowance_types', decisionKey: 'allowance-types', group: 'state-pay-framework', blurb: 'Allowance definitions are statewide policy references used by staff eligibility and salary tables.' },
    { id: 'qualification-types', singularLabel: 'Qualification type', label: 'Qualification Types', listKey: 'qualification_types', permissionKey: 'manage_qualification_types', decisionKey: 'qualification-types', group: 'state-reference', blurb: 'Qualification types are unified statewide because they control terminal levels, promotion checks, and movement sheets.' },
    { id: 'promotion-policies', singularLabel: 'Promotion policy', label: 'Promotion Policies', listKey: 'promotion_policies', permissionKey: 'manage_promotion_policies', decisionKey: 'promotion-policies', group: 'state-reference', blurb: 'Promotion-year bands stay unified statewide so due dates and movement sheets do not drift by MDA.' },
    { id: 'salary-scales', singularLabel: 'Salary scale', label: 'Salary Scales', listKey: 'salary_scales', permissionKey: 'manage_salary_scales', decisionKey: 'salary-scales', group: 'state-pay-framework', blurb: 'CONHESS, CONMESS, GL, and Special Grade scales are statewide salary policy records.' },
    { id: 'salary-structure-rates', singularLabel: 'Salary structure rate', label: 'Salary Structure Rates', listKey: 'salary_structure_rates', permissionKey: 'manage_salary_structure', decisionKey: 'salary-structure-rates', group: 'state-pay-framework', blurb: 'Each level-and-step rate is shared statewide for the selected salary scale.' },
    { id: 'salary-structure-rate-allowances', singularLabel: 'Rate allowance', label: 'Rate Allowances', listKey: 'salary_structure_rate_allowances', permissionKey: 'manage_salary_structure', decisionKey: 'salary-structure-rate-allowances', group: 'state-pay-framework', blurb: 'Allowance mappings attach statewide allowance types to statewide salary rates.' },
];

const typeConfig = computed(() => typeConfigs.find((item) => item.id === activeType.value) ?? typeConfigs[0]);
const activeGroupConfig = computed(() => groupConfigs.find((item) => item.id === typeConfig.value.group) ?? groupConfigs[0]);
const canManageActiveType = computed(() => Boolean(data.value?.permissions?.[typeConfig.value.permissionKey]));
const activeDecision = computed(() => data.value?.decisions?.[typeConfig.value.decisionKey] ?? 'mda-owned');
const activeRecords = computed(() => data.value?.[typeConfig.value.listKey] ?? []);
const selectedRecord = computed(() => visibleRecords.value.find((record) => record.id === selectedRecordIds.value[activeType.value]) ?? null);
const activeForm = computed(() => ensureForm(activeType.value));
const modalTitle = computed(() => {
    if (modalMode.value === 'create') return `Create ${typeConfig.value.singularLabel}`;
    if (modalMode.value === 'edit') return `Edit ${typeConfig.value.singularLabel}`;
    if (modalMode.value === 'delete') return `Delete ${typeConfig.value.singularLabel}`;
    return typeConfig.value.singularLabel;
});

const visibleRecords = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return activeRecords.value;
    return activeRecords.value.filter((record) => searchText(activeType.value, record).includes(term));
});

const groupedTypes = computed(() => groupConfigs.map((group) => ({
    ...group,
    items: typeConfigs
        .map((config) => ({
            ...config,
            count: (data.value?.[config.listKey] ?? []).length,
            decision: data.value?.decisions?.[config.decisionKey] ?? 'mda-owned',
            manageable: Boolean(data.value?.permissions?.[config.permissionKey]),
            active: config.id === activeType.value,
        }))
        .filter((config) => config.group === group.id),
})));

const activeGroupItems = computed(() => groupedTypes.value.find((group) => group.id === typeConfig.value.group)?.items ?? []);

const mdas = computed(() => data.value?.mdas ?? []);
const promotionPolicyScales = computed(() => data.value?.promotion_policy_scales ?? []);

const blankForm = (type) => {
    const defaultMdaId = Number(mdas.value[0]?.id ?? 0) || null;
    const defaultScaleId = salaryScaleOptions()[0]?.id ?? null;
    const defaultRateId = salaryRateOptions()[0]?.id ?? null;
    const defaultAllowanceId = allowanceTypeOptions()[0]?.id ?? null;

    switch (type) {
        case 'mdas':
            return { code: '', name: '', description: '', status: 'active' };
        case 'departments':
        case 'stations':
            return { mda_id: defaultMdaId, code: '', name: '', description: '', status: 'active' };
        case 'cadres':
            return { department_id: Number(data.value?.departments?.[0]?.id ?? 0) || null, salary_scale_id: Number(data.value?.salary_scales?.[0]?.id ?? 0) || null, name: '', description: '', status: 'active' };
        case 'ranks':
            return { cadre_id: Number(data.value?.cadres?.[0]?.id ?? 0) || null, salary_scale_id: Number(data.value?.salary_scales?.[0]?.id ?? 0) || null, name: '', level: 1, description: '', status: 'active' };
        case 'allowance-types':
            return { code: '', name: '', description: '', status: 'active' };
        case 'qualification-types':
            return { code: '', name: '', description: '', status: 'active' };
        case 'promotion-policies':
            return { salary_scale_code: promotionPolicyScales.value[0]?.code ?? 'GL', min_level: 1, max_level: 1, required_years: 1, description: '', status: 'active' };
        case 'salary-scales':
            return { code: '', name: '', min_level: 1, max_level: 17, min_step: 1, max_step: 15, status: 'active' };
        case 'salary-structure-rates':
            return { salary_scale_id: defaultScaleId, level: 1, step: 1, grade_code: '', detail: '', basic_salary: '', legacy_gross_salary: '', status: 'active', effective_from: '', effective_to: '' };
        case 'salary-structure-rate-allowances':
            return { salary_structure_rate_id: defaultRateId, allowance_type_id: defaultAllowanceId, amount: '', status: 'active' };
        default:
            return {};
    }
};

const ensureForm = (type) => {
    if (!forms.value[type]) {
        forms.value = { ...forms.value, [type]: blankForm(type) };
    }
    return forms.value[type];
};

const resetForm = (type) => {
    forms.value = { ...forms.value, [type]: blankForm(type) };
};

const fillForm = (type, record) => {
    switch (type) {
        case 'mdas':
            forms.value[type] = { code: record.code, name: record.name, description: record.description ?? '', status: record.status };
            return;
        case 'departments':
        case 'stations':
            forms.value[type] = { mda_id: record.mda_id, code: record.code, name: record.name, description: record.description ?? '', status: record.status };
            return;
        case 'cadres':
            forms.value[type] = { department_id: record.department_id, salary_scale_id: record.salary_scale_id, name: record.name, description: record.description ?? '', status: record.status };
            return;
        case 'ranks':
            forms.value[type] = { cadre_id: record.cadre_id, salary_scale_id: record.salary_scale_id, name: record.name, level: record.level ?? 1, description: record.description ?? '', status: record.status };
            return;
        case 'allowance-types':
            forms.value[type] = { code: record.code, name: record.name, description: record.description ?? '', status: record.status };
            return;
        case 'qualification-types':
            forms.value[type] = { code: record.code, name: record.name, description: record.description ?? '', status: record.status };
            return;
        case 'promotion-policies':
            forms.value[type] = {
                salary_scale_code: record.salary_scale_code,
                min_level: record.min_level,
                max_level: record.max_level,
                required_years: record.required_years,
                description: record.description ?? '',
                status: record.status,
            };
            return;
        case 'salary-scales':
            forms.value[type] = { code: record.code, name: record.name, min_level: record.min_level, max_level: record.max_level, min_step: record.min_step, max_step: record.max_step, status: record.status };
            return;
        case 'salary-structure-rates':
            forms.value[type] = {
                salary_scale_id: record.salary_scale_id,
                level: record.level,
                step: record.step,
                grade_code: record.grade_code ?? '',
                detail: record.detail ?? '',
                basic_salary: record.basic_salary,
                legacy_gross_salary: record.legacy_gross_salary ?? '',
                status: record.status,
                effective_from: record.effective_from ?? '',
                effective_to: record.effective_to ?? '',
            };
            return;
        case 'salary-structure-rate-allowances':
            forms.value[type] = {
                salary_structure_rate_id: record.salary_structure_rate_id,
                allowance_type_id: record.allowance_type_id,
                amount: record.amount,
                status: record.status,
            };
            return;
        default:
            resetForm(type);
    }
};

const salaryScaleOptions = () => data.value?.salary_scales ?? [];
const salaryRateOptions = () => data.value?.salary_structure_rates ?? [];
const allowanceTypeOptions = () => data.value?.allowance_types ?? [];

const typeFields = computed(() => {
    if (!data.value) return [];

    switch (activeType.value) {
        case 'mdas':
            return [
                { key: 'code', label: 'Code', type: 'text' },
                { key: 'name', label: 'Name', type: 'text' },
                { key: 'description', label: 'Description', type: 'textarea' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        case 'departments':
        case 'stations':
            return [
                { key: 'mda_id', label: 'MDA', type: 'select', options: mdas.value, optionLabel: (item) => `${item.code} - ${item.name}`, disabled: mdas.value.length <= 1 },
                { key: 'code', label: 'Code', type: 'text' },
                { key: 'name', label: 'Name', type: 'text' },
                { key: 'description', label: 'Description', type: 'textarea' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        case 'cadres':
            return [
                { key: 'department_id', label: 'Department', type: 'select', options: data.value.departments ?? [], optionLabel: (item) => `${item.code} - ${item.name}` },
                { key: 'salary_scale_id', label: 'Salary scale', type: 'select', options: salaryScaleOptions(), optionLabel: (item) => `${item.code} - ${item.name}` },
                { key: 'name', label: 'Name', type: 'text' },
                { key: 'description', label: 'Description', type: 'textarea' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        case 'ranks':
            return [
                { key: 'cadre_id', label: 'Cadre', type: 'select', options: data.value.cadres ?? [], optionLabel: (item) => `${item.name} / ${item.department?.code ?? 'Department'} / ${item.salary_scale?.code ?? 'Scale'}` },
                { key: 'salary_scale_id', label: 'Salary scale', type: 'select', options: salaryScaleOptions(), optionLabel: (item) => `${item.code} - ${item.name}` },
                { key: 'name', label: 'Name', type: 'text' },
                { key: 'level', label: 'Level', type: 'number' },
                { key: 'description', label: 'Description', type: 'textarea' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        case 'allowance-types':
            return [
                { key: 'code', label: 'Code', type: 'text' },
                { key: 'name', label: 'Name', type: 'text' },
                { key: 'description', label: 'Description', type: 'textarea' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        case 'qualification-types':
            return [
                { key: 'code', label: 'Code', type: 'text' },
                { key: 'name', label: 'Name', type: 'text' },
                { key: 'description', label: 'Description', type: 'textarea' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        case 'promotion-policies':
            return [
                { key: 'salary_scale_code', label: 'Salary scale', type: 'select', options: promotionPolicyScales.value, optionLabel: (item) => `${item.code} - ${item.name}` },
                { key: 'min_level', label: 'Min level', type: 'number' },
                { key: 'max_level', label: 'Max level', type: 'number' },
                { key: 'required_years', label: 'Required years', type: 'number' },
                { key: 'description', label: 'Description', type: 'textarea' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        case 'salary-scales':
            return [
                { key: 'code', label: 'Code', type: 'text' },
                { key: 'name', label: 'Name', type: 'text' },
                { key: 'min_level', label: 'Min level', type: 'number' },
                { key: 'max_level', label: 'Max level', type: 'number' },
                { key: 'min_step', label: 'Min step', type: 'number' },
                { key: 'max_step', label: 'Max step', type: 'number' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        case 'salary-structure-rates':
            return [
                { key: 'salary_scale_id', label: 'Salary scale', type: 'select', options: salaryScaleOptions(), optionLabel: (item) => `${item.code} - ${item.name}` },
                { key: 'level', label: 'Level', type: 'number' },
                { key: 'step', label: 'Step', type: 'number' },
                { key: 'grade_code', label: 'Grade code', type: 'text' },
                { key: 'detail', label: 'Detail', type: 'text' },
                { key: 'basic_salary', label: 'Basic salary', type: 'number', step: '0.01' },
                { key: 'legacy_gross_salary', label: 'Legacy gross salary', type: 'number', step: '0.01' },
                { key: 'effective_from', label: 'Effective from', type: 'date' },
                { key: 'effective_to', label: 'Effective to', type: 'date' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        case 'salary-structure-rate-allowances':
            return [
                { key: 'salary_structure_rate_id', label: 'Salary structure rate', type: 'select', options: salaryRateOptions(), optionLabel: (item) => `${item.grade_code || `${item.salary_scale?.code ?? 'Scale'} L${item.level} S${item.step}`}` },
                { key: 'allowance_type_id', label: 'Allowance type', type: 'select', options: allowanceTypeOptions(), optionLabel: (item) => `${item.code} - ${item.name}` },
                { key: 'amount', label: 'Amount', type: 'number', step: '0.01' },
                { key: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'] },
            ];
        default:
            return [];
    }
});

const activateType = (type) => {
    activeType.value = type;
    search.value = '';
    ensureForm(type);
    if (!selectedRecordIds.value[type] && (data.value?.[typeConfigs.find((item) => item.id === type)?.listKey] ?? []).length > 0) {
        selectedRecordIds.value = { ...selectedRecordIds.value, [type]: data.value[typeConfigs.find((item) => item.id === type).listKey][0].id };
    }
};

const selectGroup = (groupId) => {
    if (typeConfig.value.group === groupId) return;
    const group = groupedTypes.value.find((item) => item.id === groupId);
    if (group?.items[0]) activateType(group.items[0].id);
};

const chooseRecord = (type, id) => {
    selectedRecordIds.value = { ...selectedRecordIds.value, [type]: id };
};

const openCreateModal = () => {
    resetForm(activeType.value);
    modalMode.value = 'create';
};

const openEditModal = () => {
    if (!selectedRecord.value) return;
    fillForm(activeType.value, selectedRecord.value);
    modalMode.value = 'edit';
};

const openDeleteModal = () => {
    if (selectedRecord.value) modalMode.value = 'delete';
};

const closeModal = () => {
    modalMode.value = null;
    busy.value = false;
};

const load = async () => {
    data.value = (await api.get('/setup-management')).data.data;
    typeConfigs.forEach((config) => ensureForm(config.id));

    if (!selectedRecordIds.value[activeType.value] && activeRecords.value[0]) {
        selectedRecordIds.value = { ...selectedRecordIds.value, [activeType.value]: activeRecords.value[0].id };
    }
};

const saveRecord = async () => {
    busy.value = true;
    try {
        const recordId = modalMode.value === 'edit' ? selectedRecord.value?.id : null;
        const endpoint = `/setup-management/${activeType.value}${recordId ? `/${recordId}` : ''}`;
        const response = recordId
            ? await api.put(endpoint, activeForm.value)
            : await api.post(endpoint, activeForm.value);

        await load();

        if (response.data?.data?.id) {
            selectedRecordIds.value = { ...selectedRecordIds.value, [activeType.value]: response.data.data.id };
        }

        closeModal();
        pushToast(response.data.message);
    } catch (error) {
        busy.value = false;
        pushToast(apiMessage(error), 'error', 4200);
    }
};

const deleteRecord = async () => {
    if (!selectedRecord.value) return;
    busy.value = true;

    try {
        const response = await api.delete(`/setup-management/${activeType.value}/${selectedRecord.value.id}`);
        const deletedId = selectedRecord.value.id;

        await load();
        closeModal();

        const nextRecord = activeRecords.value.find((item) => item.id !== deletedId) ?? null;
        selectedRecordIds.value = { ...selectedRecordIds.value, [activeType.value]: nextRecord?.id ?? null };
        pushToast(response.data.message);
    } catch (error) {
        busy.value = false;
        pushToast(apiMessage(error), 'error', 4200);
    }
};

const displayLabel = (type, record) => {
    switch (type) {
        case 'departments':
        case 'stations':
        case 'mdas':
        case 'allowance-types':
        case 'qualification-types':
        case 'promotion-policies':
        case 'salary-scales':
            return type === 'promotion-policies'
                ? `${record.salary_scale_code ?? record.salary_scale?.code ?? 'Scale'} L${record.min_level}-${record.max_level}`
                : `${record.code ?? ''} ${record.name}`.trim();
        case 'cadres':
            return `${record.name} / ${record.department?.code ?? 'Department'}`;
        case 'ranks':
            return `${record.name} / ${record.cadre?.name ?? 'Cadre'} / ${record.cadre?.department?.code ?? 'Department'} / ${record.salary_scale?.code ?? 'Scale'}`;
        case 'salary-structure-rates':
            return record.grade_code || `${record.salary_scale?.code ?? 'Scale'} L${record.level} S${record.step}`;
        case 'salary-structure-rate-allowances':
            return `${record.allowance_type?.code ?? 'Allowance'} / ${record.salary_structure_rate?.grade_code || `${record.salary_structure_rate?.salary_scale?.code ?? 'Scale'} L${record.salary_structure_rate?.level ?? '-'} S${record.salary_structure_rate?.step ?? '-'}`}`;
        default:
            return record.name ?? `Record ${record.id}`;
    }
};

const searchText = (type, record) => [
    displayLabel(type, record),
    record.name,
    record.code,
    record.grade_code,
    record.detail,
    record.salary_scale_code,
    record.description,
    record.department?.name,
    record.department?.code,
    record.cadre?.name,
    record.salary_scale?.name,
    record.salary_scale?.code,
    record.allowance_type?.name,
    record.allowance_type?.code,
].filter(Boolean).join(' ').toLowerCase();

const mdaLabel = (mdaId) => {
    const mda = mdas.value.find((item) => Number(item.id) === Number(mdaId));
    return mda ? `${mda.code} - ${mda.name}` : 'No MDA assigned';
};

const recordFacts = computed(() => {
    if (!selectedRecord.value) return [];

    const record = selectedRecord.value;
    return [
        record.mda_id ? { label: 'MDA', value: mdaLabel(record.mda_id) } : null,
        activeType.value === 'mdas' && record.code ? { label: 'Code', value: record.code } : null,
        activeType.value === 'mdas' && record.name ? { label: 'Name', value: record.name } : null,
        record.salary_scale_code ? { label: 'Salary scale', value: `${record.salary_scale_code} - ${record.salary_scale?.name ?? 'Promotion scale'}` } : null,
        record.department ? { label: 'Department', value: `${record.department.code} - ${record.department.name}` } : null,
        record.cadre ? { label: 'Cadre', value: record.cadre.name } : null,
        record.salary_scale && !record.salary_scale_code ? { label: 'Salary scale', value: `${record.salary_scale.code} - ${record.salary_scale.name}` } : null,
        record.grade_code ? { label: 'Grade code', value: record.grade_code } : null,
        record.detail ? { label: 'Detail', value: record.detail } : null,
        record.allowance_type ? { label: 'Allowance', value: `${record.allowance_type.code} - ${record.allowance_type.name}` } : null,
        record.level !== undefined && record.level !== null ? { label: 'Level', value: `Level ${record.level}` } : null,
        record.min_level !== undefined && record.min_level !== null ? { label: 'Band', value: `Level ${record.min_level} to ${record.max_level}` } : null,
        record.step !== undefined && record.step !== null ? { label: 'Step', value: `Step ${record.step}` } : null,
        record.required_years !== undefined && record.required_years !== null ? { label: 'Required years', value: `${record.required_years} year${Number(record.required_years) === 1 ? '' : 's'}` } : null,
        record.status ? { label: 'Status', value: record.status } : null,
    ].filter(Boolean);
});

onMounted(load);
</script>

<template>
    <PageHeading
        eyebrow="Administration"
        title="Setup management"
        description="Manage MDA-owned structure records and statewide policy catalogs in clearly separated lanes."
    />
    <LoadingBlock v-if="!data" />
    <section v-else class="civic-setup-space">
        <div class="civic-tab-row">
            <button
                v-for="group in groupedTypes"
                :key="group.id"
                type="button"
                class="civic-tab"
                :class="{ active: group.id === typeConfig.group }"
                @click="selectGroup(group.id)"
            >
                {{ group.label }}
                <small>{{ group.items.length }}</small>
            </button>
        </div>

        <div class="civic-tab-row civic-tab-row-secondary">
            <button
                v-for="item in activeGroupItems"
                :key="item.id"
                type="button"
                class="civic-tab civic-tab-secondary"
                :class="{ active: item.active }"
                @click="activateType(item.id)"
            >
                {{ item.label }}
                <strong>{{ item.count }}</strong>
            </button>
        </div>

        <div class="civic-setup-stack">
                <section class="civic-workspace civic-setup-focus">
                    <div class="civic-workspace-header">
                        <div>
                            <div class="civic-eyebrow">{{ activeGroupConfig.label }}</div>
                            <h2>{{ typeConfig.label }}</h2>
                            <p class="civic-section-note">{{ typeConfig.blurb }}</p>
                        </div>
                        <div class="civic-setup-header-actions">
                            <div class="civic-setup-badge-group">
                                <span class="civic-setup-badge">{{ activeDecision }}</span>
                                <span class="civic-setup-badge" :data-tone="canManageActiveType ? 'manage' : 'view'">
                                    {{ canManageActiveType ? 'Manage in modal' : 'Reference only' }}
                                </span>
                            </div>
                            <button v-if="canManageActiveType" class="civic-button civic-button-primary" type="button" @click="openCreateModal">
                                New {{ typeConfig.singularLabel }}
                            </button>
                        </div>
                    </div>

                    <div class="civic-setup-toolbar">
                        <label class="civic-field civic-field-search">
                            <span>Search this setup type</span>
                            <input v-model="search" type="text" :placeholder="`Search ${typeConfig.label.toLowerCase()}`">
                        </label>
                        <div class="civic-setup-toolbar-note">
                            <span>Visible records</span>
                            <strong>{{ visibleRecords.length }}</strong>
                        </div>
                    </div>

                    <div class="civic-setup-records">
                        <button
                            v-for="record in visibleRecords"
                            :key="record.id"
                            class="civic-setup-record"
                            :class="{ active: selectedRecordIds[activeType] === record.id }"
                            type="button"
                            @click="chooseRecord(activeType, record.id)"
                        >
                            <span>
                                {{ displayLabel(activeType, record) }}
                                <small>{{ record.mda_id ? mdaLabel(record.mda_id) : record.status }}</small>
                            </span>
                            <strong>{{ record.department?.code ?? record.salary_scale?.code ?? record.allowance_type?.code ?? record.code ?? record.id }}</strong>
                        </button>
                        <div v-if="visibleRecords.length === 0" class="civic-setup-empty">
                            No records match the current filter.
                        </div>
                    </div>
                </section>

                <section class="civic-workspace civic-setup-detail-card">
                    <div class="civic-workspace-header">
                        <div>
                            <div class="civic-eyebrow">Selected record</div>
                            <h2>{{ selectedRecord ? displayLabel(activeType, selectedRecord) : `Choose a ${typeConfig.singularLabel.toLowerCase()}` }}</h2>
                            <p class="civic-section-note">
                                {{ selectedRecord ? 'Review the ownership scope, linked references, and then open a modal to make changes.' : 'Pick one record from the list to inspect its scope and linked references.' }}
                            </p>
                        </div>
                        <div v-if="selectedRecord && canManageActiveType" class="civic-inline-actions">
                            <button class="civic-button civic-button-primary" type="button" @click="openEditModal">Edit</button>
                            <button class="civic-button" type="button" @click="openDeleteModal">Delete</button>
                        </div>
                    </div>

                    <div v-if="selectedRecord" class="civic-setup-detail-body">
                        <div class="civic-setup-detail-lead">
                            <span class="civic-setup-badge">{{ activeDecision }}</span>
                            <span class="civic-setup-badge" data-tone="manage">{{ selectedRecord.status }}</span>
                        </div>
                        <dl class="civic-detail-grid civic-setup-detail-grid">
                            <div v-for="fact in recordFacts" :key="`${fact.label}-${fact.value}`">
                                <dt>{{ fact.label }}</dt>
                                <dd>{{ fact.value }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div v-else class="civic-setup-empty civic-setup-empty-panel">
                        Select a record to inspect its setup scope before editing it.
                    </div>
                </section>
        </div>

        <AppModal
            :open="modalMode === 'create' || modalMode === 'edit'"
            eyebrow="Setup editor"
            :title="modalTitle"
            :description="`All changes to ${typeConfig.label.toLowerCase()} are validated against MDA tenancy rules before they are saved.`"
            size="wide"
            @close="closeModal"
        >
            <form class="civic-form-grid civic-dialog-form" @submit.prevent="saveRecord">
                <label v-for="field in typeFields" :key="field.key" class="civic-field" :class="{ 'civic-field-wide': field.type === 'textarea' }">
                    <span>{{ field.label }}</span>
                    <select v-if="field.type === 'select'" v-model="activeForm[field.key]" :disabled="busy || field.disabled">
                        <option v-for="option in field.options" :key="typeof option === 'object' ? option.id : option" :value="typeof option === 'object' ? option.id : option">
                            {{ typeof option === 'object' ? field.optionLabel(option) : option }}
                        </option>
                    </select>
                    <textarea v-else-if="field.type === 'textarea'" v-model="activeForm[field.key]" rows="3" :disabled="busy" />
                    <input v-else v-model="activeForm[field.key]" :type="field.type" :step="field.step" :disabled="busy">
                </label>
            </form>
            <template #actions>
                <button class="civic-button" type="button" :disabled="busy" @click="closeModal">Cancel</button>
                <button class="civic-button civic-button-primary" type="button" :disabled="busy" @click="saveRecord">
                    {{ busy ? 'Saving...' : modalMode === 'edit' ? 'Save changes' : `Create ${typeConfig.singularLabel}` }}
                </button>
            </template>
        </AppModal>

        <AppModal
            :open="modalMode === 'delete'"
            eyebrow="Danger zone"
            :title="modalTitle"
            :description="selectedRecord ? `This will remove ${displayLabel(activeType, selectedRecord)} from the current setup lane.` : ''"
            @close="closeModal"
        >
            <p class="civic-section-note">Delete this record only if you are sure it is no longer needed for the owning MDA.</p>
            <template #actions>
                <button class="civic-button" type="button" :disabled="busy" @click="closeModal">Cancel</button>
                <button class="civic-button civic-button-danger" type="button" :disabled="busy" @click="deleteRecord">
                    {{ busy ? 'Deleting...' : `Delete ${typeConfig.singularLabel}` }}
                </button>
            </template>
        </AppModal>
    </section>
</template>
