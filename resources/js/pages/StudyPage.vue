<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import StatusTrack from '@/components/StatusTrack.vue';
import { api, ApiError } from '@/api/client';
import type { TrialDetail } from '@/api/types';
import { DISCLAIMER, formatDate, phaseLabel, statusLabel, STUDY_TYPE_LABELS } from '@/registry';

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
            document.title = `${trial.value.nct_id} — Clinical Trial Watcher`;
        } catch (exception) {
            error.value = exception instanceof ApiError && exception.status === 404
                ? `There is no study ${nctId} in the database. It may not have been imported yet.`
                : 'Could not load this study. Check your connection and try again.';
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="page">
        <p><RouterLink to="/search">Back to search</RouterLink></p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-else-if="!trial" class="muted">Loading study…</p>
        <article v-else class="study">
            <header class="study__head">
                <p class="nct">{{ trial.nct_id }}<span v-if="trial.acronym" class="muted"> ({{ trial.acronym }})</span></p>
                <h1>{{ trial.brief_title }}</h1>
                <p v-if="trial.official_title && trial.official_title !== trial.brief_title" class="muted">{{ trial.official_title }}</p>
                <StatusTrack class="study__track" :status="trial.overall_status" />
                <p class="notice warning">{{ DISCLAIMER }}</p>
            </header>

            <div class="study__body">
                <div class="study__text">
                    <section v-if="trial.brief_summary">
                        <h2>Summary</h2>
                        <p class="prose">{{ trial.brief_summary }}</p>
                    </section>
                    <section v-if="trial.interventions.length">
                        <h2>What is being studied</h2>
                        <ul>
                            <li v-for="item in trial.interventions" :key="item.name"><strong>{{ item.name }}</strong><span v-if="item.type" class="muted"> ({{ item.type.toLowerCase().replace('_', ' ') }})</span><span v-if="item.description">: {{ item.description }}</span></li>
                        </ul>
                    </section>
                    <section v-if="outcomesByType.PRIMARY.length">
                        <h2>Primary outcomes</h2>
                        <ul>
                            <li v-for="outcome in outcomesByType.PRIMARY" :key="outcome.measure">{{ outcome.measure }}<span v-if="outcome.time_frame" class="muted"> — {{ outcome.time_frame }}</span></li>
                        </ul>
                    </section>
                    <section v-if="trial.eligibility.criteria">
                        <h2>Eligibility criteria</h2>
                        <p class="muted">Ages {{ trial.eligibility.min_age ?? 'not limited' }} to {{ trial.eligibility.max_age ?? 'not limited' }}<template v-if="trial.eligibility.sex">, sex: {{ trial.eligibility.sex.toLowerCase() }}</template>. Only the study team decides who can take part.</p>
                        <p class="prose">{{ trial.eligibility.criteria }}</p>
                    </section>
                    <section v-if="trial.references.length">
                        <h2>Publications</h2>
                        <ul class="references">
                            <li v-for="(reference, index) in trial.references" :key="reference.pmid ?? index">
                                {{ reference.citation }}
                                <a v-if="reference.pubmed_url" :href="reference.pubmed_url" target="_blank" rel="noopener">PubMed {{ reference.pmid }}</a>
                            </li>
                        </ul>
                    </section>
                    <section v-if="trial.status_history.length">
                        <h2>Status changes we recorded</h2>
                        <ul>
                            <li v-for="change in trial.status_history" :key="change.detected_at">{{ formatDate(change.detected_at) }}: {{ statusLabel(change.previous_status) }} to {{ statusLabel(change.new_status) }}</li>
                        </ul>
                    </section>
                </div>

                <aside class="study__facts panel">
                    <dl>
                        <dt>Study type</dt><dd>{{ trial.study_type ? (STUDY_TYPE_LABELS[trial.study_type] ?? trial.study_type) : '—' }}</dd>
                        <dt>Phase</dt><dd>{{ trial.phases.length ? phaseLabel(trial.phases) : '—' }}</dd>
                        <dt>Enrollment</dt><dd>{{ trial.enrollment.count ?? '—' }}<span v-if="trial.enrollment.type" class="muted"> ({{ trial.enrollment.type.toLowerCase() }})</span></dd>
                        <dt>Start</dt><dd>{{ formatDate(trial.start_date) }}</dd>
                        <dt>Primary completion</dt><dd>{{ formatDate(trial.primary_completion_date) }}</dd>
                        <dt>Completion</dt><dd>{{ formatDate(trial.completion_date) }}</dd>
                        <dt>Sponsor</dt><dd>{{ trial.sponsor_name ?? '—' }}</dd>
                        <dt>Conditions</dt><dd>{{ trial.conditions?.join(', ') || '—' }}</dd>
                        <dt>Countries</dt><dd>{{ countries.join(', ') || '—' }}</dd>
                        <dt>Sites</dt><dd>{{ trial.locations.length }}</dd>
                        <dt>Last update</dt><dd>{{ formatDate(trial.last_update_post_date) }}</dd>
                    </dl>
                    <a class="button secondary" :href="trial.registry_url" target="_blank" rel="noopener">Open on ClinicalTrials.gov</a>
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
