<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PaginationNav from '@/components/PaginationNav.vue';
import { api, ApiError } from '@/api/client';
import type { Digest, Paginated, Team } from '@/api/types';
import { formatDate, t } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const teams = ref<Team[]>([]);
const digests = ref<Paginated<Digest> | null>(null);
const loading = ref(false);
const error = ref<ApiError | null>(null);


async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        digests.value = await api.get<Paginated<Digest>>('/digests', { team_id: route.query.team as string | undefined, page: route.query.page as string | undefined });
    } catch (exception) {
        digests.value = null;
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('digests.loadFailed'));
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
        <h1>{{ t('digests.title') }}</h1>
        <p class="muted">{{ t('digests.intro') }}</p>

        <div v-if="teams.length" class="scope">
            <label for="scope">{{ t('digests.show') }}</label>
            <select id="scope" :value="(route.query.team as string) ?? ''" @change="showScope(($event.target as HTMLSelectElement).value)">
                <option value="">{{ t('digests.mine') }}</option>
                <option v-for="team in teams" :key="team.id" :value="String(team.id)">{{ t('digests.teamOption', { team: team.name }) }}</option>
            </select>
        </div>

        <p v-if="loading" class="muted" role="status"><i class="fas fa-circle-notch fa-spin mr-2 text-mint-text" aria-hidden="true" />{{ t('digests.loading') }}</p>
        <p v-else-if="error" class="notice error" role="alert">
            {{ error.message }}
            <RouterLink v-if="error.status === 402" to="/account">{{ t('common.seePlans') }}</RouterLink>
        </p>
        <template v-else-if="digests">
            <div v-if="digests.data.length === 0" class="notice">
                <template v-if="!route.query.team && !auth.billing?.features.ai_digests">
                    {{ t('digests.upsell') }} <RouterLink to="/digests/samples">{{ t('digests.seeTwo') }}</RouterLink> {{ t('digests.or') }} <RouterLink to="/account">{{ t('digests.compare') }}</RouterLink>.
                </template>
                <template v-else>{{ t('digests.empty') }}</template>
            </div>
            <ul v-else class="digests">
                <li v-for="digest in digests.data" :key="digest.id">
                    <RouterLink :to="`/digests/${digest.id}`">{{ digest.name ?? t('digests.fallbackName') }}</RouterLink>
                    <span class="muted">{{ t('digests.period', { start: formatDate(digest.period_start), end: formatDate(digest.period_end) }) }} · {{ t('digests.updates', { count: digest.total_updates }) }}</span>
                    <span v-if="digest.generation" class="muted">{{ t(`digests.generation.${digest.generation}`) }}</span>
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
