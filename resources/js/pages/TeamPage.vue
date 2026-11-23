<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, ApiError } from '@/api/client';
import type { Alert, Paginated, Team } from '@/api/types';
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
        error.value = exception instanceof ApiError ? exception : new ApiError(0, 'Could not load this team. Check your connection.');
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
        error.value = exception instanceof ApiError ? exception : new ApiError(0, 'Could not reach the server. Check your connection.');
    } finally {
        busy.value = false;
    }
}

const invite = () =>
    act(async () => {
        await api.post(`/teams/${props.id}/invitations`, { email: inviteEmail.value });
        inviteEmail.value = '';
    }, 'Invitation sent. They join when they accept it.');

const revoke = (invitationId: number) => act(() => api.delete(`/teams/${props.id}/invitations/${invitationId}`), 'Invitation withdrawn.');
const removeMember = (memberId: number) => act(() => api.delete(`/teams/${props.id}/members/${memberId}`), 'Member removed.');
const setDigests = (receives: boolean) =>
    act(() => api.patch(`/teams/${props.id}/membership`, { receives_digests: receives }), receives ? 'You will receive team digests.' : 'You will no longer receive team digests by email.');

async function leave(): Promise<void> {
    if (!auth.user) return;
    await act(() => api.delete(`/teams/${props.id}/members/${auth.user!.id}`), null);
    await router.push('/teams');
}

async function deleteTeam(): Promise<void> {
    if (!window.confirm('Delete this team, its shared saved searches and its digests? This cannot be undone.')) return;
    await act(() => api.delete(`/teams/${props.id}`), null);
    await router.push('/teams');
}
</script>

<template>
    <div class="page">
        <p><RouterLink to="/teams">Back to teams</RouterLink></p>
        <p v-if="error" class="notice error" role="alert">
            {{ error.status === 403 ? 'You are not a member of this team.' : error.message }}
            <RouterLink v-if="error.status === 402" to="/account">See plans</RouterLink>
        </p>
        <p v-if="message" class="notice" role="status">{{ message }}</p>
        <p v-if="!team && !error" class="muted">Loading the team…</p>

        <template v-if="team">
            <h1>{{ team.name }}</h1>
            <p class="muted">Owner: {{ team.owner.name }}</p>
            <p v-if="!team.is_active" class="notice warning">
                This team's Team AI subscription is not active, so its shared saved searches and digests are paused.
                <template v-if="team.is_owner"><RouterLink to="/account">Renew Team AI</RouterLink></template>
            </p>

            <div class="grid">
                <section class="panel stack">
                    <h2>Shared saved searches</h2>
                    <p v-if="searches === null" class="muted">Available while the team is active.</p>
                    <p v-else-if="searches.length === 0" class="muted">No shared saved search yet.</p>
                    <ul v-else class="rows">
                        <li v-for="search in searches" :key="search.id">
                            <RouterLink v-if="team.is_owner" :to="`/alerts/${search.id}`">{{ search.name }}</RouterLink>
                            <span v-else>{{ search.name }}</span>
                            <span class="muted">{{ search.ai_digest.enabled ? `AI digest, ${search.ai_digest.frequency}` : 'AI digest off' }}</span>
                        </li>
                    </ul>
                    <RouterLink v-if="team.is_owner && team.is_active" class="button" :to="`/alerts/new?team=${team.id}`">New shared saved search</RouterLink>
                    <RouterLink v-if="team.is_active" :to="`/digests?team=${team.id}`">Team digest reports</RouterLink>
                </section>

                <section class="panel stack">
                    <h2>Members</h2>
                    <ul class="rows">
                        <li v-for="member in team.members ?? []" :key="member.id">
                            <span>{{ member.name }} <span v-if="member.email" class="muted">{{ member.email }}</span></span>
                            <span class="muted">{{ member.role === 'owner' ? 'Owner' : 'Member' }}</span>
                            <button v-if="team.is_owner && member.role !== 'owner'" class="button secondary" type="button" :disabled="busy" @click="removeMember(member.id)">Remove</button>
                        </li>
                    </ul>

                    <form v-if="team.is_owner" class="stack" @submit.prevent="invite">
                        <label for="invite">Invite by email</label>
                        <input id="invite" v-model.trim="inviteEmail" type="email" placeholder="colleague@example.org" required />
                        <p v-if="error?.field('email')" class="field-error">{{ error.field('email') }}</p>
                        <p class="muted">Only people with an account can be invited. They see the invitation on their Teams page.</p>
                        <button class="button" type="submit" :disabled="busy || !team.is_active">Send invitation</button>
                    </form>

                    <template v-if="team.is_owner && team.invitations?.length">
                        <h3>Pending invitations</h3>
                        <ul class="rows">
                            <li v-for="invitation in team.invitations" :key="invitation.id">
                                <span>{{ invitation.email }}</span>
                                <button class="button secondary" type="button" :disabled="busy" @click="revoke(invitation.id)">Withdraw</button>
                            </li>
                        </ul>
                    </template>
                </section>

                <section class="panel stack">
                    <h2>Your settings</h2>
                    <label class="check">
                        <input type="checkbox" :checked="team.receives_digests ?? false" :disabled="busy" @change="setDigests(($event.target as HTMLInputElement).checked)" />
                        Email me this team's AI digests
                    </label>
                    <button v-if="!team.is_owner" class="button secondary" type="button" :disabled="busy" @click="leave">Leave team</button>
                    <button v-else class="button danger" type="button" :disabled="busy" @click="deleteTeam">Delete team</button>
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
