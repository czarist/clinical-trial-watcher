<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PaginationNav from '@/components/PaginationNav.vue';
import StatusTrack from '@/components/StatusTrack.vue';
import TrialFilters from '@/components/TrialFilters.vue';
import { api, ApiError } from '@/api/client';
import type { Paginated, SearchCriteria, TrialSummary } from '@/api/types';
import { criteriaFromQuery, criteriaToQuery } from '@/criteria';
import { formatDate, t } from '@/i18n';
import { phaseLabel } from '@/registry';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const criteria = ref<SearchCriteria>(criteriaFromQuery(route.query));
const sort = ref(String(route.query.sort ?? '-last_update_post_date'));
const results = ref<Paginated<TrialSummary> | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);
const filtersOpen = ref(false);

const page = computed(() => Number(route.query.page ?? 1));
const activeFilters = computed(() => Object.keys(criteria.value).length);
const hasFilters = computed(() => activeFilters.value > 0);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        results.value = await api.get<Paginated<TrialSummary>>('/trials', { ...criteria.value, sort: sort.value, page: page.value, per_page: 20 });
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception.message : t('search.loadFailed');
    } finally {
        loading.value = false;
    }
}

function search(nextPage = 1): void {
    void router.push({ query: { ...criteriaToQuery(criteria.value), sort: sort.value, page: String(nextPage) } });
}

watch(
    () => route.query,
    (query) => {
        criteria.value = criteriaFromQuery(query);
        void load();
    },
    { immediate: true },
);

function applyFilters(): void {
    filtersOpen.value = false;
    search(1);
}

function followThisSearch(): void {
    void router.push({ path: '/alerts/new', query: criteriaToQuery(criteria.value) });
}
</script>

<template>
    <div class="mx-auto grid max-w-[1180px] items-start gap-8 px-4 pt-6 pb-12 lg:grid-cols-[300px_minmax(0,1fr)] lg:pt-8">
        <!-- Filters: a sticky sidebar on wide screens, a drawer on small ones. -->
        <div v-if="filtersOpen" class="fixed inset-0 z-40 bg-ink/60 lg:hidden" aria-hidden="true" @click="filtersOpen = false" />
        <aside
            id="search-filters"
            class="fixed inset-y-0 left-0 z-50 w-[min(22rem,90vw)] overflow-y-auto border-r border-line bg-surface px-4 shadow-2xl transition-transform duration-200 lg:sticky lg:top-24 lg:z-auto lg:max-h-[calc(100vh-7rem)] lg:w-auto lg:translate-x-0 lg:rounded-panel lg:border lg:shadow-none"
            :class="filtersOpen ? 'translate-x-0' : 'max-lg:invisible -translate-x-full'"
            :aria-label="t('filters.title')"
            @keydown.esc="filtersOpen = false"
        >
            <div class="sticky top-0 z-10 -mx-4 mb-2 flex items-center justify-between border-b border-line bg-surface px-4 py-3">
                <h2 class="m-0 flex items-center gap-2 text-base font-extrabold"><i class="fas fa-filter text-mint-text" aria-hidden="true" />{{ t('filters.title') }}</h2>
                <button
                    type="button"
                    class="inline-flex size-10 items-center justify-center rounded-control text-muted hover:bg-surface-raised hover:text-text lg:hidden"
                    @click="filtersOpen = false"
                >
                    <span class="visually-hidden">{{ t('search.hideFilters') }}</span>
                    <i class="fas fa-xmark text-lg" aria-hidden="true" />
                </button>
            </div>
            <TrialFilters v-model="criteria" @submit="applyFilters" />
        </aside>

        <section class="min-w-0" aria-live="polite">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="mb-1">{{ t('search.title') }}</h1>
                    <p v-if="results" class="m-0 flex items-center gap-2 text-sm text-muted">
                        {{ t('search.count', { count: results.meta.total }) }}
                        <i v-if="loading" class="fas fa-circle-notch fa-spin text-mint-text" aria-hidden="true" />
                    </p>
                </div>
                <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                    <button class="button secondary lg:hidden" type="button" :aria-expanded="filtersOpen" aria-controls="search-filters" @click="filtersOpen = true">
                        <i class="fas fa-sliders" aria-hidden="true" />
                        {{ t('search.showFilters') }}
                        <span v-if="activeFilters" class="rounded-full bg-mint-wash px-2 text-xs font-bold text-mint-text">{{ activeFilters }}</span>
                    </button>
                    <label for="sort" class="visually-hidden">{{ t('search.sortBy') }}</label>
                    <select id="sort" v-model="sort" class="min-h-10 flex-1 sm:w-auto sm:flex-none" @change="search(1)">
                        <option value="-last_update_post_date">{{ t('search.sort.updated') }}</option>
                        <option value="-study_first_post_date">{{ t('search.sort.posted') }}</option>
                        <option value="-start_date">{{ t('search.sort.startNewest') }}</option>
                        <option value="start_date">{{ t('search.sort.startOldest') }}</option>
                        <option value="brief_title">{{ t('search.sort.title') }}</option>
                        <option value="relevance" :disabled="!criteria.q">{{ t('search.sort.relevance') }}</option>
                    </select>
                    <button v-if="hasFilters && auth.can('create_alerts')" class="button w-full sm:w-auto" type="button" @click="followThisSearch"><i class="fas fa-bell" aria-hidden="true" />{{ t('search.follow') }}</button>
                </div>
            </div>
            <p class="mt-4 mb-6 flex max-w-none gap-3 rounded-control border border-line bg-surface-raised/60 px-4 py-3 text-sm text-muted"><i class="fas fa-circle-info mt-0.5" aria-hidden="true" /><span>{{ t('app.disclaimer') }}</span></p>

            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <div v-else-if="loading && !results" role="status">
                <span class="visually-hidden">{{ t('search.loading') }}</span>
                <!-- Placeholder cards in the shape of the results, so the first load does not look empty. -->
                <ol class="m-0 grid list-none gap-3 p-0" aria-hidden="true">
                    <li v-for="n in 4" :key="n" class="grid animate-pulse gap-4 rounded-panel border border-line bg-surface p-4 sm:grid-cols-[minmax(0,1fr)_14rem] sm:gap-6 sm:p-5">
                        <div class="grid gap-3">
                            <div class="h-3.5 w-40 rounded bg-surface-raised" />
                            <div class="h-5 w-11/12 rounded bg-surface-raised" />
                            <div class="h-5 w-2/3 rounded bg-surface-raised" />
                            <div class="h-3.5 w-1/2 rounded bg-surface-raised" />
                        </div>
                        <div class="grid content-start gap-2">
                            <div class="h-1.5 w-full max-w-56 rounded-full bg-surface-raised" />
                            <div class="h-5 w-24 rounded-full bg-surface-raised" />
                        </div>
                    </li>
                </ol>
            </div>
            <template v-else-if="results">
                <p v-if="results.data.length === 0" class="notice">{{ t('search.empty') }}</p>
                <ol class="m-0 grid list-none gap-3 p-0 transition-opacity" :class="{ 'pointer-events-none opacity-55': loading }" :aria-busy="loading">
                    <li
                        v-for="trial in results.data"
                        :key="`${trial.source}:${trial.source_id}`"
                        class="group relative grid gap-4 rounded-panel border border-line bg-surface p-4 transition-colors hover:border-mint-text/60 sm:grid-cols-[minmax(0,1fr)_14rem] sm:gap-6 sm:p-5"
                    >
                        <div class="min-w-0">
                            <p class="m-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                <i class="fas fa-flask-vial text-xs text-mint-text" aria-hidden="true" />
                                <span class="nct text-text">{{ trial.nct_id ?? trial.source_id }}</span>
                                <span v-if="trial.acronym" class="text-muted">{{ trial.acronym }}</span>
                                <span class="rounded-full border border-line px-2 text-xs text-muted">{{ trial.registry }}</span>
                            </p>
                            <h2 class="mt-2 mb-2 text-lg leading-snug font-semibold text-pretty">
                                <!-- The link covers the whole card. -->
                                <RouterLink :to="`/studies/${trial.nct_id}`" class="text-text no-underline after:absolute after:inset-0 after:rounded-panel group-hover:underline">{{ trial.brief_title }}</RouterLink>
                            </h2>
                            <p v-if="trial.conditions?.length" class="mb-3 text-[0.95rem] text-muted">
                                {{ trial.conditions.slice(0, 4).join(', ') }}<template v-if="trial.conditions.length > 4"> {{ t('search.moreConditions', { count: trial.conditions.length - 4 }) }}</template>
                            </p>
                            <dl class="m-0 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                <div v-if="trial.phases.length" class="flex gap-1"><dt class="text-muted"><i class="fas fa-layer-group mr-1" aria-hidden="true" />{{ t('search.phase') }}</dt><dd class="m-0 font-medium">{{ phaseLabel(trial.phases) }}</dd></div>
                                <div v-if="trial.sponsor_name" class="flex min-w-0 gap-1"><dt class="text-muted"><i class="fas fa-building mr-1" aria-hidden="true" />{{ t('search.sponsor') }}</dt><dd class="m-0 truncate font-medium">{{ trial.sponsor_name }}</dd></div>
                                <div class="flex gap-1"><dt class="text-muted"><i class="fas fa-location-dot mr-1" aria-hidden="true" />{{ t('search.sites') }}</dt><dd class="m-0 font-medium tabular-nums">{{ trial.locations_count ?? 0 }}</dd></div>
                                <div class="flex gap-1"><dt class="text-muted"><i class="fas fa-clock-rotate-left mr-1" aria-hidden="true" />{{ t('search.updated') }}</dt><dd class="m-0 font-medium">{{ formatDate(trial.last_update_post_date) }}</dd></div>
                            </dl>
                        </div>
                        <StatusTrack class="border-t border-line pt-3 sm:border-0 sm:pt-1" :status="trial.overall_status" />
                    </li>
                </ol>
                <PaginationNav :page="results.meta.current_page" :last-page="results.meta.last_page" @go="search" />
            </template>
        </section>
    </div>
</template>
