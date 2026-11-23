<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api, ApiError } from '@/api/client';
import type { Plan } from '@/api/types';
import LanguageSelect from '@/components/LanguageSelect.vue';
import { formatDate, formatPrice, t, type MessageKey } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();

const plans = ref<Plan[]>([]);
const profile = reactive({ name: auth.user?.name ?? '', email: auth.user?.email ?? '' });
const passwords = reactive({ current_password: '', password: '', password_confirmation: '' });
const message = ref<string | null>(null);
const error = ref<ApiError | null>(null);
const busy = ref(false);

/** What each current plan adds, in the order shown. */
const planFeatures: Record<string, MessageKey[]> = {
    Free: ['account.features.free.search', 'account.features.free.history', 'account.features.free.alerts'],
    'Pro AI': ['account.features.proAi.everything', 'account.features.proAi.digests', 'account.features.proAi.languages'],
    'Team AI': ['account.features.teamAi.everything', 'account.features.teamAi.team', 'account.features.teamAi.reports'],
};

/** "$19/month", or "$99/month per team" for the team plan, in the reader's language. */
function priceLabel(plan: Plan): string {
    if (plan.is_free) return formatPrice(0, plan.currency);
    const price = formatPrice(plan.price, plan.currency);
    return plan.name === 'Team AI' ? t('account.perTeamMonth', { price }) : t('account.perMonth', { price });
}

onMounted(async () => {
    plans.value = (await api.get<{ data: Plan[] }>('/plans')).data;
    if (route.query.checkout === 'success') message.value = t('account.checkoutSuccess');
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
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('errors.network'));
    } finally {
        busy.value = false;
    }
}

const saveProfile = () => run(() => api.put('/account/profile', profile), t('account.done.profile'));
const resendVerification = () => run(() => api.post('/auth/email/verification-notification'), t('account.done.verification'));

const savePassword = () =>
    run(async () => {
        await api.put('/account/password', passwords);
        Object.assign(passwords, { current_password: '', password: '', password_confirmation: '' });
    }, t('account.done.password'));

async function choosePlan(plan: Plan): Promise<void> {
    await run(async () => {
        const response = await api.post<{ checkout_url: string | null }>('/billing/plan', { plan_id: plan.id });
        if (response.checkout_url) window.location.assign(response.checkout_url);
    }, plan.is_free ? t('account.done.freePlan') : t('account.done.planChanged', { plan: plan.name }));
}

const resume = () => run(() => api.post('/billing/resume'), t('account.done.resumed'));

async function openPortal(): Promise<void> {
    await run(async () => {
        const response = await api.get<{ url: string }>('/billing/portal');
        window.location.assign(response.url);
    }, t('account.done.portal'));
}
</script>

<template>
    <div class="page account">
        <h1>{{ t('account.title') }}</h1>
        <p v-if="message" class="notice" role="status">{{ message }}</p>
        <p v-if="error && Object.keys(error.errors).length === 0" class="notice error" role="alert">{{ error.message }}</p>

        <section class="block">
            <h2>{{ t('account.plan') }}</h2>
            <template v-if="auth.billing">
                <p>
                    <strong>{{ t('account.yourPlan', { plan: auth.billing.effective_plan?.name ?? 'Free' }) }}</strong>
                    {{ t('account.usage', { used: auth.billing.alerts_count, limit: auth.billing.saved_search_limit }) }}
                    <template v-if="auth.billing.features.ai_digests">{{ t('account.aiIncluded') }}</template>
                </p>
                <p v-if="auth.billing.effective_plan?.is_legacy" class="notice">
                    {{ t('account.legacy', { plan: auth.billing.effective_plan.name }) }}
                </p>
                <p v-if="auth.billing.subscription?.on_grace_period" class="notice warning">
                    {{ t('account.endsOn', { date: formatDate(auth.billing.subscription.ends_at) }) }}
                    <button class="button secondary" type="button" :disabled="busy" @click="resume">{{ t('account.keep') }}</button>
                </p>
            </template>
            <p v-if="auth.user && !auth.user.email_verified" class="notice warning">
                {{ t('account.confirmFirst') }}
                <button class="button secondary" type="button" :disabled="busy" @click="resendVerification">{{ t('account.resend') }}</button>
            </p>
            <ul class="plans">
                <li v-for="plan in plans" :key="plan.id" class="plan" :class="{ 'is-current': auth.billing?.effective_plan?.id === plan.id }">
                    <h3>{{ plan.name }}</h3>
                    <p class="plan__price">{{ priceLabel(plan) }} <span v-if="!plan.is_free" class="plan__currency">{{ plan.currency }}</span></p>
                    <ul class="plan__features">
                        <li v-for="feature in planFeatures[plan.name] ?? []" :key="feature">{{ t(feature) }}</li>
                    </ul>
                    <p v-if="auth.billing?.effective_plan?.id === plan.id" class="muted">{{ t('account.current') }}</p>
                    <button
                        v-else-if="plan.is_free || plan.is_purchasable"
                        class="button"
                        type="button"
                        :disabled="busy || !auth.user?.email_verified"
                        @click="choosePlan(plan)"
                    >
                        {{ plan.is_free ? t('account.switchToFree') : t('account.choose', { plan: plan.name }) }}
                    </button>
                    <p v-else class="muted">{{ t('account.notAvailable') }}</p>
                </li>
            </ul>
            <p class="muted">{{ t('account.pricesNote') }} <RouterLink to="/digests/samples">{{ t('account.seeSamples') }}</RouterLink>.</p>
            <button v-if="auth.billing?.subscription" class="button secondary" type="button" :disabled="busy" @click="openPortal">{{ t('account.portal') }}</button>
        </section>

        <div class="forms">
            <form class="panel stack" @submit.prevent="saveProfile">
                <h2>{{ t('account.profile') }}</h2>
                <div>
                    <label for="name">{{ t('common.name') }}</label>
                    <input id="name" v-model="profile.name" autocomplete="name" required />
                </div>
                <div>
                    <label for="email">{{ t('common.email') }}</label>
                    <input id="email" v-model="profile.email" type="email" autocomplete="email" required />
                    <p v-if="error?.field('email')" class="field-error">{{ error.field('email') }}</p>
                    <p class="muted">{{ t('account.emailChangeNote') }}</p>
                </div>
                <button class="button" type="submit" :disabled="busy">{{ t('account.saveProfile') }}</button>
            </form>

            <form class="panel stack" @submit.prevent="savePassword">
                <h2>{{ t('account.passwordTitle') }}</h2>
                <div>
                    <label for="current_password">{{ t('account.currentPassword') }}</label>
                    <input id="current_password" v-model="passwords.current_password" type="password" autocomplete="current-password" required />
                    <p v-if="error?.field('current_password')" class="field-error">{{ error.field('current_password') }}</p>
                </div>
                <div>
                    <label for="new_password">{{ t('account.newPassword') }}</label>
                    <input id="new_password" v-model="passwords.password" type="password" autocomplete="new-password" minlength="8" required />
                    <p v-if="error?.field('password')" class="field-error">{{ error.field('password') }}</p>
                </div>
                <div>
                    <label for="new_password_confirmation">{{ t('account.repeatNewPassword') }}</label>
                    <input id="new_password_confirmation" v-model="passwords.password_confirmation" type="password" autocomplete="new-password" required />
                </div>
                <button class="button" type="submit" :disabled="busy">{{ t('account.changePassword') }}</button>
            </form>
            <section class="panel stack">
                <h2>{{ t('account.languageTitle') }}</h2>
                <LanguageSelect @changed="message = t('account.done.language')" />
                <p class="muted">{{ t('account.languageNote') }}</p>
            </section>
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

.plan__currency {
    font-size: var(--step--1);
    font-weight: 400;
    color: var(--muted);
}

.plan__features {
    padding-left: var(--space-4);
    margin: 0 0 var(--space-3);
}

.forms {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--space-6);
}
</style>
