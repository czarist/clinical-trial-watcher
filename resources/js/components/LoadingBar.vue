<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { isBusy } from '@/activity';
import { t } from '@/i18n';

// Quick responses would only flicker the bar, so it appears after a short wait.
const DELAY_MS = 150;

const visible = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    isBusy,
    (busy) => {
        clearTimeout(timer);
        if (busy) timer = setTimeout(() => (visible.value = true), DELAY_MS);
        else visible.value = false;
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div v-if="visible" class="pointer-events-none fixed inset-x-0 top-0 z-[60] h-1 overflow-hidden bg-mint/20" role="progressbar" :aria-label="t('common.loading')">
        <div class="loading-bar h-full w-1/3 rounded-full bg-mint" />
    </div>
</template>

<style scoped>
.loading-bar {
    animation: slide 1.1s ease-in-out infinite;
}

@keyframes slide {
    from {
        transform: translateX(-100%);
    }

    to {
        transform: translateX(300%);
    }
}
</style>
