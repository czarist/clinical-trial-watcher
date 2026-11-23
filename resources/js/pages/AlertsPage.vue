<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api, ApiError } from '@/api/client';
import type { Alert, Paginated } from '@/api/types';
import { formatDate } from '@/registry';

const alerts = ref<Paginated<Alert> | null>(null);
const error = ref<string | null>(null);

const frequencyLabel = { instant: 'As soon as a study matches', daily: 'Daily summary', weekly: 'Weekly summary' } as const;

function describe(alert: Alert): string {
    const c = alert.criteria;
    const parts = [c.q && `“${c.q}”`, c.condition, c.mesh_term, c.keyword, c.sponsor, c.city, c.country].filter(Boolean);
    if (c.status?.length) parts.push(`${c.status.length} status filter(s)`);
    if (c.phase?.length) parts.push(`${c.phase.length} phase filter(s)`);
    if (c.radius_km) parts.push(`within ${c.radius_km} km`);
    return parts.join(', ') || 'All studies';
}

onMounted(async () => {
    try {
        alerts.value = await api.get<Paginated<Alert>>('/alerts');
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception.message : 'Could not load your alerts.';
    }
});
</script>

<template>
    <div class="page">
        <div class="head">
            <h1>Alerts</h1>
            <RouterLink class="button" to="/alerts/new">New alert</RouterLink>
        </div>
        <p class="muted">An alert saves a search. When the daily import finds a new study, an update or a change of recruitment status that matches it, we email you.</p>

        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <template v-else-if="alerts">
            <p class="muted">You are using {{ alerts.meta.total }} of {{ alerts.meta.alerts_limit }} alerts in your plan.</p>
            <p v-if="alerts.data.length === 0" class="notice">You have no alerts yet. Search for studies and choose “Follow this search”, or create one here.</p>
            <ul class="alerts">
                <li v-for="alert in alerts.data" :key="alert.id" class="alert" :class="{ 'is-paused': !alert.is_active }">
                    <div>
                        <h2><RouterLink :to="`/alerts/${alert.id}`">{{ alert.name }}</RouterLink></h2>
                        <p>{{ describe(alert) }}</p>
                    </div>
                    <dl>
                        <dt>Email</dt><dd>{{ alert.is_active ? frequencyLabel[alert.frequency] : 'Paused' }}</dd>
                        <dt>Waiting to be sent</dt><dd>{{ alert.pending_matches_count ?? 0 }}</dd>
                        <dt>Last email</dt><dd>{{ formatDate(alert.last_notified_at) }}</dd>
                    </dl>
                </li>
            </ul>
        </template>
    </div>
</template>

<style scoped>
.head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--space-4);
}

.alerts {
    list-style: none;
    padding: 0;
    margin: var(--space-6) 0 0;
    border-top: 2px solid var(--ink);
}

.alert {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: var(--space-6);
    padding: var(--space-4) 0;
    border-bottom: 1px solid var(--line);
}

.alert h2 {
    font-size: var(--step-1);
    font-weight: 600;
    margin-bottom: var(--space-1);
}

.alert.is-paused {
    color: var(--muted);
}

dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--space-1) var(--space-3);
    margin: 0;
    font-size: var(--step--1);
}

dt {
    color: var(--muted);
}

dd {
    margin: 0;
}

@media (max-width: 760px) {
    .alert {
        grid-template-columns: 1fr;
        gap: var(--space-2);
    }
}
</style>
