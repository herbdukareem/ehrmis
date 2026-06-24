<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AppModal from '../components/AppModal.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import { api, apiMessage } from '../lib/api';
import { pushToast } from '../stores/app';

const data = ref(null);
const activePanel = ref('users');
const selectedUserId = ref(null);
const selectedRoleId = ref(null);
const userSearch = ref('');
const roleSearch = ref('');
const permissionSearch = ref('');
const modalState = ref({ entity: null, mode: null });
const busy = ref(false);

const userForm = ref({});
const roleForm = ref({ name: '', scope: 'global', mda_id: null, permissions: [] });
const newRoleForm = ref({ name: '', scope: 'global', mda_id: null, permissions: [] });
const newUserForm = ref({});

const selectedUser = computed(() => data.value?.users.find((user) => user.id === selectedUserId.value) ?? null);
const manageableRoles = computed(() => data.value?.roles.filter((role) => role.can_manage_definition) ?? []);
const selectedRole = computed(() => manageableRoles.value.find((role) => role.id === selectedRoleId.value) ?? null);
const canManageAccessScopes = computed(() => Boolean(data.value?.can_manage_access_scopes));
const canCreateRole = computed(() => (data.value?.role_scope_options?.length ?? 0) > 0);
const canCreateUsers = computed(() => Boolean(data.value?.can_create_users));
const canManageUserStatus = computed(() => Boolean(data.value?.can_manage_user_status));
const scopeTypes = computed(() => data.value?.scope_types ?? ['platform', 'state', 'mda']);
const userStatuses = computed(() => data.value?.user_statuses ?? ['active', 'inactive']);
const permissions = computed(() => data.value?.permissions ?? []);
const mdas = computed(() => data.value?.mdas ?? []);
const mdaPermissionNames = computed(() => data.value?.mda_role_permissions ?? []);
const visibleUsers = computed(() => {
    const term = userSearch.value.trim().toLowerCase();
    const users = data.value?.users ?? [];
    if (!term) return users;

    return users.filter((user) => [
        user.name,
        user.email,
        user.mda?.code,
        user.mda?.name,
        ...(user.roles ?? []).map((role) => role.name),
    ].filter(Boolean).join(' ').toLowerCase().includes(term));
});
const visibleRoles = computed(() => {
    const term = roleSearch.value.trim().toLowerCase();
    if (!term) return manageableRoles.value;

    return manageableRoles.value.filter((role) => [
        role.name,
        role.scope,
        role.mda?.code,
        ...(role.permissions ?? []).map((permission) => permission.name),
    ].filter(Boolean).join(' ').toLowerCase().includes(term));
});
const activeUserForm = computed(() => modalState.value.mode === 'create' ? newUserForm.value : userForm.value);
const activeRoleForm = computed(() => modalState.value.mode === 'create' ? newRoleForm.value : roleForm.value);

const defaultRoleScope = () => {
    const scopeOptions = data.value?.role_scope_options ?? [];
    return scopeOptions.length === 1 ? scopeOptions[0] : 'global';
};

const defaultRoleMdaId = () => defaultRoleScope() === 'mda'
    ? Number(data.value?.mdas?.[0]?.id ?? 0) || null
    : null;

const defaultUserForm = () => ({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    status: userStatuses.value[0] ?? 'active',
    role_ids: [],
    scope_type: 'mda',
    state_code: 'NG-NI',
    mda_id: Number(data.value?.mdas?.[0]?.id ?? 0) || null,
    mda_ids: [],
});

const resetNewRoleForm = () => {
    newRoleForm.value = {
        name: '',
        scope: defaultRoleScope(),
        mda_id: defaultRoleMdaId(),
        permissions: [],
    };
};

const resetNewUserForm = () => {
    newUserForm.value = defaultUserForm();
};

const uniqueNumericIds = (values) => [...new Set((values ?? []).map((value) => Number(value)).filter(Boolean))];

const buildUserForm = (user) => {
    const mdaScopes = (user.access_scopes ?? [])
        .filter((scope) => scope.scope_type === 'mda' && scope.mda_id)
        .map((scope) => Number(scope.mda_id));
    const primaryMdaId = Number(user.mda_id ?? mdaScopes[0] ?? 0) || null;
    const nonMdaScope = (user.access_scopes ?? []).find((scope) => scope.scope_type !== 'mda');

    return {
        name: user.name,
        email: user.email,
        password: '',
        password_confirmation: '',
        status: user.status ?? 'active',
        role_ids: user.roles.map((role) => role.id),
        scope_type: nonMdaScope?.scope_type ?? 'mda',
        state_code: nonMdaScope?.state_code ?? 'NG-NI',
        mda_id: primaryMdaId,
        mda_ids: mdaScopes.filter((mdaId) => mdaId !== primaryMdaId),
    };
};

const buildRoleForm = (role) => ({
    name: role.name,
    scope: role.scope,
    mda_id: role.mda_id,
    permissions: role.permissions.map((permission) => permission.name),
});

const assignableRolesFor = (form) => {
    const roles = data.value?.roles ?? [];
    const primaryMdaId = Number(form.mda_id ?? 0) || null;
    const isMdaScope = !canManageAccessScopes.value || form.scope_type === 'mda';

    return roles.filter((role) => {
        if (role.scope === 'global') return true;
        return isMdaScope && primaryMdaId && Number(role.mda_id) === primaryMdaId;
    });
};

const assignableRoles = computed(() => assignableRolesFor(activeUserForm.value));

const syncUserRoleSelection = (form) => {
    const allowedRoleIds = new Set(assignableRolesFor(form).map((role) => Number(role.id)));
    form.role_ids = uniqueNumericIds(form.role_ids).filter((roleId) => allowedRoleIds.has(roleId));
};

const syncUserScopeDefaults = (form) => {
    if (!form) return;

    if (form.scope_type === 'mda') {
        if (!Number(form.mda_id ?? 0)) {
            form.mda_id = Number(mdas.value[0]?.id ?? 0) || null;
        }

        form.mda_ids = uniqueNumericIds(form.mda_ids).filter((mdaId) => mdaId !== Number(form.mda_id));
    } else {
        form.mda_id = null;
        form.mda_ids = [];
    }

    syncUserRoleSelection(form);
};

const permissionGroups = computed(() => {
    const allPermissions = permissions.value;
    const term = permissionSearch.value.trim().toLowerCase();
    const filterItems = (items) => !term
        ? items
        : items.filter((permission) => [
            permission.name,
            permission.guard_name,
        ].filter(Boolean).join(' ').toLowerCase().includes(term));

    return [
        {
            id: 'safe',
            label: 'Assignable permissions',
            blurb: 'Permissions available for the current role scope.',
            items: filterItems(allPermissions.filter((permission) => activeRoleForm.value.scope === 'global' || mdaPermissionNames.value.includes(permission.name))),
        },
        {
            id: 'restricted',
            label: 'Restricted outside this scope',
            blurb: 'Visible here so admins understand what stays reserved for higher scope.',
            items: activeRoleForm.value.scope === 'global'
                ? []
                : filterItems(allPermissions.filter((permission) => !mdaPermissionNames.value.includes(permission.name))),
        },
    ];
});

const syncRoleScopeDefaults = (form) => {
    if (!form) return;

    if (form.scope === 'mda') {
        if (!Number(form.mda_id ?? 0)) {
            form.mda_id = Number(mdas.value[0]?.id ?? 0) || null;
        }

        form.permissions = (form.permissions ?? []).filter((permissionName) => mdaPermissionNames.value.includes(permissionName));
        return;
    }

    form.mda_id = null;
    form.permissions = [...new Set(form.permissions ?? [])];
};

const chooseUser = (id) => {
    selectedUserId.value = id;
    const user = data.value?.users.find((candidate) => candidate.id === id);
    userForm.value = user ? buildUserForm(user) : defaultUserForm();
    syncUserScopeDefaults(userForm.value);
};

const chooseRole = (id) => {
    selectedRoleId.value = id;
    const role = manageableRoles.value.find((candidate) => candidate.id === id);
    roleForm.value = role ? buildRoleForm(role) : { name: '', scope: defaultRoleScope(), mda_id: defaultRoleMdaId(), permissions: [] };
    syncRoleScopeDefaults(roleForm.value);
};

const openModal = (entity, mode) => {
    if (entity === 'user' && mode === 'create') resetNewUserForm();
    if (entity === 'role' && mode === 'create') resetNewRoleForm();
    if (entity === 'user' && mode === 'edit' && selectedUser.value) userForm.value = buildUserForm(selectedUser.value);
    if (entity === 'role' && mode === 'edit' && selectedRole.value) roleForm.value = buildRoleForm(selectedRole.value);
    if (entity === 'user') syncUserScopeDefaults(activeUserForm.value);
    if (entity === 'role') syncRoleScopeDefaults(activeRoleForm.value);
    permissionSearch.value = '';
    modalState.value = { entity, mode };
};

const closeModal = () => {
    modalState.value = { entity: null, mode: null };
    busy.value = false;
    permissionSearch.value = '';
};

const buildUserPayload = (form) => {
    const payload = {
        name: form.name,
        email: form.email,
        status: form.status,
        role_ids: form.role_ids,
    };

    if (form.password) {
        payload.password = form.password;
        payload.password_confirmation = form.password_confirmation;
    }

    if (canManageAccessScopes.value) {
        payload.scope_type = form.scope_type;
        payload.state_code = form.scope_type === 'state' ? form.state_code : null;
        payload.mda_id = form.scope_type === 'mda' ? form.mda_id : null;
        payload.mda_ids = form.scope_type === 'mda' ? form.mda_ids : [];
    }

    return payload;
};

const submitUser = async () => {
    busy.value = true;
    const isCreate = modalState.value.mode === 'create';

    try {
        const form = isCreate ? newUserForm.value : userForm.value;
        const response = isCreate
            ? await api.post('/access-management/users', buildUserPayload(form))
            : await api.put(`/access-management/users/${selectedUserId.value}`, buildUserPayload(form));

        await load();
        if (response.data?.data?.id) chooseUser(response.data.data.id);
        closeModal();
        pushToast(response.data.message);
    } catch (error) {
        busy.value = false;
        pushToast(apiMessage(error), 'error', 4200);
    }
};

const submitRole = async () => {
    busy.value = true;
    const isCreate = modalState.value.mode === 'create';

    try {
        const form = isCreate ? newRoleForm.value : roleForm.value;
        const response = isCreate
            ? await api.post('/access-management/roles', form)
            : await api.put(`/access-management/roles/${selectedRoleId.value}`, form);

        await load();
        if (response.data?.data?.id) chooseRole(response.data.data.id);
        closeModal();
        pushToast(response.data.message);
    } catch (error) {
        busy.value = false;
        pushToast(apiMessage(error), 'error', 4200);
    }
};

const deleteRole = async () => {
    if (!selectedRole.value) return;
    busy.value = true;

    try {
        const response = await api.delete(`/access-management/roles/${selectedRoleId.value}`);
        await load();
        selectedRoleId.value = visibleRoles.value[0]?.id ?? null;
        if (selectedRoleId.value) chooseRole(selectedRoleId.value);
        closeModal();
        pushToast(response.data.message);
    } catch (error) {
        busy.value = false;
        pushToast(apiMessage(error), 'error', 4200);
    }
};

watch(
    () => activeRoleForm.value.scope,
    () => {
        if (modalState.value.entity === 'role') {
            syncRoleScopeDefaults(activeRoleForm.value);
        }
    }
);

watch(
    () => [activeUserForm.value.scope_type, activeUserForm.value.mda_id],
    () => {
        if (modalState.value.entity === 'user') {
            syncUserScopeDefaults(activeUserForm.value);
        }
    }
);

const load = async () => {
    const previousUserId = selectedUserId.value;
    const previousRoleId = selectedRoleId.value;
    data.value = (await api.get('/access-management')).data.data;
    resetNewRoleForm();
    resetNewUserForm();

    if (previousUserId && data.value.users.some((user) => user.id === previousUserId)) {
        chooseUser(previousUserId);
    } else if (data.value.users[0]) {
        chooseUser(data.value.users[0].id);
    } else {
        selectedUserId.value = null;
        userForm.value = defaultUserForm();
    }

    if (previousRoleId && manageableRoles.value.some((role) => role.id === previousRoleId)) {
        chooseRole(previousRoleId);
    } else if (manageableRoles.value[0]) {
        chooseRole(manageableRoles.value[0].id);
    } else {
        selectedRoleId.value = null;
    }
};

const scopeLabel = (scope) => {
    if (scope === 'platform') return 'Platform-wide';
    if (scope === 'state') return 'State-wide';
    return 'MDA-scoped';
};

const userFacts = computed(() => {
    if (!selectedUser.value) return [];
    const user = selectedUser.value;
    const mdaScopes = (user.access_scopes ?? []).filter((scope) => scope.scope_type === 'mda' && scope.mda);

    return [
        { label: 'Primary MDA', value: user.mda ? `${user.mda.code} - ${user.mda.name}` : 'Platform / state user' },
        { label: 'Status', value: user.status ?? 'active' },
        { label: 'Roles', value: user.roles.map((role) => role.name).join(', ') || 'No roles assigned' },
        { label: 'Access scope', value: scopeLabel((user.access_scopes ?? []).find((scope) => scope.scope_type !== 'mda')?.scope_type ?? 'mda') },
        ...(mdaScopes.length ? [{ label: 'Additional MDAs', value: mdaScopes.map((scope) => scope.mda.code).join(', ') }] : []),
    ];
});

const roleFacts = computed(() => {
    if (!selectedRole.value) return [];
    return [
        { label: 'Scope', value: selectedRole.value.scope === 'global' ? 'Global role' : 'MDA role' },
        { label: 'MDA', value: selectedRole.value.mda ? `${selectedRole.value.mda.code} - ${selectedRole.value.mda.name}` : 'Platform-wide' },
        { label: 'Permissions', value: `${selectedRole.value.permissions.length} assigned` },
    ];
});

onMounted(load);
</script>

<template>
    <PageHeading
        eyebrow="Security administration"
        title="Roles and access scopes"
        description="Create and manage users safely within MDA boundaries while privileged platform actors retain wider scope controls."
    />
    <LoadingBlock v-if="!data" />
    <section v-else class="civic-access-space">
        <div class="civic-tab-row">
            <button type="button" class="civic-tab" :class="{ active: activePanel === 'users' }" @click="activePanel = 'users'">
                Users
                <small>{{ visibleUsers.length }}</small>
            </button>
            <button type="button" class="civic-tab" :class="{ active: activePanel === 'roles' }" @click="activePanel = 'roles'">
                Roles
                <small>{{ visibleRoles.length }}</small>
            </button>
        </div>

        <article v-show="activePanel === 'users'" class="civic-workspace civic-access-panel">
                <div class="civic-workspace-header">
                    <div>
                        <div class="civic-eyebrow">Assignments</div>
                        <h2>User access</h2>
                        <div class="civic-setup-badge-group">
                            <span class="civic-setup-badge" :data-tone="canManageAccessScopes ? 'manage' : 'view'">
                                {{ canManageAccessScopes ? 'Cross-MDA management' : 'Own-MDA management' }}
                            </span>
                        </div>
                    </div>
                    <button v-if="canCreateUsers" class="civic-button civic-button-primary" type="button" @click="openModal('user', 'create')">
                        Create user
                    </button>
                </div>

                <div class="civic-setup-toolbar">
                    <label class="civic-field civic-field-search">
                        <span>Search visible users</span>
                        <input v-model="userSearch" type="text" placeholder="Search users, emails, roles, or MDA">
                    </label>
                    <div class="civic-setup-toolbar-note">
                        <span>Visible users</span>
                        <strong>{{ visibleUsers.length }}</strong>
                    </div>
                </div>

                <div class="civic-admin-list civic-admin-list-tall">
                    <button v-for="user in visibleUsers" :key="user.id" :class="{ active: selectedUserId === user.id }" @click="chooseUser(user.id)">
                        <span>
                            <span class="civic-name-row">
                                <span class="civic-dot" :data-tone="user.status === 'active' ? 'manage' : 'view'" :title="user.status" />
                                {{ user.name }}
                            </span>
                            <small>{{ user.email }}</small>
                        </span>
                        <strong>{{ user.mda?.code ?? 'Platform' }}</strong>
                    </button>
                    <div v-if="visibleUsers.length === 0" class="civic-setup-empty">No user matches the current search.</div>
                </div>

                <div class="civic-access-detail">
                    <div class="civic-workspace-header">
                        <div>
                            <div class="civic-eyebrow">Selected user</div>
                            <h2>{{ selectedUser?.name ?? 'Choose a user' }}</h2>
                        </div>
                        <button v-if="selectedUser" class="civic-button civic-button-primary" type="button" @click="openModal('user', 'edit')">
                            Edit user
                        </button>
                    </div>
                    <div v-if="selectedUser" class="civic-access-detail-body">
                        <dl class="civic-detail-grid civic-setup-detail-grid">
                            <div v-for="fact in userFacts" :key="`${fact.label}-${fact.value}`">
                                <dt>{{ fact.label }}</dt>
                                <dd>{{ fact.value }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div v-else class="civic-setup-empty civic-setup-empty-panel">Select a user to review their roles and tenancy scope.</div>
                </div>
        </article>

        <article v-show="activePanel === 'roles'" class="civic-workspace civic-access-panel">
                <div class="civic-workspace-header">
                    <div>
                        <div class="civic-eyebrow">Capability templates</div>
                        <h2>Roles and permissions</h2>
                        <div class="civic-setup-badge-group">
                            <span class="civic-setup-badge" :data-tone="canCreateRole ? 'manage' : 'view'">
                                {{ canCreateRole ? 'Role editor available' : 'Role editor hidden' }}
                            </span>
                        </div>
                    </div>
                    <button v-if="canCreateRole" class="civic-button civic-button-primary" type="button" @click="openModal('role', 'create')">
                        Create role
                    </button>
                </div>

                <div class="civic-setup-toolbar">
                    <label class="civic-field civic-field-search">
                        <span>Search manageable roles</span>
                        <input v-model="roleSearch" type="text" placeholder="Search role names, scope, or permissions">
                    </label>
                    <div class="civic-setup-toolbar-note">
                        <span>Manageable roles</span>
                        <strong>{{ visibleRoles.length }}</strong>
                    </div>
                </div>

                <div class="civic-admin-list civic-admin-list-tall">
                    <button v-for="role in visibleRoles" :key="role.id" :class="{ active: selectedRoleId === role.id }" @click="chooseRole(role.id)">
                        <span>
                            <span class="civic-name-row">
                                <span class="civic-dot" :data-tone="role.scope === 'global' ? 'manage' : 'view'" />
                                {{ role.name }}
                            </span>
                            <small>{{ role.scope === 'global' ? 'Global role' : `${role.mda?.code ?? 'MDA'} role` }}</small>
                        </span>
                        <strong>{{ role.permissions.length }}</strong>
                    </button>
                    <div v-if="visibleRoles.length === 0" class="civic-setup-empty">No role matches the current search.</div>
                </div>

                <div class="civic-access-detail">
                    <div class="civic-workspace-header">
                        <div>
                            <div class="civic-eyebrow">Selected role</div>
                            <h2>{{ selectedRole?.name ?? 'Choose a role' }}</h2>
                        </div>
                        <div v-if="selectedRole" class="civic-inline-actions">
                            <button class="civic-button civic-button-primary" type="button" @click="openModal('role', 'edit')">Edit role</button>
                            <button class="civic-button" type="button" @click="openModal('role', 'delete')">Delete</button>
                        </div>
                    </div>
                    <div v-if="selectedRole" class="civic-access-detail-body">
                        <dl class="civic-detail-grid civic-setup-detail-grid">
                            <div v-for="fact in roleFacts" :key="`${fact.label}-${fact.value}`">
                                <dt>{{ fact.label }}</dt>
                                <dd>{{ fact.value }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div v-else class="civic-setup-empty civic-setup-empty-panel">Select a role to inspect its scope and permission surface.</div>
                </div>
        </article>

        <AppModal
            :open="modalState.entity === 'user' && (modalState.mode === 'create' || modalState.mode === 'edit')"
            eyebrow="Access editor"
            :title="modalState.mode === 'create' ? 'Create user' : `Edit ${selectedUser?.name ?? 'user'}`"
            description="Backend access rules still apply if hidden controls are bypassed, so only allowed scope fields will save."
            size="wide"
            @close="closeModal"
        >
            <form class="civic-form-grid civic-dialog-form" @submit.prevent="submitUser">
                <label class="civic-field"><span>Full name</span><input v-model="activeUserForm.name" type="text" required :disabled="busy"></label>
                <label class="civic-field"><span>Email</span><input v-model="activeUserForm.email" type="email" required :disabled="busy"></label>
                <label class="civic-field"><span>Password</span><input v-model="activeUserForm.password" type="password" :required="modalState.mode === 'create'" :disabled="busy"></label>
                <label class="civic-field"><span>Confirm password</span><input v-model="activeUserForm.password_confirmation" type="password" :required="modalState.mode === 'create'" :disabled="busy"></label>
                <label class="civic-field">
                    <span>Status</span>
                    <select v-model="activeUserForm.status" :disabled="busy || !canManageUserStatus">
                        <option v-for="status in userStatuses" :key="status" :value="status">{{ status }}</option>
                    </select>
                </label>
                <label v-if="canManageAccessScopes" class="civic-field">
                    <span>Access scope</span>
                    <select v-model="activeUserForm.scope_type" :disabled="busy">
                        <option v-for="scope in scopeTypes" :key="scope" :value="scope">{{ scopeLabel(scope) }}</option>
                    </select>
                </label>
                <label v-if="!canManageAccessScopes || activeUserForm.scope_type === 'mda'" class="civic-field">
                    <span>Primary MDA</span>
                    <select v-model="activeUserForm.mda_id" :disabled="busy || (!canManageAccessScopes && mdas.length <= 1)">
                        <option v-for="mda in mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </select>
                </label>
                <label v-if="canManageAccessScopes && activeUserForm.scope_type === 'mda'" class="civic-field civic-field-wide">
                    <span>Additional MDAs</span>
                    <select v-model="activeUserForm.mda_ids" multiple size="4" :disabled="busy">
                        <option v-for="mda in mdas.filter((candidate) => candidate.id !== activeUserForm.mda_id)" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </select>
                </label>
                <label v-if="canManageAccessScopes && activeUserForm.scope_type === 'state'" class="civic-field">
                    <span>State code</span>
                    <input v-model="activeUserForm.state_code" type="text" :disabled="busy">
                </label>
                <div class="civic-field civic-field-wide">
                    <span>Assignable roles</span>
                    <div class="civic-check-grid">
                        <label v-for="role in assignableRoles" :key="role.id" class="civic-check civic-check-card">
                            <input v-model="activeUserForm.role_ids" type="checkbox" :value="role.id" :disabled="busy">
                            <span class="civic-check-copy">
                                <strong>{{ role.name }}</strong>
                                <small>{{ role.scope === 'global' ? 'Global system role' : `${role.mda?.code ?? 'MDA'} role` }}</small>
                            </span>
                        </label>
                        <div v-if="assignableRoles.length === 0" class="civic-setup-empty">No roles are available for the current scope.</div>
                    </div>
                </div>
            </form>
            <template #actions>
                <button class="civic-button" type="button" :disabled="busy" @click="closeModal">Cancel</button>
                <button class="civic-button civic-button-primary" type="button" :disabled="busy" @click="submitUser">
                    {{ busy ? 'Saving...' : modalState.mode === 'create' ? 'Create user' : 'Save user' }}
                </button>
            </template>
        </AppModal>

        <AppModal
            :open="modalState.entity === 'role' && (modalState.mode === 'create' || modalState.mode === 'edit')"
            eyebrow="Role editor"
            :title="modalState.mode === 'create' ? 'Create role' : `Edit ${selectedRole?.name ?? 'role'}`"
            description="Permission choices are filtered by role scope so MDA roles cannot be granted restricted platform capabilities."
            size="wide"
            @close="closeModal"
        >
            <form class="civic-form-grid civic-dialog-form" @submit.prevent="submitRole">
                <label class="civic-field"><span>Name</span><input v-model="activeRoleForm.name" type="text" :disabled="busy"></label>
                <label class="civic-field">
                    <span>Scope</span>
                    <select v-model="activeRoleForm.scope" :disabled="busy || (data.role_scope_options.length === 1)">
                        <option v-for="scope in data.role_scope_options" :key="scope" :value="scope">{{ scope === 'global' ? 'Global role' : 'MDA role' }}</option>
                    </select>
                </label>
                <label v-if="activeRoleForm.scope === 'mda'" class="civic-field">
                    <span>MDA</span>
                    <select v-model="activeRoleForm.mda_id" :disabled="busy || (data.can_manage_own_mda_roles && !data.can_manage_all_roles)">
                        <option v-for="mda in mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                    </select>
                </label>
                <label class="civic-field civic-field-wide">
                    <span>Search permissions</span>
                    <input v-model="permissionSearch" type="text" placeholder="Filter permissions by name" :disabled="busy">
                </label>

                <div v-for="group in permissionGroups" :key="group.id" class="civic-field civic-field-wide">
                    <span>{{ group.label }}</span>
                    <p class="civic-section-note">{{ group.blurb }}</p>
                    <div class="civic-check-grid">
                        <label v-for="permission in group.items" :key="permission.id" class="civic-check civic-check-card" :class="{ 'civic-check-muted': group.id === 'restricted' }">
                            <input
                                v-model="activeRoleForm.permissions"
                                type="checkbox"
                                :value="permission.name"
                                :disabled="busy || group.id === 'restricted'"
                            >
                            <span class="civic-check-copy">
                                <strong>{{ permission.name }}</strong>
                                <small>{{ group.id === 'restricted' ? 'Reserved for higher-scope administration.' : 'Assignable in this role scope.' }}</small>
                            </span>
                        </label>
                        <div v-if="group.items.length === 0" class="civic-setup-empty">No permissions in this group.</div>
                    </div>
                </div>
            </form>
            <template #actions>
                <button class="civic-button" type="button" :disabled="busy" @click="closeModal">Cancel</button>
                <button class="civic-button civic-button-primary" type="button" :disabled="busy" @click="submitRole">
                    {{ busy ? 'Saving...' : modalState.mode === 'create' ? 'Create role' : 'Save role' }}
                </button>
            </template>
        </AppModal>

        <AppModal
            :open="modalState.entity === 'role' && modalState.mode === 'delete'"
            eyebrow="Danger zone"
            :title="`Delete ${selectedRole?.name ?? 'role'}`"
            description="This permanently removes the selected role definition."
            @close="closeModal"
        >
            <p class="civic-section-note">Delete the role only if you are sure it should no longer be assigned anywhere in its allowed scope.</p>
            <template #actions>
                <button class="civic-button" type="button" :disabled="busy" @click="closeModal">Cancel</button>
                <button class="civic-button civic-button-danger" type="button" :disabled="busy" @click="deleteRole">
                    {{ busy ? 'Deleting...' : 'Delete role' }}
                </button>
            </template>
        </AppModal>
    </section>
</template>
