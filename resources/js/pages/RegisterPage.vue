<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AuthLayout from '@/components/AuthLayout.vue';
import { ApiError } from '@/api/client';
import { t } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

const form = reactive({ name: '', email: '', password: '', password_confirmation: '' });
const error = ref<ApiError | null>(null);
const busy = ref(false);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        await auth.register(form.name, form.email, form.password, form.password_confirmation);
        await router.push('/search');
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('errors.network'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout :title="t('auth.registerTitle')">
        <p class="muted">{{ t('auth.registerIntro') }}</p>
        <form class="stack" @submit.prevent="submit">
            <div>
                <label for="name">{{ t('common.name') }}</label>
                <input id="name" v-model="form.name" autocomplete="name" required />
                <p v-if="error?.field('name')" class="field-error">{{ error.field('name') }}</p>
            </div>
            <div>
                <label for="email">{{ t('common.email') }}</label>
                <input id="email" v-model="form.email" type="email" autocomplete="email" required />
                <p v-if="error?.field('email')" class="field-error">{{ error.field('email') }}</p>
            </div>
            <div>
                <label for="password">{{ t('common.password') }}</label>
                <input id="password" v-model="form.password" type="password" autocomplete="new-password" minlength="8" required />
                <p v-if="error?.field('password')" class="field-error">{{ error.field('password') }}</p>
            </div>
            <div>
                <label for="password_confirmation">{{ t('auth.repeatPassword') }}</label>
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
            </div>
            <p v-if="error && Object.keys(error.errors).length === 0" class="notice error" role="alert">{{ error.message }}</p>
            <button class="button" type="submit" :disabled="busy">{{ t('auth.createAccount') }}</button>
        </form>
        <p>{{ t('auth.haveAccount') }} <RouterLink to="/login">{{ t('auth.login') }}</RouterLink></p>
    </AuthLayout>
</template>
