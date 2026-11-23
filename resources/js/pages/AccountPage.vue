<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api, ApiError } from '@/api/client';
import type { Plan } from '@/api/types';
import { formatDate } from '@/registry';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();

const plans = ref<Plan[]>([]);
const profile = reactive({ name: auth.user?.name ?? '', email: auth.user?.email ?? '' });
const passwords = reactive({ current_password: '', password: '', password_confirmation: '' });
const message = ref<string | null>(null);
const error = ref<ApiError | null>(null);
const busy = ref(false);

onMounted(async () => {
    plans.value = (await api.get<{ data: Plan[] }>('/plans')).data;
    if (route.query.checkout === 'success') message.value = 'Payment received. Your plan changes as soon as Stripe confirms it.';
});

async function run(action: () => Promise<unknown>, success: string): Promise<void> {
    busy.value = true;
    error.value = null;
    message.value = null;
    try {
        await action();
        message.value = success;
        await auth.load();
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, 'Could not reach the server. Check your connection.');
    } finally {
        busy.value = false;
    }
}

const saveProfile = () => run(() => api.put('/account/profile', profile), 'Profile saved.');
const resendVerification = () => run(() => api.post('/auth/email/verification-notification'), 'We sent a new confirmation link to your email.');

const savePassword = () =>
    run(async () => {
        await api.put('/account/password', passwords);
        Object.assign(passwords, { current_password: '', password: '', password_confirmation: '' });
    }, 'Password changed.');

async function choosePlan(plan: Plan): Promise<void> {
    await run(async () => {
        const response = await api.post<{ checkout_url: string | null }>('/billing/plan', { plan_id: plan.id });
        if (response.checkout_url) window.location.assign(response.checkout_url);
    }, plan.is_free ? 'Your subscription ends at the end of the billing period.' : `Your plan is now ${plan.name}.`);
}

const resume = () => run(() => api.post('/billing/resume'), 'Your subscription continues.');

async function openPortal(): Promise<void> {
    await run(async () => {
        const response = await api.get<{ url: string }>('/billing/portal');
        window.location.assign(response.url);
    }, 'Opening the billing portal…');
}
</script>

<template>
    <div class="page account">
        <h1>Account and plan</h1>
        <p v-if="message" class="notice" role="status">{{ message }}</p>
        <p v-if="error && Object.keys(error.errors).length === 0" class="notice error" role="alert">{{ error.message }}</p>

        <section class="block">
            <h2>Plan</h2>
            <template v-if="auth.billing">
                <p>
                    Your alerts follow the <strong>{{ auth.billing.effective_plan?.name ?? 'free' }}</strong> plan:
                    {{ auth.billing.alerts_count }} of {{ auth.billing.alerts_limit }} alerts used.
                    <template v-if="auth.billing.on_trial">Your free trial ends on {{ formatDate(auth.billing.trial_ends_at) }}.</template>
                </p>
                <p v-if="auth.billing.subscription?.on_grace_period" class="notice warning">
                    Your subscription ends on {{ formatDate(auth.billing.subscription.ends_at) }}.
                    <button class="button secondary" type="button" :disabled="busy" @click="resume">Keep my subscription</button>
                </p>
            </template>
            <p v-if="auth.user && !auth.user.email_verified" class="notice warning">
                Confirm your email address before choosing a paid plan.
                <button class="button secondary" type="button" :disabled="busy" @click="resendVerification">Send the link again</button>
            </p>
            <ul class="plans">
                <li v-for="plan in plans" :key="plan.id" class="plan" :class="{ 'is-current': auth.billing?.effective_plan?.id === plan.id }">
                    <h3>{{ plan.name }}</h3>
                    <p class="plan__price">{{ plan.is_free ? 'Free' : `$${plan.price} / ${plan.billing_cycle === 'yearly' ? 'year' : 'month'}` }}</p>
                    <p>Up to {{ plan.alerts_limit.toLocaleString() }} alerts<template v-if="plan.trial_days && !plan.is_free">, {{ plan.trial_days }}-day trial for new subscribers</template>.</p>
                    <p v-if="auth.billing?.effective_plan?.id === plan.id" class="muted">Your current plan</p>
                    <button
                        v-else-if="plan.is_free || plan.is_purchasable"
                        class="button"
                        type="button"
                        :disabled="busy || !auth.user?.email_verified"
                        @click="choosePlan(plan)"
                    >
                        {{ plan.is_free ? 'Switch to Free' : `Choose ${plan.name}` }}
                    </button>
                    <p v-else class="muted">Contact us to subscribe.</p>
                </li>
            </ul>
            <button v-if="auth.billing?.subscription" class="button secondary" type="button" :disabled="busy" @click="openPortal">Manage payment and invoices</button>
        </section>

        <div class="forms">
            <form class="panel stack" @submit.prevent="saveProfile">
                <h2>Profile</h2>
                <div>
                    <label for="name">Name</label>
                    <input id="name" v-model="profile.name" autocomplete="name" required />
                </div>
                <div>
                    <label for="email">Email</label>
                    <input id="email" v-model="profile.email" type="email" autocomplete="email" required />
                    <p v-if="error?.field('email')" class="field-error">{{ error.field('email') }}</p>
                    <p class="muted">If you change it, we send a confirmation link to the new address.</p>
                </div>
                <button class="button" type="submit" :disabled="busy">Save profile</button>
            </form>

            <form class="panel stack" @submit.prevent="savePassword">
                <h2>Password</h2>
                <div>
                    <label for="current_password">Current password</label>
                    <input id="current_password" v-model="passwords.current_password" type="password" autocomplete="current-password" required />
                    <p v-if="error?.field('current_password')" class="field-error">{{ error.field('current_password') }}</p>
                </div>
                <div>
                    <label for="new_password">New password</label>
                    <input id="new_password" v-model="passwords.password" type="password" autocomplete="new-password" minlength="8" required />
                    <p v-if="error?.field('password')" class="field-error">{{ error.field('password') }}</p>
                </div>
                <div>
                    <label for="new_password_confirmation">Repeat the new password</label>
                    <input id="new_password_confirmation" v-model="passwords.password_confirmation" type="password" autocomplete="new-password" required />
                </div>
                <button class="button" type="submit" :disabled="busy">Change password</button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.block {
    margin-bottom: var(--space-8);
}

.plans {
    list-style: none;
    padding: 0;
    margin: var(--space-4) 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--space-4);
}

.plan {
    border: 1px solid var(--line);
    border-radius: var(--radius-panel);
    padding: var(--space-4);
    background: var(--surface);
}

.plan.is-current {
    border: 2px solid var(--mint-text);
}

.plan__price {
    font-size: var(--step-1);
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.forms {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--space-6);
}
</style>
