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
    <form class="filters" @submit.prevent="apply">
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
        <div class="pair">
            <div>
                <label for="f-country">{{ t('filters.country') }}</label>
                <input id="f-country" v-model.trim="form.country" :placeholder="t('filters.countryPlaceholder')" />
            </div>
            <div>
                <label for="f-city">{{ t('filters.city') }}</label>
                <input id="f-city" v-model.trim="form.city" />
            </div>
        </div>

        <fieldset>
            <legend>{{ t('filters.status') }}</legend>
            <label v-for="status in MAIN_STATUSES" :key="status" class="check">
                <input type="checkbox" :checked="form.status?.includes(status)" @change="toggle('status', status)" />
                {{ statusLabel(status) }}
            </label>
        </fieldset>

        <fieldset>
            <legend>{{ t('filters.phase') }}</legend>
            <label v-for="phase in PHASES" :key="phase" class="check">
                <input type="checkbox" :checked="form.phase?.includes(phase)" @change="toggle('phase', phase)" />
                {{ phaseLabel([phase]) }}
            </label>
        </fieldset>

        <fieldset>
            <legend>{{ t('filters.studyType') }}</legend>
            <label v-for="type in STUDY_TYPES" :key="type" class="check">
                <input type="checkbox" :checked="form.study_type?.includes(type)" @change="toggle('study_type', type)" />
                {{ studyTypeLabel(type) }}
            </label>
        </fieldset>

        <fieldset>
            <legend>{{ t('filters.startDate') }}</legend>
            <div class="pair">
                <div>
                    <label for="f-start-from">{{ t('filters.from') }}</label>
                    <input id="f-start-from" v-model="form.start_date_from" type="date" />
                </div>
                <div>
                    <label for="f-start-to">{{ t('filters.to') }}</label>
                    <input id="f-start-to" v-model="form.start_date_to" type="date" />
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>{{ t('filters.near') }}</legend>
            <div class="pair">
                <div>
                    <label for="f-lat">{{ t('filters.latitude') }}</label>
                    <input id="f-lat" v-model.number="form.latitude" type="number" step="any" min="-90" max="90" />
                </div>
                <div>
                    <label for="f-lng">{{ t('filters.longitude') }}</label>
                    <input id="f-lng" v-model.number="form.longitude" type="number" step="any" min="-180" max="180" />
                </div>
            </div>
            <label for="f-radius">{{ t('filters.radius') }}</label>
            <input id="f-radius" v-model.number="form.radius_km" type="number" min="1" max="2000" />
        </fieldset>

        <div class="actions">
            <button class="button" type="submit">{{ submitLabel ?? t('filters.submit') }}</button>
            <button class="button secondary" type="button" @click="clear">{{ t('filters.clear') }}</button>
        </div>
    </form>
</template>

<style scoped>
.filters {
    display: grid;
    gap: var(--space-4);
}

fieldset {
    border: 0;
    border-top: 1px solid var(--line);
    padding: var(--space-3) 0 0;
    margin: 0;
}

legend {
    font-weight: 800;
    font-size: var(--step--1);
    padding: 0;
    margin-bottom: var(--space-2);
}

.check {
    display: flex;
    gap: var(--space-2);
    align-items: center;
    font-weight: 400;
    margin: 0 0 var(--space-1);
}

.pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-3);
}

.actions {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
}
</style>
