<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PaginationNav from '@/components/PaginationNav.vue';
import { api, ApiError } from '@/api/client';
import type { Digest, Paginated, Team } from '@/api/types';
import { formatDate } from '@/registry';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const teams = ref<Team[]>([]);
const digests = ref<Paginated<Digest> | null>(null);
const loading = ref(false);
const error = ref<ApiError | null>(null);

const generationLabel = { ai: 'AI summaries', partial: 'Some factual summaries', fallback: 'Factual summaries' } as const;

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        digests.value = await api.get<Paginated<Digest>>('/digests', { team_id: route.query.team as string | undefined, page: route.query.page as string | undefined });
    } catch (exception) {
        digests.value = null;
        error.value = exception instanceof ApiError ? exception : new ApiError(0, 'Could not load the digests. Check your connection.');
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    teams.value = (await api.get<{ data: Team[] }>('/teams').catch(() => ({ data: [] as Team[] }))).data;
});
watch(() => route.query, load, { immediate: true });

function showScope(value: string): void {
    void router.push({ query: value === '' ? {} : { team: value } });
}
</script>

<template>
    <div class="page">
        <h1>AI digests</h1>
        <p class="muted">Each edition explains the changes in the studies a saved search follows. Turn digests on in a saved search's settings.</p>

        <div v-if="teams.length" class="scope">
            <label for="scope">Show</label>
            <select id="scope" :value="(route.query.team as string) ?? ''" @change="showScope(($event.target as HTMLSelectElement).value)">
                <option value="">My digests</option>
                <option v-for="team in teams" :key="team.id" :value="String(team.id)">Team: {{ team.name }}</option>
            </select>
        </div>

        <p v-if="loading" class="muted">Loading digests…</p>
        <p v-else-if="error" class="notice error" role="alert">
            {{ error.message }}
            <RouterLink v-if="error.status === 402" to="/account">See plans</RouterLink>
        </p>
        <template v-else-if="digests">
            <div v-if="digests.data.length === 0" class="notice">
                <template v-if="!route.query.team && !auth.billing?.features.ai_digests">
                    AI digests are part of Pro AI and Team AI. <RouterLink to="/digests/samples">See two sample editions</RouterLink> or <RouterLink to="/account">compare plans</RouterLink>.
                </template>
                <template v-else>No digest yet. Editions appear after the first daily or weekly window with matching updates.</template>
            </div>
            <ul v-else class="digests">
                <li v-for="digest in digests.data" :key="digest.id">
                    <RouterLink :to="`/digests/${digest.id}`">{{ digest.name ?? 'Digest' }}</RouterLink>
                    <span class="muted">{{ formatDate(digest.period_start) }} to {{ formatDate(digest.period_end) }} · {{ digest.total_updates }} update(s)</span>
                    <span v-if="digest.generation" class="muted">{{ generationLabel[digest.generation] }}</span>
                </li>
            </ul>
            <PaginationNav
                :page="digests.meta.current_page"
                :last-page="digests.meta.last_page"
                @go="(page: number) => router.push({ query: { ...route.query, page: String(page) } })"
            />
        </template>
    </div>
</template>

<style scoped>
.scope {
    display: flex;
    gap: var(--space-2);
    align-items: center;
    margin: var(--space-4) 0;
}

.scope select {
    max-width: 320px;
}

.digests {
    list-style: none;
    padding: 0;
    border-top: 2px solid var(--ink);
}

.digests li {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1) var(--space-4);
    padding: var(--space-3) 0;
    border-bottom: 1px solid var(--line);
}

.digests a {
    font-weight: 700;
}
</style>
