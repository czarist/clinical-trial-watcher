<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import AppHeader from '@/components/AppHeader.vue';
import LoadingBar from '@/components/LoadingBar.vue';
import { t } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

// The first page waits for the signed-in user; until then the start screen stays up.
const ready = ref(false);
void useRouter()
    .isReady()
    .finally(() => (ready.value = true));
</script>

<template>
    <LoadingBar />
    <div v-if="!ready" class="grid min-h-dvh place-items-center" role="status">
        <div class="flex flex-col items-center gap-5">
            <img src="/logo.svg" alt="" width="56" height="56" class="rounded-xl" />
            <span class="size-7 animate-spin rounded-full border-3 border-line border-t-mint" aria-hidden="true" />
            <span class="visually-hidden">{{ t('common.loading') }}</span>
        </div>
    </div>
    <template v-else>
        <AppHeader v-if="auth.isLoggedIn" />
        <main>
            <RouterView />
        </main>
    </template>
</template>
