<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import StatusTrack from '@/components/StatusTrack.vue';
import TrialFilters from '@/components/TrialFilters.vue';
import { api, ApiError } from '@/api/client';
import type { Alert, AlertFrequency, AlertMatch, DigestFrequency, DigestLanguage, Paginated, SearchCriteria } from '@/api/types';
import { criteriaFromQuery } from '@/criteria';
import { formatDate, formatPrice, LOCALES, t } from '@/i18n';
import { statusLabel } from '@/registry';
import { useAuthStore } from '@/stores/auth';

const props = defineProps<{ id?: string }>();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const isNew = computed(() => props.id === undefined);
const teamId = ref<number | null>(isNew.value && route.query.team ? Number(route.query.team) : null);
const form = reactive({
    name: '',
    frequency: 'daily' as AlertFrequency,
    notify_status_changes: true,
    is_active: true,
    ai_digest_enabled: false,
    ai_digest_frequency: 'weekly' as DigestFrequency,
    ai_digest_language: 'en' as DigestLanguage,
});
/** Shared searches use the team's Team AI plan; personal ones need Pro AI or Team AI. */
const aiAvailable = computed(() => teamId.value !== null || (auth.billing?.features.ai_digests ?? false));
const criteria = ref<SearchCriteria>(isNew.value ? criteriaFromQuery(route.query) : {});
const matches = ref<Paginated<AlertMatch> | null>(null);
const error = ref<ApiError | null>(null);
const saved = ref<string | null>(null);
const busy = ref(false);


watch(
    () => props.id,
    async (id) => {
        if (id === undefined) return;
        try {
            const alert = (await api.get<{ data: Alert }>(`/alerts/${id}`)).data;
            Object.assign(form, {
                name: alert.name,
                frequency: alert.frequency,
                notify_status_changes: alert.notify_status_changes,
                is_active: alert.is_active,
                ai_digest_enabled: alert.ai_digest.enabled,
                ai_digest_frequency: alert.ai_digest.frequency,
                ai_digest_language: alert.ai_digest.language,
            });
            teamId.value = alert.team_id;
            criteria.value = alert.criteria;
            matches.value = await api.get<Paginated<AlertMatch>>(`/alerts/${id}/matches`);
        } catch (exception) {
            error.value = exception instanceof ApiError ? exception : new ApiError(0, t('alertEditor.loadFailed'));
        }
    },
    { immediate: true },
);

async function save(): Promise<void> {
    busy.value = true;
    error.value = null;
    saved.value = null;
    const body = { ...form, criteria: criteria.value };
    try {
        if (isNew.value) {
            const created = await api.post<{ data: Alert }>('/alerts', { ...body, team_id: teamId.value });
            await router.replace(`/alerts/${created.data.id}`);
        } else {
            await api.patch(`/alerts/${props.id}`, body);
        }
        saved.value = t('alertEditor.saved');
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('errors.network'));
    } finally {
        busy.value = false;
    }
}

async function remove(): Promise<void> {
    if (!props.id) return;
    await api.delete(`/alerts/${props.id}`);
    await router.push(teamId.value !== null ? `/teams/${teamId.value}` : '/alerts');
}
</script>

<template>
    <div class="page editor">
        <p>
            <RouterLink v-if="teamId !== null" :to="`/teams/${teamId}`">{{ t('alertEditor.backToTeam') }}</RouterLink>
            <RouterLink v-else to="/alerts">{{ t('alertEditor.backToAlerts') }}</RouterLink>
        </p>
        <h1>{{ isNew ? (teamId !== null ? t('alertEditor.newShared') : t('alertEditor.new')) : form.name || t('alertEditor.fallbackTitle') }}</h1>
        <p v-if="teamId !== null" class="muted">{{ t('alertEditor.sharedNote') }}</p>

        <div class="editor__grid">
            <form class="panel stack" @submit.prevent="save">
                <div>
                    <label for="name">{{ t('alertEditor.name') }}</label>
                    <input id="name" v-model.trim="form.name" maxlength="120" :placeholder="t('alertEditor.namePlaceholder')" required />
                    <p v-if="error?.field('name')" class="field-error">{{ error.field('name') }}</p>
                </div>
                <div>
                    <label for="frequency">{{ t('alertEditor.emailMe') }}</label>
                    <select id="frequency" v-model="form.frequency">
                        <option value="instant">{{ t('alertEditor.frequency.instant') }}</option>
                        <option value="daily">{{ t('alertEditor.frequency.daily') }}</option>
                        <option value="weekly">{{ t('alertEditor.frequency.weekly') }}</option>
                    </select>
                </div>
                <label class="check"><input v-model="form.notify_status_changes" type="checkbox" /> {{ t('alertEditor.statusChanges') }}</label>
                <label v-if="!isNew" class="check"><input v-model="form.is_active" type="checkbox" /> {{ t('alertEditor.active') }}</label>

                <fieldset class="ai">
                    <legend>{{ t('alertEditor.aiDigest') }}</legend>
                    <template v-if="aiAvailable">
                        <label class="check">
                            <input v-model="form.ai_digest_enabled" type="checkbox" />
                            {{ t('alertEditor.aiEnable') }}
                        </label>
                        <template v-if="form.ai_digest_enabled">
                            <div>
                                <label for="ai-frequency">{{ t('alertEditor.howOften') }}</label>
                                <select id="ai-frequency" v-model="form.ai_digest_frequency">
                                    <option value="daily">{{ t('alertEditor.daily') }}</option>
                                    <option value="weekly">{{ t('alertEditor.weekly') }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="ai-language">{{ t('alertEditor.language') }}</label>
                                <select id="ai-language" v-model="form.ai_digest_language">
                                    <option v-for="item in LOCALES" :key="item.code" :value="item.code" :lang="item.tag">{{ item.name }}</option>
                                </select>
                            </div>
                        </template>
                        <p class="muted">{{ t('alertEditor.aiOffKeepsAlert') }}</p>
                    </template>
                    <template v-else>
                        <p v-if="form.ai_digest_enabled" class="notice warning">
                            {{ t('alertEditor.aiNoLongerIncluded') }}
                            <button class="button secondary" type="button" @click="form.ai_digest_enabled = false">{{ t('alertEditor.aiTurnOff') }}</button>
                        </p>
                        <p>
                            {{ t('alertEditor.aiPitch', { price: formatPrice(19, 'usd') }) }}
                        </p>
                        <p class="ai__links">
                            <RouterLink to="/digests/samples">{{ t('common.seeSamples') }}</RouterLink>
                            <RouterLink class="button secondary" to="/account">{{ t('alertEditor.upgrade') }}</RouterLink>
                        </p>
                    </template>
                </fieldset>

                <p v-if="error?.field('criteria')" class="notice error">{{ error.field('criteria') }}</p>
                <p v-else-if="error && Object.keys(error.errors).length === 0" class="notice error" role="alert">
                    {{ error.message }}
                    <RouterLink v-if="error.status === 402" to="/account">{{ t('common.seePlans') }}</RouterLink>
                </p>
                <p v-if="saved" class="notice" role="status">{{ saved }}</p>
                <div class="actions">
                    <button class="button" type="submit" :disabled="busy">{{ isNew ? t('alertEditor.create') : t('alertEditor.save') }}</button>
                    <button v-if="!isNew" class="button danger" type="button" @click="remove">{{ t('alertEditor.delete') }}</button>
                </div>
            </form>

            <section class="panel">
                <h2>{{ t('alertEditor.whichStudies') }}</h2>
                <TrialFilters v-model="criteria" :submit-label="t('alertEditor.useFilters')" />
            </section>
        </div>

        <section v-if="matches" class="matches">
            <h2>{{ t('alertEditor.matches') }}</h2>
            <p v-if="matches.data.length === 0" class="muted">{{ t('alertEditor.noMatches') }}</p>
            <ul v-else class="matches__list">
                <li v-for="match in matches.data" :key="match.id">
                    <p class="muted">{{ t('alertEditor.matchedOn', { reason: t(`alertEditor.reason.${match.reason}`), date: formatDate(match.matched_at) }) }}<template v-if="match.reason === 'status_change'">: {{ t('alertEditor.statusChange', { before: statusLabel(match.previous_status), after: statusLabel(match.new_status) }) }}</template></p>
                    <RouterLink v-if="match.trial" :to="`/studies/${match.trial.nct_id}`">{{ match.trial.nct_id }}: {{ match.trial.brief_title }}</RouterLink>
                    <StatusTrack v-if="match.trial" compact :status="match.trial.overall_status" />
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.editor__grid {
    display: grid;
    grid-template-columns: minmax(0, 360px) minmax(0, 1fr);
    gap: var(--space-6);
    align-items: start;
}

.check {
    display: flex;
    gap: var(--space-2);
    align-items: center;
    font-weight: 400;
}

.ai {
    border: 1px solid var(--line);
    border-radius: var(--radius-panel);
    padding: var(--space-3) var(--space-4);
    display: grid;
    gap: var(--space-3);
}

.ai legend {
    font-weight: 800;
    padding: 0 var(--space-1);
}

.ai p {
    margin: 0;
}

.ai__links {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
}

.actions {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
}

.matches {
    margin-top: var(--space-8);
}

.matches__list {
    list-style: none;
    padding: 0;
    border-top: 2px solid var(--ink);
}

.matches__list li {
    padding: var(--space-3) 0;
    border-bottom: 1px solid var(--line);
}

.matches__list p {
    margin: 0;
    font-size: var(--step--1);
}

@media (max-width: 900px) {
    .editor__grid {
        grid-template-columns: 1fr;
    }
}
</style>
