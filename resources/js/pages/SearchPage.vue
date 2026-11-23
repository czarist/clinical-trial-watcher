<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PaginationNav from '@/components/PaginationNav.vue';
import StatusTrack from '@/components/StatusTrack.vue';
import TrialFilters from '@/components/TrialFilters.vue';
import { api, ApiError } from '@/api/client';
import type { Paginated, SearchCriteria, TrialSummary } from '@/api/types';
import { criteriaFromQuery, criteriaToQuery } from '@/criteria';
import { DISCLAIMER, formatDate, phaseLabel } from '@/registry';
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
const hasFilters = computed(() => Object.keys(criteria.value).length > 0);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        results.value = await api.get<Paginated<TrialSummary>>('/trials', { ...criteria.value, sort: sort.value, page: page.value, per_page: 20 });
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception.message : 'Could not load studies. Check your connection and try again.';
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

function followThisSearch(): void {
    void router.push({ path: '/alerts/new', query: criteriaToQuery(criteria.value) });
}
</script>

<template>
    <div class="page search">
        <aside class="search__filters" :class="{ 'is-open': filtersOpen }">
            <button class="button secondary search__toggle" type="button" :aria-expanded="filtersOpen" @click="filtersOpen = !filtersOpen">
                {{ filtersOpen ? 'Hide filters' : 'Show filters' }}
            </button>
            <div class="search__filters-body">
                <TrialFilters v-model="criteria" @submit="search(1)" />
            </div>
        </aside>

        <section class="search__results" aria-live="polite">
            <div class="search__head">
                <h1>Studies</h1>
                <div class="search__tools">
                    <label for="sort" class="visually-hidden">Sort by</label>
                    <select id="sort" v-model="sort" @change="search(1)">
                        <option value="-last_update_post_date">Recently updated</option>
                        <option value="-study_first_post_date">Recently posted</option>
                        <option value="-start_date">Start date, newest</option>
                        <option value="start_date">Start date, oldest</option>
                        <option value="brief_title">Title, A to Z</option>
                        <option value="relevance" :disabled="!criteria.q">Best match for your words</option>
                    </select>
                    <button v-if="hasFilters && auth.can('create_alerts')" class="button" type="button" @click="followThisSearch">Follow this search</button>
                </div>
            </div>
            <p class="muted disclaimer">{{ DISCLAIMER }}</p>

            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <p v-else-if="loading && !results" class="muted">Loading studies…</p>
            <template v-else-if="results">
                <p class="muted count">{{ results.meta.total.toLocaleString() }} {{ results.meta.total === 1 ? 'study' : 'studies' }}</p>
                <p v-if="results.data.length === 0" class="notice">No study matches these filters. Remove a filter or search for a broader condition.</p>
                <ol class="results" :class="{ 'is-loading': loading }">
                    <li v-for="trial in results.data" :key="trial.nct_id" class="result">
                        <div class="result__main">
                            <p class="result__id nct">{{ trial.nct_id }}<span v-if="trial.acronym" class="muted"> ({{ trial.acronym }})</span></p>
                            <h2 class="result__title"><RouterLink :to="`/studies/${trial.nct_id}`">{{ trial.brief_title }}</RouterLink></h2>
                            <p v-if="trial.conditions?.length" class="result__conditions">{{ trial.conditions.slice(0, 4).join(', ') }}<template v-if="trial.conditions.length > 4"> and {{ trial.conditions.length - 4 }} more</template></p>
                            <dl class="result__facts">
                                <div v-if="trial.phases.length"><dt>Phase</dt><dd>{{ phaseLabel(trial.phases) }}</dd></div>
                                <div v-if="trial.sponsor_name"><dt>Sponsor</dt><dd>{{ trial.sponsor_name }}</dd></div>
                                <div><dt>Sites</dt><dd>{{ trial.locations_count ?? 0 }}</dd></div>
                                <div><dt>Updated</dt><dd>{{ formatDate(trial.last_update_post_date) }}</dd></div>
                            </dl>
                        </div>
                        <StatusTrack class="result__track" :status="trial.overall_status" />
                    </li>
                </ol>
                <PaginationNav :page="results.meta.current_page" :last-page="results.meta.last_page" @go="search" />
            </template>
        </section>
    </div>
</template>

<style scoped>
.search {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap: var(--space-8);
    align-items: start;
}

.search__toggle {
    display: none;
}

.search__head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--space-4);
    flex-wrap: wrap;
}

.search__tools {
    display: flex;
    gap: var(--space-2);
}

.search__tools select {
    width: auto;
}

.disclaimer,
.count {
    font-size: var(--step--1);
}

.results {
    list-style: none;
    margin: 0;
    padding: 0;
    border-top: 2px solid var(--ink);
}

.results.is-loading {
    opacity: 0.55;
}

.result {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 240px;
    gap: var(--space-6);
    padding: var(--space-4) 0;
    border-bottom: 1px solid var(--line);
}

.result__id {
    margin: 0;
    font-size: var(--step--1);
}

.result__title {
    font-size: var(--step-1);
    font-weight: 600;
    margin: var(--space-1) 0 var(--space-2);
}

.result__title a {
    color: var(--text);
    text-decoration: none;
}

.result__title a:hover {
    text-decoration: underline;
}

.result__conditions {
    margin-bottom: var(--space-2);
}

.result__facts {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1) var(--space-6);
    margin: 0;
    font-size: var(--step--1);
}

.result__facts div {
    display: flex;
    gap: var(--space-1);
}

.result__facts dt {
    color: var(--muted);
}

.result__facts dd {
    margin: 0;
}

@media (max-width: 900px) {
    .search {
        grid-template-columns: 1fr;
    }

    .search__toggle {
        display: inline-flex;
    }

    .search__filters-body {
        display: none;
        margin-top: var(--space-4);
    }

    .search__filters.is-open .search__filters-body {
        display: block;
    }

    .result {
        grid-template-columns: 1fr;
        gap: var(--space-3);
    }
}
</style>
