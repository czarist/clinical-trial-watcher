<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import StatusTrack from '@/components/StatusTrack.vue';
import { api, ApiError } from '@/api/client';
import type { TrialDetail } from '@/api/types';
import { formatDate, formatNumber, locale, t } from '@/i18n';
import { phaseLabel, statusLabel, studyTypeLabel } from '@/registry';

const props = defineProps<{ nctId: string }>();

const trial = ref<TrialDetail | null>(null);
const error = ref<string | null>(null);

const countries = computed(() => [...new Set((trial.value?.locations ?? []).map((l) => l.country).filter(Boolean))]);
const outcomesByType = computed(() => ({
    PRIMARY: trial.value?.outcomes.filter((o) => o.type === 'PRIMARY') ?? [],
    SECONDARY: trial.value?.outcomes.filter((o) => o.type === 'SECONDARY') ?? [],
}));

watch(
    () => props.nctId,
    async (nctId) => {
        trial.value = null;
        error.value = null;
        try {
            trial.value = (await api.get<{ data: TrialDetail }>(`/trials/${nctId}`)).data;
            setTitle();
        } catch (exception) {
            error.value = exception instanceof ApiError && exception.status === 404
                ? t('study.notFound', { nctId })
                : t('study.loadFailed');
        }
    },
    { immediate: true },
);

function setTitle(): void {
    if (trial.value) document.title = t('titles.suffix', { title: trial.value.nct_id });
}

watch(locale, setTitle);
</script>

<template>
    <div class="page">
        <p><RouterLink to="/search">{{ t('study.back') }}</RouterLink></p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-else-if="!trial" class="muted">{{ t('study.loading') }}</p>
        <article v-else class="study">
            <header class="study__head">
                <p class="nct">{{ trial.nct_id }}<span v-if="trial.acronym" class="muted"> ({{ trial.acronym }})</span></p>
                <h1>{{ trial.brief_title }}</h1>
                <p v-if="trial.official_title && trial.official_title !== trial.brief_title" class="muted">{{ trial.official_title }}</p>
                <StatusTrack class="study__track" :status="trial.overall_status" />
                <p class="notice warning">{{ t('app.disclaimer') }}</p>
            </header>

            <div class="study__body">
                <div class="study__text">
                    <section v-if="trial.brief_summary">
                        <h2>{{ t('study.summary') }}</h2>
                        <p class="prose">{{ trial.brief_summary }}</p>
                    </section>
                    <section v-if="trial.interventions.length">
                        <h2>{{ t('study.interventions') }}</h2>
                        <ul>
                            <li v-for="item in trial.interventions" :key="item.name"><strong>{{ item.name }}</strong><span v-if="item.type" class="muted"> ({{ item.type.toLowerCase().replace('_', ' ') }})</span><span v-if="item.description">: {{ item.description }}</span></li>
                        </ul>
                    </section>
                    <section v-if="outcomesByType.PRIMARY.length">
                        <h2>{{ t('study.primaryOutcomes') }}</h2>
                        <ul>
                            <li v-for="outcome in outcomesByType.PRIMARY" :key="outcome.measure">{{ outcome.measure }}<span v-if="outcome.time_frame" class="muted"> — {{ outcome.time_frame }}</span></li>
                        </ul>
                    </section>
                    <section v-if="trial.eligibility.criteria">
                        <h2>{{ t('study.eligibility') }}</h2>
                        <p class="muted">{{ t('study.ages', { min: trial.eligibility.min_age ?? t('study.notLimited'), max: trial.eligibility.max_age ?? t('study.notLimited') }) }}<template v-if="trial.eligibility.sex">, {{ t('study.sex', { sex: trial.eligibility.sex.toLowerCase() }) }}</template>. {{ t('study.teamDecides') }}</p>
                        <p class="prose">{{ trial.eligibility.criteria }}</p>
                    </section>
                    <section v-if="trial.references.length">
                        <h2>{{ t('study.publications') }}</h2>
                        <ul class="references">
                            <li v-for="(reference, index) in trial.references" :key="reference.pmid ?? index">
                                {{ reference.citation }}
                                <a v-if="reference.pubmed_url" :href="reference.pubmed_url" target="_blank" rel="noopener">PubMed {{ reference.pmid }}</a>
                            </li>
                        </ul>
                    </section>
                    <section v-if="trial.status_history.length">
                        <h2>{{ t('study.statusHistory') }}</h2>
                        <ul>
                            <li v-for="change in trial.status_history" :key="change.detected_at">{{ t('study.statusChange', { date: formatDate(change.detected_at), before: statusLabel(change.previous_status), after: statusLabel(change.new_status) }) }}</li>
                        </ul>
                    </section>
                </div>

                <aside class="study__facts panel">
                    <dl>
                        <dt>{{ t('study.studyType') }}</dt><dd>{{ trial.study_type ? studyTypeLabel(trial.study_type) : '—' }}</dd>
                        <dt>{{ t('study.phase') }}</dt><dd>{{ trial.phases.length ? phaseLabel(trial.phases) : '—' }}</dd>
                        <dt>{{ t('study.enrollment') }}</dt><dd>{{ trial.enrollment.count !== null ? formatNumber(trial.enrollment.count) : '—' }}<span v-if="trial.enrollment.type" class="muted"> ({{ trial.enrollment.type.toLowerCase() }})</span></dd>
                        <dt>{{ t('study.start') }}</dt><dd>{{ formatDate(trial.start_date) }}</dd>
                        <dt>{{ t('study.primaryCompletion') }}</dt><dd>{{ formatDate(trial.primary_completion_date) }}</dd>
                        <dt>{{ t('study.completion') }}</dt><dd>{{ formatDate(trial.completion_date) }}</dd>
                        <dt>{{ t('study.sponsor') }}</dt><dd>{{ trial.sponsor_name ?? '—' }}</dd>
                        <dt>{{ t('study.conditions') }}</dt><dd>{{ trial.conditions?.join(', ') || '—' }}</dd>
                        <dt>{{ t('study.countries') }}</dt><dd>{{ countries.join(', ') || '—' }}</dd>
                        <dt>{{ t('study.sites') }}</dt><dd>{{ trial.locations.length }}</dd>
                        <dt>{{ t('study.lastUpdate') }}</dt><dd>{{ formatDate(trial.last_update_post_date) }}</dd>
                    </dl>
                    <a class="button secondary" :href="trial.registry_url" target="_blank" rel="noopener">{{ t('study.openRegistry') }}</a>
                </aside>
            </div>
        </article>
    </div>
</template>

<style scoped>
.study__head {
    max-width: 860px;
}

.study__track {
    max-width: 520px;
    margin: var(--space-6) 0;
}

.study__body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: var(--space-8);
    margin-top: var(--space-8);
    align-items: start;
}

.study__text section + section {
    margin-top: var(--space-8);
}

.prose {
    white-space: pre-line;
}

.references li + li {
    margin-top: var(--space-2);
}

.study__facts dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--space-2) var(--space-4);
    margin: 0 0 var(--space-6);
    font-size: var(--step--1);
}

.study__facts dt {
    color: var(--muted);
}

.study__facts dd {
    margin: 0;
}

@media (max-width: 900px) {
    .study__body {
        grid-template-columns: 1fr;
    }
}
</style>
