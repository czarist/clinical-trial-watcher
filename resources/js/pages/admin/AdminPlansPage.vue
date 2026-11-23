<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { api, ApiError } from '@/api/client';
import type { Plan } from '@/api/types';
import { formatPrice, t, tx } from '@/i18n';

interface PlanForm {
    id?: number;
    name: string;
    price: number;
    billing_cycle: 'monthly' | 'yearly';
    stripe_price_id: string;
    trial_days: number;
}

const plans = ref<Plan[]>([]);
const editing = ref<PlanForm | null>(null);
const error = ref<ApiError | null>(null);
const message = ref<string | null>(null);

const empty = (): PlanForm => ({ name: '', price: 0, billing_cycle: 'monthly', stripe_price_id: '', trial_days: 0 });
const form = reactive<PlanForm>(empty());

async function load(): Promise<void> {
    plans.value = (await api.get<{ data: Plan[] }>('/admin/plans')).data;
}

function edit(plan?: Plan): void {
    Object.assign(form, plan
        ? { id: plan.id, name: plan.name, price: Number(plan.price), billing_cycle: plan.billing_cycle, stripe_price_id: plan.stripe_price_id ?? '', trial_days: plan.trial_days }
        : { ...empty(), id: undefined });
    editing.value = form;
    error.value = null;
}

async function save(): Promise<void> {
    error.value = null;
    const body = { ...form, stripe_price_id: form.stripe_price_id || null };
    try {
        if (form.id) await api.patch(`/admin/plans/${form.id}`, body);
        else await api.post('/admin/plans', body);
        message.value = t('admin.planSaved', { name: form.name });
        editing.value = null;
        await load();
    } catch (exception) {
        error.value = exception instanceof ApiError ? exception : new ApiError(0, t('admin.planSaveFailed'));
    }
}

async function remove(plan: Plan): Promise<void> {
    await api.delete(`/admin/plans/${plan.id}`);
    message.value = t('admin.planDeleted', { name: plan.name });
    await load();
}

onMounted(load);
</script>

<template>
    <section>
        <p class="muted">{{ t('admin.plansIntro') }}</p>
        <p v-if="message" class="notice" role="status">{{ message }}</p>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th>{{ t('admin.colPlan') }}</th><th>{{ t('admin.colPrice') }}</th><th>{{ t('admin.colStatus') }}</th><th>{{ t('admin.colTrial') }}</th><th>{{ t('admin.colStripe') }}</th><th><span class="visually-hidden">{{ t('admin.colActions') }}</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="plan in plans" :key="plan.id">
                        <td>{{ plan.name }}</td>
                        <td>{{ plan.is_free ? t('admin.free') : t('admin.pricePer', { price: formatPrice(plan.price, plan.currency), cycle: tx(`admin.cycle.${plan.billing_cycle}`, plan.billing_cycle) }) }}</td>
                        <td>{{ plan.is_legacy ? t('admin.legacy') : t('admin.offered') }}</td>
                        <td>{{ t('admin.trialDays', { count: plan.trial_days }) }}</td>
                        <td>{{ plan.stripe_price_id ?? '—' }}</td>
                        <td>
                            <button class="button secondary" type="button" @click="edit(plan)">{{ t('common.edit') }}</button>
                            <button class="button danger" type="button" @click="remove(plan)">{{ t('common.delete') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <button class="button" type="button" @click="edit()">{{ t('admin.newPlan') }}</button>

        <form v-if="editing" class="panel stack editor" @submit.prevent="save">
            <h2>{{ form.id ? t('admin.editPlan', { name: form.name }) : t('admin.newPlan') }}</h2>
            <div>
                <label for="plan-name">{{ t('common.name') }}</label>
                <input id="plan-name" v-model.trim="form.name" required />
                <p v-if="error?.field('name')" class="field-error">{{ error.field('name') }}</p>
            </div>
            <div class="grid">
                <div><label for="plan-price">{{ t('admin.price') }}</label><input id="plan-price" v-model.number="form.price" type="number" min="0" step="0.01" required /></div>
                <div>
                    <label for="plan-cycle">{{ t('admin.billingCycle') }}</label>
                    <select id="plan-cycle" v-model="form.billing_cycle"><option value="monthly">{{ t('admin.cycle.monthly') }}</option><option value="yearly">{{ t('admin.cycle.yearly') }}</option></select>
                </div>
                <div><label for="plan-trial">{{ t('admin.trialDaysLabel') }}</label><input id="plan-trial" v-model.number="form.trial_days" type="number" min="0" max="365" /></div>
            </div>
            <div>
                <label for="plan-stripe">{{ t('admin.stripePriceId') }}</label>
                <input id="plan-stripe" v-model.trim="form.stripe_price_id" placeholder="price_..." />
                <p v-if="error?.field('stripe_price_id')" class="field-error">{{ error.field('stripe_price_id') }}</p>
            </div>
            <div class="actions">
                <button class="button" type="submit">{{ t('admin.savePlan') }}</button>
                <button class="button secondary" type="button" @click="editing = null">{{ t('common.cancel') }}</button>
            </div>
        </form>
    </section>
</template>

<style scoped>
td .button + .button {
    margin-left: var(--space-2);
}

.editor {
    margin-top: var(--space-6);
    max-width: 640px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: var(--space-3);
}

.actions {
    display: flex;
    gap: var(--space-2);
}
</style>
