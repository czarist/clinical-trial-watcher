<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthLayout from '@/components/AuthLayout.vue';
import { ApiError } from '@/api/client';
import { t } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const email = ref('');
const password = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        await auth.login(email.value, password.value);
        const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/search';
        await router.push(redirect);
    } catch (exception) {
        error.value = exception instanceof ApiError && exception.status === 429
            ? t('errors.tooManyAttempts')
            : exception instanceof ApiError ? exception.message : t('errors.network');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout :title="t('auth.login')">
        <p v-if="route.query.verified === '1'" class="notice">{{ t('auth.verified') }}</p>
        <p v-else-if="route.query.verified === '0'" class="notice error">{{ t('auth.verifyFailed') }}</p>
        <p v-if="route.query.reset === '1'" class="notice">{{ t('auth.passwordChanged') }}</p>
        <form class="stack" @submit.prevent="submit">
            <div>
                <label for="email">{{ t('common.email') }}</label>
                <input id="email" v-model="email" type="email" autocomplete="email" required />
            </div>
            <div>
                <label for="password">{{ t('common.password') }}</label>
                <input id="password" v-model="password" type="password" autocomplete="current-password" required />
            </div>
            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <button class="button" type="submit" :disabled="busy">{{ t('auth.login') }}</button>
        </form>
        <p class="links">
            <RouterLink to="/forgot-password">{{ t('auth.forgot') }}</RouterLink><br />
            {{ t('auth.newHere') }} <RouterLink to="/register">{{ t('auth.createAccountLink') }}</RouterLink>
        </p>
    </AuthLayout>
</template>

<style scoped>
.links {
    margin-top: var(--space-6);
}
</style>
