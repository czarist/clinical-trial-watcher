<script setup lang="ts">
import { ref, watch } from 'vue';
import DigestReport from '@/components/DigestReport.vue';
import { api, ApiError } from '@/api/client';
import type { Digest } from '@/api/types';

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
            error.value = exception instanceof ApiError ? exception : new ApiError(0, 'Could not load this digest. Check your connection.');
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="page">
        <p><RouterLink :to="digest?.team_id ? `/digests?team=${digest.team_id}` : '/digests'">Back to digests</RouterLink></p>
        <p v-if="error" class="notice error" role="alert">
            {{ error.status === 403 || error.status === 404 ? 'This digest is not available to you.' : error.message }}
        </p>
        <p v-else-if="!digest" class="muted">Loading the digest…</p>
        <DigestReport v-else-if="digest.content" :content="digest.content" />
        <p v-else class="notice">This edition has no content.</p>
    </div>
</template>
