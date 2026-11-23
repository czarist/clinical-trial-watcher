<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import LanguageSelect from '@/components/LanguageSelect.vue';
import { t } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const menuOpen = ref(false);

// A link opens a new page; the small-screen menu should not stay over it.
watch(
    () => route.fullPath,
    () => (menuOpen.value = false),
);

async function logOut(): Promise<void> {
    await auth.logout();
    await router.push('/login');
}

const link =
    'flex items-center gap-2 rounded-control px-3 py-2 text-[#c9d4e6] no-underline transition-colors hover:bg-white/5 hover:text-white xl:rounded-none xl:border-b-2 xl:border-transparent xl:px-0 xl:py-1 xl:hover:bg-transparent [&.router-link-active]:bg-white/10 [&.router-link-active]:text-white xl:[&.router-link-active]:border-mint xl:[&.router-link-active]:bg-transparent';
</script>

<template>
    <header class="sticky top-0 z-30 bg-ink text-white shadow-[0_1px_0_rgb(255_255_255/0.06)]">
        <nav class="mx-auto flex max-w-[1180px] flex-wrap items-center gap-x-8 gap-y-2 px-4 py-3" :aria-label="t('nav.main')">
            <RouterLink to="/search" class="flex items-center gap-2 font-extrabold text-white no-underline">
                <img src="/logo.svg" alt="" width="32" height="32" class="rounded-lg" />
                <span>{{ t('app.name') }}</span>
            </RouterLink>

            <button
                type="button"
                class="ml-auto inline-flex size-11 items-center justify-center rounded-control border border-[#33456a] text-[#c9d4e6] hover:text-white xl:hidden"
                :aria-expanded="menuOpen"
                aria-controls="main-menu"
                @click="menuOpen = !menuOpen"
            >
                <span class="visually-hidden">{{ t('nav.menu') }}</span>
                <i class="fas text-lg" :class="menuOpen ? 'fa-xmark' : 'fa-bars'" aria-hidden="true" />
            </button>

            <div
                id="main-menu"
                class="w-full flex-col gap-4 border-t border-white/10 pt-3 pb-2 xl:flex xl:w-auto xl:flex-1 xl:flex-row xl:items-center xl:border-0 xl:p-0"
                :class="menuOpen ? 'flex' : 'hidden'"
            >
                <ul class="m-0 flex list-none flex-col gap-1 p-0 xl:flex-1 xl:flex-row xl:gap-6">
                    <li v-if="auth.can('view_clinical_trials')"><RouterLink to="/search" :class="link"><i class="fas fa-magnifying-glass w-4 text-center text-[0.9em] opacity-80" aria-hidden="true" />{{ t('nav.search') }}</RouterLink></li>
                    <li v-if="auth.can('create_alerts')"><RouterLink to="/alerts" :class="link"><i class="fas fa-bell w-4 text-center text-[0.9em] opacity-80" aria-hidden="true" />{{ t('nav.alerts') }}</RouterLink></li>
                    <li><RouterLink to="/digests" :class="link"><i class="fas fa-wand-magic-sparkles w-4 text-center text-[0.9em] opacity-80" aria-hidden="true" />{{ t('nav.digests') }}</RouterLink></li>
                    <li><RouterLink to="/teams" :class="link"><i class="fas fa-users w-4 text-center text-[0.9em] opacity-80" aria-hidden="true" />{{ t('nav.teams') }}</RouterLink></li>
                    <li><RouterLink to="/account" :class="link"><i class="fas fa-circle-user w-4 text-center text-[0.9em] opacity-80" aria-hidden="true" />{{ t('nav.account') }}</RouterLink></li>
                    <li v-if="auth.isAdmin"><RouterLink to="/admin" :class="link"><i class="fas fa-screwdriver-wrench w-4 text-center text-[0.9em] opacity-80" aria-hidden="true" />{{ t('nav.admin') }}</RouterLink></li>
                </ul>
                <div class="flex items-center gap-3 px-3 xl:px-0">
                    <LanguageSelect dark />
                    <button
                        class="min-h-9 cursor-pointer rounded-control border border-[#33456a] bg-transparent px-3 py-1 text-[#c9d4e6] transition-colors hover:border-[#4a5f8a] hover:text-white"
                        type="button"
                        @click="logOut"
                    >
                        <i class="fas fa-right-from-bracket mr-1.5" aria-hidden="true" />{{ t('nav.logout') }}
                    </button>
                </div>
            </div>
        </nav>
        <p v-if="auth.user && !auth.user.email_verified" class="m-0 max-w-none bg-mint px-4 py-2 text-center text-sm text-ink">
            {{ t('nav.verifyEmail') }}
            <RouterLink to="/account" class="font-semibold text-ink">{{ t('nav.resendLink') }}</RouterLink>
        </p>
    </header>
</template>
