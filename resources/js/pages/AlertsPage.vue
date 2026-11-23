<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api, ApiError } from '@/api/client';
import type { Alert, Paginated } from '@/api/types';
import { formatDate, t } from '@/i18n';

const alerts = ref<Paginated<Alert> | null>(null);
const error = ref<string | null>(null);


function describe(alert: Alert): string {
    const c = alert.criteria;
    const parts = [c.q && `“${c.q}”`, c.condition, c.mesh_term, c.keyword, c.sponsor, c.city, c.country].filter(Boolean);
    if (c.status?.length) parts.push(t('alerts.statusFilters', { count: c.status.length }));
    if (c.phase?.length) parts.push(t('alerts.phaseFilters', { count: c.phase.length }));
    if (c.radius_km) parts.push(t('alerts.within', { km: c.radius_km }));
    return parts.join(', ') || t('alerts.allStudies');
}

onMounted(async () => {
    try {
        alerts.value = await api.get<Paginated<Alert>>('/alerts');
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception.message : t('alerts.loadFailed');
    }
});
</script>

<template>
    <div class="page">
        <div class="head">
            <h1>{{ t('alerts.title') }}</h1>
            <RouterLink class="button" to="/alerts/new">{{ t('alerts.new') }}</RouterLink>
        </div>
        <p class="muted">{{ t('alerts.intro') }}</p>

        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <template v-else-if="alerts">
            <p class="muted">
                {{ t('alerts.usage', { used: alerts.meta.total, limit: alerts.meta.alerts_limit ?? 0 }) }}
                <template v-if="!alerts.meta.ai_digests_available">{{ t('alerts.aiUpsell') }} <RouterLink to="/digests/samples">{{ t('alerts.seeSamples') }}</RouterLink>.</template>
            </p>
            <p v-if="alerts.data.length === 0" class="notice">{{ t('alerts.empty') }}</p>
            <ul class="alerts">
                <li v-for="alert in alerts.data" :key="alert.id" class="alert" :class="{ 'is-paused': !alert.is_active }">
                    <div>
                        <h2><RouterLink :to="`/alerts/${alert.id}`">{{ alert.name }}</RouterLink></h2>
                        <p>{{ describe(alert) }}</p>
                    </div>
                    <dl>
                        <dt>{{ t('alerts.email') }}</dt><dd>{{ alert.is_active ? t(`alerts.frequency.${alert.frequency}`) : t('alerts.paused') }}</dd>
                        <dt>{{ t('alerts.waiting') }}</dt><dd>{{ alert.pending_matches_count ?? 0 }}</dd>
                        <dt>{{ t('alerts.lastEmail') }}</dt><dd>{{ formatDate(alert.last_notified_at) }}</dd>
                        <dt>{{ t('alerts.aiDigest') }}</dt><dd>{{ alert.ai_digest.enabled ? t(`alerts.${alert.ai_digest.frequency}`) : t('alerts.off') }}</dd>
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
