<script setup lang="ts">
import { ref, watch } from 'vue';
import DigestReport from '@/components/DigestReport.vue';
import { api, ApiError } from '@/api/client';
import type { Digest } from '@/api/types';
import { t } from '@/i18n';

const props = defineProps<{ id: string }>();
const digest = ref<Digest | null>(null);
const error = ref<ApiError | null>(null);

watch(
    () => props.id,
    async (id) => {
        digest.value = null;
        error.value = null;
        try {
            digest.value = (await api.get<{ data: Digest }>(`/digests/${id}`)).data;
        } catch (exception) {
            error.value = exception instanceof ApiError ? exception : new ApiError(0, t('digests.loadOneFailed'));
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="page">
        <p><RouterLink :to="digest?.team_id ? `/digests?team=${digest.team_id}` : '/digests'">{{ t('digests.back') }}</RouterLink></p>
        <p v-if="error" class="notice error" role="alert">
            {{ error.status === 403 || error.status === 404 ? t('digests.unavailable') : error.message }}
        </p>
        <p v-else-if="!digest" class="muted">{{ t('digests.loadingOne') }}</p>
        <DigestReport v-else-if="digest.content" :content="digest.content" />
        <p v-else class="notice">{{ t('digests.noContent') }}</p>
    </div>
</template>
