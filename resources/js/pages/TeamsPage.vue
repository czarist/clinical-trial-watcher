<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, ApiError } from '@/api/client';
import type { Team, TeamInvitation } from '@/api/types';
import { formatPrice, t } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

const teams = ref<Team[]>([]);
const invitations = ref<TeamInvitation[]>([]);
const name = ref('');
const loading = ref(true);
const busy = ref(false);
const error = ref<ApiError | null>(null);

async function load(): Promise<void> {
    try {
        [teams.value, invitations.value] = await Promise.all([
            api.get<{ data: Team[] }>('/teams').then((r) => r.data),
            api.get<{ data: TeamInvitation[] }>('/team-invitations').then((r) => r.data),
        ]);
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('teams.loadFailed'));
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function act(action: () => Promise<unknown>): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        await action();
        await load();
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('errors.network'));
    } finally {
        busy.value = false;
    }
}

const accept = (invitation: TeamInvitation) => act(() => api.post(`/team-invitations/${invitation.id}/accept`));
const decline = (invitation: TeamInvitation) => act(() => api.post(`/team-invitations/${invitation.id}/decline`));

async function create(): Promise<void> {
    await act(async () => {
        const created = await api.post<{ data: Team }>('/teams', { name: name.value });
        await router.push(`/teams/${created.data.id}`);
    });
}
</script>

<template>
    <div class="page">
        <h1>{{ t('teams.title') }}</h1>
        <p class="muted">{{ t('teams.intro') }}</p>

        <p v-if="error" class="notice error" role="alert">
            {{ error.message }}
            <RouterLink v-if="error.status === 402" to="/account">{{ t('common.seePlans') }}</RouterLink>
        </p>
        <p v-if="loading" class="muted">{{ t('teams.loading') }}</p>

        <template v-else>
            <section v-if="invitations.length" class="block">
                <h2>{{ t('teams.invitations') }}</h2>
                <ul class="rows">
                    <li v-for="invitation in invitations" :key="invitation.id">
                        <span><strong>{{ invitation.team?.name }}</strong> <span class="muted">{{ t('teams.from', { owner: invitation.team?.owner_name ?? t('teams.theOwner') }) }}</span></span>
                        <span class="actions">
                            <button class="button" type="button" :disabled="busy" @click="accept(invitation)">{{ t('teams.join') }}</button>
                            <button class="button secondary" type="button" :disabled="busy" @click="decline(invitation)">{{ t('teams.decline') }}</button>
                        </span>
                    </li>
                </ul>
            </section>

            <section class="block">
                <h2>{{ t('teams.yours') }}</h2>
                <p v-if="teams.length === 0" class="muted">{{ t('teams.none') }}</p>
                <ul v-else class="rows">
                    <li v-for="team in teams" :key="team.id">
                        <RouterLink :to="`/teams/${team.id}`">{{ team.name }}</RouterLink>
                        <span class="muted">{{ team.is_owner ? t('teams.youOwn') : t('teams.owner', { name: team.owner.name }) }}<template v-if="!team.is_active"> · {{ t('teams.inactive') }}</template></span>
                    </li>
                </ul>
            </section>

            <section v-if="!teams.some((team) => team.is_owner)" class="block">
                <h2>{{ t('teams.create') }}</h2>
                <form v-if="auth.billing?.features.team_workspace" class="create" @submit.prevent="create">
                    <label for="team-name">{{ t('teams.teamName') }}</label>
                    <input id="team-name" v-model.trim="name" maxlength="120" required />
                    <button class="button" type="submit" :disabled="busy">{{ t('teams.createButton') }}</button>
                </form>
                <p v-else class="notice">
                    {{ t('teams.needsTeamAi', { price: formatPrice(99, 'usd') }) }} <RouterLink to="/account">{{ t('common.seePlans') }}</RouterLink> ·
                    <RouterLink to="/digests/samples">{{ t('teams.sampleTeamDigest') }}</RouterLink>
                </p>
            </section>
        </template>
    </div>
</template>

<style scoped>
.block {
    margin-top: var(--space-6);
}

.rows {
    list-style: none;
    padding: 0;
    border-top: 2px solid var(--ink);
}

.rows li {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: var(--space-2) var(--space-4);
    padding: var(--space-3) 0;
    border-bottom: 1px solid var(--line);
}

.actions {
    display: flex;
    gap: var(--space-2);
}

.create {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    align-items: end;
    max-width: 560px;
}

.create input {
    flex: 1 1 220px;
}
</style>
