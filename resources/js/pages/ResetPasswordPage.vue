<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthLayout from '@/components/AuthLayout.vue';
import { api, ApiError } from '@/api/client';
import { t } from '@/i18n';

const route = useRoute();
const router = useRouter();

const form = reactive({
    token: String(route.query.token ?? ''),
    email: String(route.query.email ?? ''),
    password: '',
    password_confirmation: '',
});
const error = ref<ApiError | null>(null);
const busy = ref(false);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        await api.post('/auth/reset-password', form);
        await router.push({ path: '/login', query: { reset: '1' } });
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('errors.network'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout :title="t('auth.resetTitle')">
        <p v-if="!form.token" class="notice error">{{ t('auth.resetNeedsLink') }}</p>
        <form v-else class="stack" @submit.prevent="submit">
            <div>
                <label for="email">{{ t('common.email') }}</label>
                <input id="email" v-model="form.email" type="email" autocomplete="email" required />
                <p v-if="error?.field('email')" class="field-error">{{ error.field('email') }}</p>
            </div>
            <div>
                <label for="password">{{ t('auth.newPassword') }}</label>
                <input id="password" v-model="form.password" type="password" autocomplete="new-password" minlength="8" required />
                <p v-if="error?.field('password')" class="field-error">{{ error.field('password') }}</p>
            </div>
            <div>
                <label for="password_confirmation">{{ t('auth.repeatNewPassword') }}</label>
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
            </div>
            <button class="button" type="submit" :disabled="busy">{{ t('auth.changePassword') }}</button>
        </form>
    </AuthLayout>
</template>
