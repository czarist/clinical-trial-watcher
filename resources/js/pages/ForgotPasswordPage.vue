<script setup lang="ts">
import { ref } from 'vue';
import AuthLayout from '@/components/AuthLayout.vue';
import { api, ApiError } from '@/api/client';

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
        error.value = exception instanceof ApiError ? exception.message : 'Could not reach the server. Check your connection.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Reset your password">
        <p v-if="message" class="notice" role="status">{{ message }}</p>
        <form v-else class="stack" @submit.prevent="submit">
            <p class="muted">Enter the email of your account and we will send you a link to choose a new password.</p>
            <div>
                <label for="email">Email</label>
                <input id="email" v-model="email" type="email" autocomplete="email" required />
            </div>
            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <button class="button" type="submit" :disabled="busy">Send reset link</button>
        </form>
        <p><RouterLink to="/login">Back to log in</RouterLink></p>
    </AuthLayout>
</template>
