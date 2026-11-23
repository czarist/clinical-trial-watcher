<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import DigestReport from '@/components/DigestReport.vue';
import { api, ApiError } from '@/api/client';
import type { DigestSample } from '@/api/types';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const samples = ref<DigestSample[]>([]);
const selected = ref(0);
const current = computed(() => samples.value[selected.value]);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        samples.value = (await api.get<{ data: DigestSample[] }>('/digests/samples')).data;
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception.message : 'Could not load the samples. Check your connection.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="page">
        <h1>Sample AI digests</h1>
        <p class="lede">
            Pro AI and Team AI send a daily or weekly digest for a saved search: what changed in the matching studies, why each change matches, and where to check it on
            ClinicalTrials.gov. These two editions use invented studies.
        </p>
        <p>
            <RouterLink v-if="auth.isLoggedIn" class="button" to="/account">See plans</RouterLink>
            <RouterLink v-else class="button" to="/register">Create a free account</RouterLink>
        </p>

        <p v-if="loading" class="muted">Loading samples…</p>
        <p v-else-if="error" class="notice error" role="alert">{{ error }}</p>
        <template v-else>
            <div class="tabs" role="tablist" aria-label="Sample editions">
                <button
                    v-for="(sample, index) in samples"
                    :key="sample.id"
                    type="button"
                    role="tab"
                    class="tab"
                    :aria-selected="selected === index"
                    @click="selected = index"
                >
                    {{ sample.plan }} · {{ sample.language === 'pt_BR' ? 'Português' : 'English' }}
                </button>
            </div>
            <DigestReport v-if="current" :content="current" :sample-label="current.sample_label" />
        </template>
    </div>
</template>

<style scoped>
.lede {
    max-width: 70ch;
}

.tabs {
    display: flex;
    gap: var(--space-2);
    margin: var(--space-6) 0 var(--space-4);
    flex-wrap: wrap;
}

.tab {
    border: 1px solid var(--line);
    background: var(--surface);
    color: var(--text);
    border-radius: var(--radius-control);
    padding: var(--space-2) var(--space-3);
    font: inherit;
    cursor: pointer;
}

.tab[aria-selected='true'] {
    border-color: var(--ink);
    background: var(--ink);
    color: #fff;
}
</style>
