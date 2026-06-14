<script setup>
import { computed, onMounted, ref } from 'vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import { api, apiMessage } from '../lib/api';

const data = ref(null);
const selectedUserId = ref(null);
const selectedRoleId = ref(null);
const feedback = ref('');
const selectedUser = computed(() => data.value?.users.find((user) => user.id === selectedUserId.value));
const selectedRole = computed(() => data.value?.roles.find((role) => role.id === selectedRoleId.value));
const userForm = ref({ roles: [], scope_type: 'mda', state_code: 'NG-NI', mda_id: null });
const rolePermissions = ref([]);
const chooseUser = (id) => {
    selectedUserId.value = id;
    const user = selectedUser.value;
    const scope = user.access_scopes?.[0];
    userForm.value = { roles: user.roles.map((role) => role.name), scope_type: scope?.scope_type ?? 'mda', state_code: scope?.state_code ?? 'NG-NI', mda_id: scope?.mda_id ?? user.mda_id };
};
const chooseRole = (id) => { selectedRoleId.value = id; rolePermissions.value = selectedRole.value.permissions.map((permission) => permission.name); };
const saveUser = async () => {
    try { feedback.value = (await api.put(`/access-management/users/${selectedUserId.value}`, userForm.value)).data.message; await load(); } catch (error) { feedback.value = apiMessage(error); }
};
const saveRole = async () => {
    try { feedback.value = (await api.put(`/access-management/roles/${selectedRoleId.value}`, { permissions: rolePermissions.value })).data.message; await load(); } catch (error) { feedback.value = apiMessage(error); }
};
const load = async () => { data.value = (await api.get('/access-management')).data.data; };
onMounted(load);
</script>

<template>
    <PageHeading eyebrow="Security administration" title="Roles and access scopes" description="Roles define capabilities. Access scopes define where those capabilities may be exercised." />
    <LoadingBlock v-if="!data" />
    <section v-else class="civic-access-grid">
        <div v-if="feedback" class="civic-feedback civic-field-wide">{{ feedback }}</div>
        <article class="civic-workspace">
            <div class="civic-workspace-header"><div><div class="civic-eyebrow">Assignments</div><h2>User access</h2></div></div>
            <div class="civic-admin-list"><button v-for="user in data.users" :key="user.id" :class="{ active: selectedUserId === user.id }" @click="chooseUser(user.id)"><span>{{ user.name }}<small>{{ user.email }}</small></span><strong>{{ user.mda?.code ?? 'Platform' }}</strong></button></div>
            <form v-if="selectedUser" class="civic-admin-editor" @submit.prevent="saveUser">
                <h3>{{ selectedUser.name }}</h3>
                <label class="civic-field"><span>Access scope</span><select v-model="userForm.scope_type"><option value="platform">Platform-wide</option><option value="state">State-wide</option><option value="mda">MDA-specific</option></select></label>
                <label v-if="userForm.scope_type === 'mda'" class="civic-field"><span>MDA</span><select v-model="userForm.mda_id"><option v-for="mda in data.mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option></select></label>
                <div class="civic-check-grid"><label v-for="role in data.roles" :key="role.id" class="civic-check"><input v-model="userForm.roles" type="checkbox" :value="role.name"> {{ role.name }}</label></div>
                <button class="civic-button civic-button-primary">Save user access</button>
            </form>
        </article>
        <article class="civic-workspace">
            <div class="civic-workspace-header"><div><div class="civic-eyebrow">Capability templates</div><h2>Roles and permissions</h2></div></div>
            <div class="civic-admin-list"><button v-for="role in data.roles" :key="role.id" :class="{ active: selectedRoleId === role.id }" @click="chooseRole(role.id)"><span>{{ role.name }}</span><strong>{{ role.permissions.length }}</strong></button></div>
            <form v-if="selectedRole" class="civic-admin-editor" @submit.prevent="saveRole">
                <h3>{{ selectedRole.name }}</h3>
                <div class="civic-check-grid"><label v-for="permission in data.permissions" :key="permission.id" class="civic-check"><input v-model="rolePermissions" type="checkbox" :value="permission.name" :disabled="!data.can_manage_roles"> {{ permission.name }}</label></div>
                <button v-if="data.can_manage_roles" class="civic-button civic-button-primary">Save role permissions</button>
            </form>
        </article>
    </section>
</template>
