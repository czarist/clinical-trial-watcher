<script setup lang="ts">
import { reactive, watch } from 'vue';
import type { SearchCriteria } from '@/api/types';
import { compactCriteria } from '@/criteria';
import { t } from '@/i18n';
import { MAIN_STATUSES, PHASES, phaseLabel, statusLabel, STUDY_TYPES, studyTypeLabel } from '@/registry';

const model = defineModel<SearchCriteria>({ required: true });
defineProps<{ submitLabel?: string; showText?: boolean }>();
const emit = defineEmits<{ submit: [] }>();

const form = reactive<SearchCriteria>({ ...model.value });
watch(model, (value) => Object.assign(form, { ...value }), { deep: true });

function toggle(key: 'status' | 'phase' | 'study_type', value: string): void {
    const current = new Set(form[key] ?? []);
    if (current.has(value)) current.delete(value);
    else current.add(value);
    form[key] = [...current];
}

// A group of filters: a rule above it; min-w-0 stops a fieldset from widening the sidebar.
const group = 'm-0 min-w-0 border-0 border-t border-solid border-line p-0 pt-4 [&>legend+*]:clear-both';
const legend = 'float-left mb-3 w-full p-0 text-sm font-extrabold';
// Choices are chips with a hidden checkbox inside, so the label stays the click and keyboard target.
const chip =
    'relative m-0 inline-flex min-h-9 cursor-pointer items-center rounded-full border border-line bg-surface px-3 py-1 text-sm font-normal transition-colors select-none hover:border-mint-text has-checked:border-mint-text has-checked:bg-mint-wash has-checked:font-semibold has-checked:text-mint-text has-focus-visible:outline-3 has-focus-visible:outline-offset-2 has-focus-visible:outline-mint';

function apply(): void {
    model.value = compactCriteria({ ...form });
    emit('submit');
}

function clear(): void {
    for (const key of Object.keys(form) as (keyof SearchCriteria)[]) delete form[key];
    apply();
}
</script>

<template>
    <form class="filters @container grid min-w-0 gap-5" @submit.prevent="apply">
        <div class="grid gap-3">
            <div v-if="showText !== false">
                <label for="f-q">{{ t('filters.text') }}</label>
                <input id="f-q" v-model.trim="form.q" type="search" :placeholder="t('filters.textPlaceholder')" />
            </div>
            <div>
                <label for="f-condition">{{ t('filters.condition') }}</label>
                <input id="f-condition" v-model.trim="form.condition" :placeholder="t('filters.conditionPlaceholder')" />
            </div>
            <div>
                <label for="f-mesh">{{ t('filters.meshTerm') }}</label>
                <input id="f-mesh" v-model.trim="form.mesh_term" :placeholder="t('filters.meshTermPlaceholder')" />
            </div>
            <div>
                <label for="f-keyword">{{ t('filters.keyword') }}</label>
                <input id="f-keyword" v-model.trim="form.keyword" />
            </div>
            <div>
                <label for="f-sponsor">{{ t('filters.sponsor') }}</label>
                <input id="f-sponsor" v-model.trim="form.sponsor" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="min-w-0">
                    <label for="f-country">{{ t('filters.country') }}</label>
                    <input id="f-country" v-model.trim="form.country" :placeholder="t('filters.countryPlaceholder')" />
                </div>
                <div class="min-w-0">
                    <label for="f-city">{{ t('filters.city') }}</label>
                    <input id="f-city" v-model.trim="form.city" />
                </div>
            </div>
        </div>

        <fieldset :class="group">
            <legend :class="legend"><i class="fas fa-signal mr-1.5 text-mint-text" aria-hidden="true" />{{ t('filters.status') }}</legend>
            <div class="flex flex-wrap gap-2">
                <label v-for="status in MAIN_STATUSES" :key="status" :class="chip">
                    <input type="checkbox" class="visually-hidden" :checked="form.status?.includes(status)" @change="toggle('status', status)" />
                    {{ statusLabel(status) }}
                </label>
            </div>
        </fieldset>

        <fieldset :class="group">
            <legend :class="legend"><i class="fas fa-layer-group mr-1.5 text-mint-text" aria-hidden="true" />{{ t('filters.phase') }}</legend>
            <div class="flex flex-wrap gap-2">
                <label v-for="phase in PHASES" :key="phase" :class="chip">
                    <input type="checkbox" class="visually-hidden" :checked="form.phase?.includes(phase)" @change="toggle('phase', phase)" />
                    {{ phaseLabel([phase]) }}
                </label>
            </div>
        </fieldset>

        <fieldset :class="group">
            <legend :class="legend"><i class="fas fa-vials mr-1.5 text-mint-text" aria-hidden="true" />{{ t('filters.studyType') }}</legend>
            <div class="flex flex-wrap gap-2">
                <label v-for="type in STUDY_TYPES" :key="type" :class="chip">
                    <input type="checkbox" class="visually-hidden" :checked="form.study_type?.includes(type)" @change="toggle('study_type', type)" />
                    {{ studyTypeLabel(type) }}
                </label>
            </div>
        </fieldset>

        <fieldset :class="group">
            <legend :class="legend"><i class="fas fa-calendar-days mr-1.5 text-mint-text" aria-hidden="true" />{{ t('filters.startDate') }}</legend>
            <div class="grid grid-cols-2 gap-3">
                <div class="min-w-0">
                    <label for="f-start-from">{{ t('filters.from') }}</label>
                    <input id="f-start-from" v-model="form.start_date_from" type="date" />
                </div>
                <div class="min-w-0">
                    <label for="f-start-to">{{ t('filters.to') }}</label>
                    <input id="f-start-to" v-model="form.start_date_to" type="date" />
                </div>
            </div>
        </fieldset>

        <fieldset :class="group">
            <legend :class="legend"><i class="fas fa-location-crosshairs mr-1.5 text-mint-text" aria-hidden="true" />{{ t('filters.near') }}</legend>
            <div class="grid grid-cols-2 gap-3">
                <div class="min-w-0">
                    <label for="f-lat">{{ t('filters.latitude') }}</label>
                    <input id="f-lat" v-model.number="form.latitude" type="number" step="any" min="-90" max="90" />
                </div>
                <div class="min-w-0">
                    <label for="f-lng">{{ t('filters.longitude') }}</label>
                    <input id="f-lng" v-model.number="form.longitude" type="number" step="any" min="-180" max="180" />
                </div>
            </div>
            <div class="mt-3">
                <label for="f-radius">{{ t('filters.radius') }}</label>
                <input id="f-radius" v-model.number="form.radius_km" type="number" min="1" max="2000" />
            </div>
        </fieldset>

        <div class="sticky bottom-0 -mx-1 grid gap-2 bg-surface px-1 py-3 @[19rem]:grid-cols-[1fr_auto]">
            <button class="button whitespace-nowrap" type="submit"><i class="fas fa-magnifying-glass" aria-hidden="true" />{{ submitLabel ?? t('filters.submit') }}</button>
            <button class="button secondary whitespace-nowrap" type="button" @click="clear"><i class="fas fa-eraser" aria-hidden="true" />{{ t('filters.clear') }}</button>
        </div>
    </form>
</template>
