<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '@/api/client';
import type { Role } from '@/api/types';

interface Permission {
    id: number;
    name: string;
    description: string | null;
}

const roles = ref<Role[]>([]);
const permissions = ref<Permission[]>([]);
const message = ref<string | null>(null);

async function toggle(role: Role, permission: Permission): Promise<void> {
    const ids = new Set(role.permissions.map((p) => p.id));
    if (ids.has(permission.id)) ids.delete(permission.id);
    else ids.add(permission.id);
    const updated = (await api.put<{ data: Role }>(`/admin/roles/${role.id}/permissions`, { permission_ids: [...ids] })).data;
    role.permissions = updated.permissions;
    message.value = `${role.name} permissions saved.`;
}

onMounted(async () => {
    const [roleList, permissionList] = await Promise.all([
        api.get<{ data: Role[] }>('/admin/roles'),
        api.get<{ data: Permission[] }>('/admin/permissions'),
    ]);
    roles.value = roleList.data;
    permissions.value = permissionList.data;
});
</script>

<template>
    <section>
        <p class="muted">Changes apply on each user's next request.</p>
        <p v-if="message" class="notice" role="status">{{ message }}</p>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Permission</th>
                        <th v-for="role in roles" :key="role.id">{{ role.name }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="permission in permissions" :key="permission.id">
                        <td>{{ permission.description ?? permission.name }}</td>
                        <td v-for="role in roles" :key="role.id">
                            <input
                                type="checkbox"
                                :aria-label="`${role.name}: ${permission.description ?? permission.name}`"
                                :checked="role.permissions.some((p) => p.id === permission.id)"
                                @change="toggle(role, permission)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
