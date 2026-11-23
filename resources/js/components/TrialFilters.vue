<script setup lang="ts">
import { reactive, watch } from 'vue';
import type { SearchCriteria } from '@/api/types';
import { compactCriteria } from '@/criteria';
import { PHASE_LABELS, STATUS_LABELS, STUDY_TYPE_LABELS } from '@/registry';

const model = defineModel<SearchCriteria>({ required: true });
defineProps<{ submitLabel?: string; showText?: boolean }>();
const emit = defineEmits<{ submit: [] }>();

const form = reactive<SearchCriteria>({ ...model.value });
watch(model, (value) => Object.assign(form, { ...value }), { deep: true });

const mainStatuses = ['NOT_YET_RECRUITING', 'RECRUITING', 'ENROLLING_BY_INVITATION', 'ACTIVE_NOT_RECRUITING', 'COMPLETED', 'SUSPENDED', 'TERMINATED', 'WITHDRAWN'];

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
            <label for="f-q">Words in the title or summary</label>
            <input id="f-q" v-model.trim="form.q" type="search" placeholder="e.g. inhaled corticosteroids" />
        </div>
        <div>
            <label for="f-condition">Condition</label>
            <input id="f-condition" v-model.trim="form.condition" placeholder="e.g. asthma" />
        </div>
        <div>
            <label for="f-mesh">MeSH term</label>
            <input id="f-mesh" v-model.trim="form.mesh_term" placeholder="e.g. Lung Diseases" />
        </div>
        <div>
            <label for="f-keyword">Keyword</label>
            <input id="f-keyword" v-model.trim="form.keyword" />
        </div>
        <div>
            <label for="f-sponsor">Sponsor</label>
            <input id="f-sponsor" v-model.trim="form.sponsor" />
        </div>
        <div class="pair">
            <div>
                <label for="f-country">Country</label>
                <input id="f-country" v-model.trim="form.country" placeholder="Brazil" />
            </div>
            <div>
                <label for="f-city">City</label>
                <input id="f-city" v-model.trim="form.city" />
            </div>
        </div>

        <fieldset>
            <legend>Recruitment status</legend>
            <label v-for="status in mainStatuses" :key="status" class="check">
                <input type="checkbox" :checked="form.status?.includes(status)" @change="toggle('status', status)" />
                {{ STATUS_LABELS[status] }}
            </label>
        </fieldset>

        <fieldset>
            <legend>Phase</legend>
            <label v-for="(name, phase) in PHASE_LABELS" :key="phase" class="check">
                <input type="checkbox" :checked="form.phase?.includes(phase)" @change="toggle('phase', phase)" />
                {{ name }}
            </label>
        </fieldset>

        <fieldset>
            <legend>Study type</legend>
            <label v-for="(name, type) in STUDY_TYPE_LABELS" :key="type" class="check">
                <input type="checkbox" :checked="form.study_type?.includes(type)" @change="toggle('study_type', type)" />
                {{ name }}
            </label>
        </fieldset>

        <fieldset>
            <legend>Start date</legend>
            <div class="pair">
                <div>
                    <label for="f-start-from">From</label>
                    <input id="f-start-from" v-model="form.start_date_from" type="date" />
                </div>
                <div>
                    <label for="f-start-to">To</label>
                    <input id="f-start-to" v-model="form.start_date_to" type="date" />
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Near a place</legend>
            <div class="pair">
                <div>
                    <label for="f-lat">Latitude</label>
                    <input id="f-lat" v-model.number="form.latitude" type="number" step="any" min="-90" max="90" />
                </div>
                <div>
                    <label for="f-lng">Longitude</label>
                    <input id="f-lng" v-model.number="form.longitude" type="number" step="any" min="-180" max="180" />
                </div>
            </div>
            <label for="f-radius">Within (km)</label>
            <input id="f-radius" v-model.number="form.radius_km" type="number" min="1" max="2000" />
        </fieldset>

        <div class="actions">
            <button class="button" type="submit">{{ submitLabel ?? 'Show studies' }}</button>
            <button class="button secondary" type="button" @click="clear">Clear filters</button>
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
