<script setup lang="ts">
defineProps<{ page: number; lastPage: number }>();
const emit = defineEmits<{ go: [page: number] }>();
</script>

<template>
    <nav v-if="lastPage > 1" class="pagination" aria-label="Pages">
        <button class="button secondary" type="button" :disabled="page <= 1" @click="emit('go', page - 1)">Previous page</button>
        <span>Page {{ page }} of {{ lastPage }}</span>
        <button class="button secondary" type="button" :disabled="page >= lastPage" @click="emit('go', page + 1)">Next page</button>
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
