<script setup lang="ts">
import { ref } from 'vue';
import AuthLayout from '@/components/AuthLayout.vue';
import { api, ApiError } from '@/api/client';
import { t } from '@/i18n';

const email = ref('');
const message = ref<string | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        message.value = (await api.post<{ message: string }>('/auth/forgot-password', { email: email.value })).message;
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception.message : t('errors.network');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout :title="t('auth.forgotTitle')">
        <p v-if="message" class="notice" role="status">{{ message }}</p>
        <form v-else class="stack" @submit.prevent="submit">
            <p class="muted">{{ t('auth.forgotIntro') }}</p>
            <div>
                <label for="email">{{ t('common.email') }}</label>
                <input id="email" v-model="email" type="email" autocomplete="email" required />
            </div>
            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <button class="button" type="submit" :disabled="busy">{{ t('auth.sendResetLink') }}</button>
        </form>
        <p><RouterLink to="/login">{{ t('auth.backToLogin') }}</RouterLink></p>
    </AuthLayout>
</template>
