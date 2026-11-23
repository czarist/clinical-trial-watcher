<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import StatusTrack from '@/components/StatusTrack.vue';
import TrialFilters from '@/components/TrialFilters.vue';
import { api, ApiError } from '@/api/client';
import type { Alert, AlertFrequency, AlertMatch, Paginated, SearchCriteria } from '@/api/types';
import { criteriaFromQuery } from '@/criteria';
import { formatDate, statusLabel } from '@/registry';

const props = defineProps<{ id?: string }>();
const route = useRoute();
const router = useRouter();

const isNew = computed(() => props.id === undefined);
const form = reactive({
    name: '',
    frequency: 'daily' as AlertFrequency,
    notify_status_changes: true,
    is_active: true,
});
const criteria = ref<SearchCriteria>(isNew.value ? criteriaFromQuery(route.query) : {});
const matches = ref<Paginated<AlertMatch> | null>(null);
const error = ref<ApiError | null>(null);
const saved = ref<string | null>(null);
const busy = ref(false);

const reasonLabel = { new_study: 'New study', updated_study: 'Updated', status_change: 'Status changed' } as const;

watch(
    () => props.id,
    async (id) => {
        if (id === undefined) return;
        try {
            const alert = (await api.get<{ data: Alert }>(`/alerts/${id}`)).data;
            Object.assign(form, { name: alert.name, frequency: alert.frequency, notify_status_changes: alert.notify_status_changes, is_active: alert.is_active });
            criteria.value = alert.criteria;
            matches.value = await api.get<Paginated<AlertMatch>>(`/alerts/${id}/matches`);
        } catch (exception) {
            error.value = exception instanceof ApiError ? exception : new ApiError(0, 'Could not load this alert.');
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
            const created = await api.post<{ data: Alert }>('/alerts', body);
            await router.replace(`/alerts/${created.data.id}`);
        } else {
            await api.patch(`/alerts/${props.id}`, body);
        }
        saved.value = 'Alert saved.';
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, 'Could not reach the server. Check your connection.');
    } finally {
        busy.value = false;
    }
}

async function remove(): Promise<void> {
    if (!props.id) return;
    await api.delete(`/alerts/${props.id}`);
    await router.push('/alerts');
}
</script>

<template>
    <div class="page editor">
        <p><RouterLink to="/alerts">Back to alerts</RouterLink></p>
        <h1>{{ isNew ? 'New alert' : form.name || 'Alert' }}</h1>

        <div class="editor__grid">
            <form class="panel stack" @submit.prevent="save">
                <div>
                    <label for="name">Name</label>
                    <input id="name" v-model.trim="form.name" maxlength="120" placeholder="e.g. Asthma studies in São Paulo" required />
                    <p v-if="error?.field('name')" class="field-error">{{ error.field('name') }}</p>
                </div>
                <div>
                    <label for="frequency">Email me</label>
                    <select id="frequency" v-model="form.frequency">
                        <option value="instant">As soon as a study matches</option>
                        <option value="daily">Once a day, in a summary</option>
                        <option value="weekly">Once a week, in a summary</option>
                    </select>
                </div>
                <label class="check"><input v-model="form.notify_status_changes" type="checkbox" /> Tell me when a study changes recruitment status</label>
                <label v-if="!isNew" class="check"><input v-model="form.is_active" type="checkbox" /> Alert is on</label>

                <p v-if="error?.field('criteria')" class="notice error">{{ error.field('criteria') }}</p>
                <p v-else-if="error && Object.keys(error.errors).length === 0" class="notice error" role="alert">
                    {{ error.message }}
                    <RouterLink v-if="error.status === 403" to="/account">See plans</RouterLink>
                </p>
                <p v-if="saved" class="notice" role="status">{{ saved }}</p>
                <div class="actions">
                    <button class="button" type="submit" :disabled="busy">{{ isNew ? 'Create alert' : 'Save alert' }}</button>
                    <button v-if="!isNew" class="button danger" type="button" @click="remove">Delete alert</button>
                </div>
            </form>

            <section class="panel">
                <h2>Which studies</h2>
                <TrialFilters v-model="criteria" submit-label="Use these filters" />
            </section>
        </div>

        <section v-if="matches" class="matches">
            <h2>Matches</h2>
            <p v-if="matches.data.length === 0" class="muted">No study has matched yet. Matches appear after the next import.</p>
            <ul v-else class="matches__list">
                <li v-for="match in matches.data" :key="match.id">
                    <p class="muted">{{ reasonLabel[match.reason] }} on {{ formatDate(match.matched_at) }}<template v-if="match.reason === 'status_change'">: {{ statusLabel(match.previous_status) }} to {{ statusLabel(match.new_status) }}</template></p>
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
