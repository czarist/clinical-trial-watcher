<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import PaginationNav from '@/components/PaginationNav.vue';
import { api, ApiError } from '@/api/client';
import type { ImportRun, Paginated } from '@/api/types';
import { formatDate } from '@/registry';

const runs = ref<Paginated<ImportRun> | null>(null);
const options = reactive({ full: false, since: '', max_pages: '' as string | number });
const message = ref<string | null>(null);
const error = ref<string | null>(null);

const statusLabel = { running: 'Running', succeeded: 'Succeeded', partial: 'Partial', failed: 'Failed' } as const;

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
        message.value = 'Import queued. It appears here when the queue worker starts it.';
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception.message : 'Could not queue the import.';
    }
}

onMounted(() => load());
</script>

<template>
    <section>
        <form class="panel start" @submit.prevent="start">
            <h2>Start an import</h2>
            <p class="muted">By default the import reads studies updated since the last successful run. One import runs at a time.</p>
            <label class="check"><input v-model="options.full" type="checkbox" /> Read the whole registry</label>
            <div class="grid">
                <div><label for="since">Updated since</label><input id="since" v-model="options.since" type="date" :disabled="options.full" /></div>
                <div><label for="max-pages">Stop after pages</label><input id="max-pages" v-model="options.max_pages" type="number" min="1" /></div>
            </div>
            <p v-if="message" class="notice" role="status">{{ message }}</p>
            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <button class="button" type="submit">Queue import</button>
        </form>

        <div v-if="runs" class="table-scroll">
            <table>
                <thead>
                    <tr><th>Run</th><th>Status</th><th>Started</th><th>Since</th><th>Pages</th><th>New</th><th>Updated</th><th>Unchanged</th><th>Failed</th></tr>
                </thead>
                <tbody>
                    <tr v-for="run in runs.data" :key="run.id">
                        <td>{{ run.id }}</td>
                        <td :class="`status-${run.status}`">{{ statusLabel[run.status] }}<p v-if="run.error" class="muted">{{ run.error }}</p></td>
                        <td>{{ formatDate(run.started_at) }}</td>
                        <td>{{ run.updated_since ? formatDate(run.updated_since) : 'Everything' }}</td>
                        <td>{{ run.pages }}</td>
                        <td>{{ run.created }}</td>
                        <td>{{ run.updated }}</td>
                        <td>{{ run.unchanged }}</td>
                        <td>{{ run.failed }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="runs.data.length === 0" class="muted">No import has run yet.</p>
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
