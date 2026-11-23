<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import DigestReport from '@/components/DigestReport.vue';
import { api, ApiError } from '@/api/client';
import LanguageSelect from '@/components/LanguageSelect.vue';
import type { DigestSample } from '@/api/types';
import { LOCALES, t } from '@/i18n';
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
        error.value = exception instanceof ApiError ? exception.message : t('digests.samples.loadFailed');
    } finally {
        loading.value = false;
    }
});

const languageName = (code: string): string => LOCALES.find((item) => item.code === code)?.name ?? code;
</script>

<template>
    <div class="page">
        <LanguageSelect v-if="!auth.isLoggedIn" class="language" />
        <h1>{{ t('digests.samples.title') }}</h1>
        <p class="lede">{{ t('digests.samples.lede') }} {{ t('digests.samples.languageNote') }}</p>
        <p>
            <RouterLink v-if="auth.isLoggedIn" class="button" to="/account">{{ t('common.seePlans') }}</RouterLink>
            <RouterLink v-else class="button" to="/register">{{ t('digests.samples.createAccount') }}</RouterLink>
        </p>

        <p v-if="loading" class="muted" role="status"><i class="fas fa-circle-notch fa-spin mr-2 text-mint-text" aria-hidden="true" />{{ t('digests.samples.loading') }}</p>
        <p v-else-if="error" class="notice error" role="alert">{{ error }}</p>
        <template v-else>
            <div class="tabs" role="tablist" :aria-label="t('digests.samples.tabs')">
                <button
                    v-for="(sample, index) in samples"
                    :key="sample.id"
                    type="button"
                    role="tab"
                    class="tab"
                    :aria-selected="selected === index"
                    @click="selected = index"
                >
                    {{ sample.plan }} · {{ languageName(sample.language) }}
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
