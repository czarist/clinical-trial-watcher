<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import PaginationNav from '@/components/PaginationNav.vue';
import { api, ApiError } from '@/api/client';
import type { ImportRun, Paginated } from '@/api/types';
import { formatDate, formatNumber, t } from '@/i18n';

const runs = ref<Paginated<ImportRun> | null>(null);
const options = reactive({ full: false, since: '', max_pages: '' as string | number });
const message = ref<string | null>(null);
const error = ref<string | null>(null);


async function load(page = 1): Promise<void> {
    runs.value = await api.get<Paginated<ImportRun>>('/admin/imports', { page });
}

async function start(): Promise<void> {
    error.value = null;
    try {
        await api.post('/admin/imports', {
            full: options.full,
            since: options.full || !options.since ? null : options.since,
            max_pages: options.max_pages === '' ? null : Number(options.max_pages),
        });
        message.value = t('admin.importQueued');
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception.message : t('admin.importFailed');
    }
}

onMounted(() => load());
</script>

<template>
    <section>
        <form class="panel start" @submit.prevent="start">
            <h2>{{ t('admin.startImport') }}</h2>
            <p class="muted">{{ t('admin.importIntro') }}</p>
            <label class="check"><input v-model="options.full" type="checkbox" /> {{ t('admin.wholeRegistry') }}</label>
            <div class="grid">
                <div><label for="since">{{ t('admin.updatedSince') }}</label><input id="since" v-model="options.since" type="date" :disabled="options.full" /></div>
                <div><label for="max-pages">{{ t('admin.maxPages') }}</label><input id="max-pages" v-model="options.max_pages" type="number" min="1" /></div>
            </div>
            <p v-if="message" class="notice" role="status">{{ message }}</p>
            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <button class="button" type="submit">{{ t('admin.queueImport') }}</button>
        </form>

        <div v-if="runs" class="table-scroll">
            <table>
                <thead>
                    <tr><th>{{ t('admin.run.id') }}</th><th>{{ t('admin.run.status') }}</th><th>{{ t('admin.run.started') }}</th><th>{{ t('admin.run.since') }}</th><th>{{ t('admin.run.pages') }}</th><th>{{ t('admin.run.created') }}</th><th>{{ t('admin.run.updated') }}</th><th>{{ t('admin.run.unchanged') }}</th><th>{{ t('admin.run.failed') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="run in runs.data" :key="run.id">
                        <td>{{ run.id }}</td>
                        <td :class="`status-${run.status}`">{{ t(`admin.runStatus.${run.status}`) }}<p v-if="run.error" class="muted">{{ run.error }}</p></td>
                        <td>{{ formatDate(run.started_at) }}</td>
                        <td>{{ run.updated_since ? formatDate(run.updated_since) : t('admin.everything') }}</td>
                        <td>{{ formatNumber(run.pages) }}</td>
                        <td>{{ formatNumber(run.created) }}</td>
                        <td>{{ formatNumber(run.updated) }}</td>
                        <td>{{ formatNumber(run.unchanged) }}</td>
                        <td>{{ formatNumber(run.failed) }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="runs.data.length === 0" class="muted">{{ t('admin.noRuns') }}</p>
            <PaginationNav :page="runs.meta.current_page" :last-page="runs.meta.last_page" @go="load" />
        </div>
    </section>
</template>

<style scoped>
.start {
    max-width: 560px;
    margin-bottom: var(--space-6);
    display: grid;
    gap: var(--space-3);
}

.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-3);
}

.check {
    display: flex;
    gap: var(--space-2);
    align-items: center;
    font-weight: 400;
}

.status-failed {
    color: var(--danger);
}

.status-partial {
    color: var(--amber);
}
</style>
