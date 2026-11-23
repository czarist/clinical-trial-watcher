<script setup lang="ts">
import { t } from '@/i18n';

defineProps<{ page: number; lastPage: number }>();
const emit = defineEmits<{ go: [page: number] }>();
</script>

<template>
    <nav v-if="lastPage > 1" class="pagination" :aria-label="t('pagination.label')">
        <button class="button secondary" type="button" :disabled="page <= 1" @click="emit('go', page - 1)"><i class="fas fa-chevron-left" aria-hidden="true" />{{ t('pagination.previous') }}</button>
        <span>{{ t('pagination.status', { page, last: lastPage }) }}</span>
        <button class="button secondary" type="button" :disabled="page >= lastPage" @click="emit('go', page + 1)">{{ t('pagination.next') }}<i class="fas fa-chevron-right" aria-hidden="true" /></button>
    </nav>
</template>

<style scoped>
.pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    margin-top: var(--space-6);
}
</style>
