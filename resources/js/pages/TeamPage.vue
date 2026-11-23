<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, ApiError } from '@/api/client';
import type { Alert, Paginated, Team } from '@/api/types';
import { t } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const props = defineProps<{ id: string }>();
const auth = useAuthStore();
const router = useRouter();

const team = ref<Team | null>(null);
const searches = ref<Alert[] | null>(null);
const inviteEmail = ref('');
const message = ref<string | null>(null);
const error = ref<ApiError | null>(null);
const busy = ref(false);

async function load(): Promise<void> {
    try {
        team.value = (await api.get<{ data: Team }>(`/teams/${props.id}`)).data;
        searches.value = team.value.is_active
            ? (await api.get<Paginated<Alert>>('/alerts', { team_id: props.id })).data
            : null;
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('team.loadFailed'));
    }
}

watch(() => props.id, load, { immediate: true });

async function act(action: () => Promise<unknown>, success: string | null): Promise<void> {
    busy.value = true;
    error.value = null;
    message.value = null;
    try {
        await action();
        message.value = success;
        await load();
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('errors.network'));
    } finally {
        busy.value = false;
    }
}

const invite = () =>
    act(async () => {
        await api.post(`/teams/${props.id}/invitations`, { email: inviteEmail.value });
        inviteEmail.value = '';
    }, t('team.done.invited'));

const revoke = (invitationId: number) => act(() => api.delete(`/teams/${props.id}/invitations/${invitationId}`), t('team.done.withdrawn'));
const removeMember = (memberId: number) => act(() => api.delete(`/teams/${props.id}/members/${memberId}`), t('team.done.removed'));
const setDigests = (receives: boolean) =>
    act(() => api.patch(`/teams/${props.id}/membership`, { receives_digests: receives }), receives ? t('team.done.digestsOn') : t('team.done.digestsOff'));

async function leave(): Promise<void> {
    if (!auth.user) return;
    await act(() => api.delete(`/teams/${props.id}/members/${auth.user!.id}`), null);
    await router.push('/teams');
}

async function deleteTeam(): Promise<void> {
    if (!window.confirm(t('team.confirmDelete'))) return;
    await act(() => api.delete(`/teams/${props.id}`), null);
    await router.push('/teams');
}
</script>

<template>
    <div class="page">
        <p><RouterLink to="/teams">{{ t('team.back') }}</RouterLink></p>
        <p v-if="error" class="notice error" role="alert">
            {{ error.status === 403 ? t('team.notMember') : error.message }}
            <RouterLink v-if="error.status === 402" to="/account">{{ t('common.seePlans') }}</RouterLink>
        </p>
        <p v-if="message" class="notice" role="status">{{ message }}</p>
        <p v-if="!team && !error" class="muted" role="status"><i class="fas fa-circle-notch fa-spin mr-2 text-mint-text" aria-hidden="true" />{{ t('team.loading') }}</p>

        <template v-if="team">
            <h1>{{ team.name }}</h1>
            <p class="muted">{{ t('team.owner', { name: team.owner.name }) }}</p>
            <p v-if="!team.is_active" class="notice warning">
                {{ t('team.inactive') }}
                <template v-if="team.is_owner"><RouterLink to="/account">{{ t('team.renew') }}</RouterLink></template>
            </p>

            <div class="grid">
                <section class="panel stack">
                    <h2>{{ t('team.searches') }}</h2>
                    <p v-if="searches === null" class="muted">{{ t('team.searchesInactive') }}</p>
                    <p v-else-if="searches.length === 0" class="muted">{{ t('team.noSearches') }}</p>
                    <ul v-else class="rows">
                        <li v-for="search in searches" :key="search.id">
                            <RouterLink v-if="team.is_owner" :to="`/alerts/${search.id}`">{{ search.name }}</RouterLink>
                            <span v-else>{{ search.name }}</span>
                            <span class="muted">{{ search.ai_digest.enabled ? t(`team.aiOn.${search.ai_digest.frequency}`) : t('team.aiOff') }}</span>
                        </li>
                    </ul>
                    <RouterLink v-if="team.is_owner && team.is_active" class="button" :to="`/alerts/new?team=${team.id}`">{{ t('team.newSearch') }}</RouterLink>
                    <RouterLink v-if="team.is_active" :to="`/digests?team=${team.id}`">{{ t('team.reports') }}</RouterLink>
                </section>

                <section class="panel stack">
                    <h2>{{ t('team.members') }}</h2>
                    <ul class="rows">
                        <li v-for="member in team.members ?? []" :key="member.id">
                            <span>{{ member.name }} <span v-if="member.email" class="muted">{{ member.email }}</span></span>
                            <span class="muted">{{ member.role === 'owner' ? t('team.roleOwner') : t('team.roleMember') }}</span>
                            <button v-if="team.is_owner && member.role !== 'owner'" class="button secondary" type="button" :disabled="busy" @click="removeMember(member.id)">{{ t('common.remove') }}</button>
                        </li>
                    </ul>

                    <form v-if="team.is_owner" class="stack" @submit.prevent="invite">
                        <label for="invite">{{ t('team.invite') }}</label>
                        <input id="invite" v-model.trim="inviteEmail" type="email" :placeholder="t('team.invitePlaceholder')" required />
                        <p v-if="error?.field('email')" class="field-error">{{ error.field('email') }}</p>
                        <p class="muted">{{ t('team.inviteNote') }}</p>
                        <button class="button" type="submit" :disabled="busy || !team.is_active">{{ t('team.sendInvite') }}</button>
                    </form>

                    <template v-if="team.is_owner && team.invitations?.length">
                        <h3>{{ t('team.pending') }}</h3>
                        <ul class="rows">
                            <li v-for="invitation in team.invitations" :key="invitation.id">
                                <span>{{ invitation.email }}</span>
                                <button class="button secondary" type="button" :disabled="busy" @click="revoke(invitation.id)">{{ t('team.withdraw') }}</button>
                            </li>
                        </ul>
                    </template>
                </section>

                <section class="panel stack">
                    <h2>{{ t('team.settings') }}</h2>
                    <label class="check">
                        <input type="checkbox" :checked="team.receives_digests ?? false" :disabled="busy" @change="setDigests(($event.target as HTMLInputElement).checked)" />
                        {{ t('team.receiveDigests') }}
                    </label>
                    <button v-if="!team.is_owner" class="button secondary" type="button" :disabled="busy" @click="leave">{{ t('team.leave') }}</button>
                    <button v-else class="button danger" type="button" :disabled="busy" @click="deleteTeam">{{ t('team.delete') }}</button>
                </section>
            </div>
        </template>
    </div>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--space-6);
    margin-top: var(--space-6);
    align-items: start;
}

.rows {
    list-style: none;
    padding: 0;
    margin: 0;
}

.rows li {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) 0;
    border-bottom: 1px solid var(--line);
}

.check {
    display: flex;
    gap: var(--space-2);
    align-items: center;
    font-weight: 400;
}
</style>
