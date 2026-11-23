<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PaginationNav from '@/components/PaginationNav.vue';
import { api, ApiError } from '@/api/client';
import type { Paginated, Plan, Role, User } from '@/api/types';
import { t } from '@/i18n';

const users = ref<Paginated<User> | null>(null);
const roles = ref<Role[]>([]);
const plans = ref<Plan[]>([]);
const search = ref('');
const message = ref<string | null>(null);
const error = ref<string | null>(null);

async function load(page = 1): Promise<void> {
    users.value = await api.get<Paginated<User>>('/admin/users', { search: search.value, page });
}

async function update(user: User, changes: { plan_id?: number | null; role_ids?: number[] }): Promise<void> {
    error.value = null;
    try {
        const updated = (await api.patch<{ data: User }>(`/admin/users/${user.id}`, changes)).data;
        Object.assign(user, updated);
        message.value = t('admin.updated', { name: updated.name });
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception.message : t('admin.saveFailed');
    }
}

function toggleRole(user: User, roleId: number): void {
    const ids = new Set(user.roles.map((role) => role.id));
    if (ids.has(roleId)) ids.delete(roleId);
    else ids.add(roleId);
    void update(user, { role_ids: [...ids] });
}

onMounted(async () => {
    const [roleList, planList] = await Promise.all([
        api.get<{ data: Role[] }>('/admin/roles').catch(() => ({ data: [] })),
        api.get<{ data: Plan[] }>('/plans'),
    ]);
    roles.value = roleList.data;
    plans.value = planList.data;
    await load();
});
</script>

<template>
    <section>
        <form class="search" role="search" @submit.prevent="load(1)">
            <label for="user-search" class="visually-hidden">{{ t('admin.findLabel') }}</label>
            <input id="user-search" v-model.trim="search" type="search" :placeholder="t('admin.findPlaceholder')" />
            <button class="button" type="submit">{{ t('admin.find') }}</button>
        </form>
        <p v-if="message" class="notice" role="status">{{ message }}</p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <div v-if="users" class="table-scroll">
            <table>
                <thead>
                    <tr><th>{{ t('admin.colName') }}</th><th>{{ t('admin.colEmail') }}</th><th>{{ t('admin.colPlan') }}</th><th>{{ t('admin.colRoles') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.id">
                        <td>{{ user.name }}</td>
                        <td>{{ user.email }}<span v-if="!user.email_verified" class="muted"> {{ t('admin.notConfirmed') }}</span></td>
                        <td>
                            <label :for="`plan-${user.id}`" class="visually-hidden">{{ t('admin.planOf', { name: user.name }) }}</label>
                            <select :id="`plan-${user.id}`" :value="user.plan?.id ?? ''" @change="update(user, { plan_id: Number(($event.target as HTMLSelectElement).value) || null })">
                                <option value="">{{ t('admin.noPlan') }}</option>
                                <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                            </select>
                        </td>
                        <td>
                            <label v-for="role in roles" :key="role.id" class="check">
                                <input type="checkbox" :checked="user.roles.some((r) => r.id === role.id)" @change="toggleRole(user, role.id)" />
                                {{ role.name }}
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
            <PaginationNav :page="users.meta.current_page" :last-page="users.meta.last_page" @go="load" />
        </div>
    </section>
</template>

<style scoped>
.search {
    display: flex;
    gap: var(--space-2);
    max-width: 520px;
    margin-bottom: var(--space-4);
}

.check {
    display: flex;
    gap: var(--space-2);
    align-items: center;
    font-weight: 400;
}
</style>
