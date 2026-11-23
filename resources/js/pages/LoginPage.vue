<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthLayout from '@/components/AuthLayout.vue';
import { ApiError } from '@/api/client';
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
            ? 'Too many attempts. Wait a minute and try again.'
            : exception instanceof ApiError ? exception.message : 'Could not reach the server. Check your connection.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Log in">
        <p v-if="route.query.verified === '1'" class="notice">Your email address is confirmed. Log in to continue.</p>
        <p v-else-if="route.query.verified === '0'" class="notice error">This confirmation link is not valid. Log in and send a new one from your account.</p>
        <p v-if="route.query.reset === '1'" class="notice">Your password was changed. Log in with the new one.</p>
        <form class="stack" @submit.prevent="submit">
            <div>
                <label for="email">Email</label>
                <input id="email" v-model="email" type="email" autocomplete="email" required />
            </div>
            <div>
                <label for="password">Password</label>
                <input id="password" v-model="password" type="password" autocomplete="current-password" required />
            </div>
            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <button class="button" type="submit" :disabled="busy">Log in</button>
        </form>
        <p class="links">
            <RouterLink to="/forgot-password">Forgot your password?</RouterLink><br />
            New here? <RouterLink to="/register">Create an account</RouterLink>
        </p>
    </AuthLayout>
</template>

<style scoped>
.links {
    margin-top: var(--space-6);
}
</style>
