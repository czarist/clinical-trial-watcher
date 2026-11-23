<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, ApiError } from '@/api/client';
import type { Team, TeamInvitation } from '@/api/types';
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
        error.value = exception instanceof ApiError ? exception : new ApiError(0, 'Could not load your teams. Check your connection.');
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
        error.value = exception instanceof ApiError ? exception : new ApiError(0, 'Could not reach the server. Check your connection.');
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
        <h1>Teams</h1>
        <p class="muted">A Team AI team shares saved searches and AI digest reports between its members. Members join only by accepting an invitation.</p>

        <p v-if="error" class="notice error" role="alert">
            {{ error.message }}
            <RouterLink v-if="error.status === 402" to="/account">See plans</RouterLink>
        </p>
        <p v-if="loading" class="muted">Loading teams…</p>

        <template v-else>
            <section v-if="invitations.length" class="block">
                <h2>Invitations</h2>
                <ul class="rows">
                    <li v-for="invitation in invitations" :key="invitation.id">
                        <span><strong>{{ invitation.team?.name }}</strong> <span class="muted">from {{ invitation.team?.owner_name ?? 'the team owner' }}</span></span>
                        <span class="actions">
                            <button class="button" type="button" :disabled="busy" @click="accept(invitation)">Join team</button>
                            <button class="button secondary" type="button" :disabled="busy" @click="decline(invitation)">Decline</button>
                        </span>
                    </li>
                </ul>
            </section>

            <section class="block">
                <h2>Your teams</h2>
                <p v-if="teams.length === 0" class="muted">You are not in a team yet.</p>
                <ul v-else class="rows">
                    <li v-for="team in teams" :key="team.id">
                        <RouterLink :to="`/teams/${team.id}`">{{ team.name }}</RouterLink>
                        <span class="muted">{{ team.is_owner ? 'You own this team' : `Owner: ${team.owner.name}` }}<template v-if="!team.is_active"> · inactive</template></span>
                    </li>
                </ul>
            </section>

            <section v-if="!teams.some((team) => team.is_owner)" class="block">
                <h2>Create a team</h2>
                <form v-if="auth.billing?.features.team_workspace" class="create" @submit.prevent="create">
                    <label for="team-name">Team name</label>
                    <input id="team-name" v-model.trim="name" maxlength="120" required />
                    <button class="button" type="submit" :disabled="busy">Create team</button>
                </form>
                <p v-else class="notice">
                    Creating a team needs Team AI, $99/month per team. <RouterLink to="/account">See plans</RouterLink> ·
                    <RouterLink to="/digests/samples">See a sample team digest</RouterLink>
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
